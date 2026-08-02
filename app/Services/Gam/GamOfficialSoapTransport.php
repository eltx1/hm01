<?php

namespace App\Services\Gam;

use App\Enums\GamCredentialType;
use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\Exceptions\GamTransportException;
use Google\AdsApi\AdManager\AdManagerSessionBuilder;
use Google\AdsApi\Common\OAuth2TokenBuilder;
use ReflectionClass;
use SplObjectStorage;
use Throwable;

final class GamOfficialSoapTransport implements GamSoapTransportInterface
{
    public function __construct(
        private readonly GamSecretResolver $secrets,
        private readonly GamSoapVersionResolver $versions,
        private readonly GamSoapPayloadHydrator $hydrator,
    ) {}

    public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
    {
        if (! extension_loaded('soap')) {
            throw new GamTransportException('The PHP SOAP extension is required for GAM fallback writes.', 'SOAP_EXTENSION_MISSING');
        }
        if (! class_exists(AdManagerSessionBuilder::class)) {
            throw new GamTransportException('The official Google Ads PHP library is not installed.', 'SOAP_LIBRARY_MISSING');
        }
        if (! $connection->is_enabled) {
            throw new GamTransportException('The selected GAM connection is disabled.', 'CONNECTION_DISABLED');
        }

        try {
            $version = $this->versions->resolve();
            $namespace = $this->versions->namespaceFor($version);
            $factoryClass = $namespace.'\\ServiceFactory';
            $factoryMethod = 'create'.$service;
            $factory = new $factoryClass;
            if (! method_exists($factory, $factoryMethod)) {
                throw new GamTransportException("GAM SOAP {$version} does not expose {$service}.", 'SOAP_SERVICE_UNAVAILABLE');
            }

            $session = (new AdManagerSessionBuilder)
                ->withNetworkCode((string) $connection->network_code)
                ->withApplicationName((string) ($connection->application_name ?: config('gam.application_name')))
                ->withOAuth2Credential($this->credential($connection))
                ->build();
            $client = $factory->{$factoryMethod}($session);
            $arguments = $this->hydrator->arguments($client, $method, $payload, $namespace);
            $response = $client->{$method}(...$arguments);
            $normalized = $this->normalize($response, new SplObjectStorage);

            if (is_array($normalized) && array_is_list($normalized) && count($normalized) === 1 && is_array($normalized[0])) {
                $normalized = $normalized[0];
            }

            if ($method === 'createLineItemCreativeAssociations' && is_array($normalized)
                && isset($normalized['lineItemId'], $normalized['creativeId'])) {
                $normalized['id'] = $normalized['lineItemId'].':'.$normalized['creativeId'];
            }

            return is_array($normalized) ? $normalized : ['value' => $normalized];
        } catch (GamTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $message = trim($exception->getMessage()) ?: 'Google Ad Manager SOAP fallback failed.';
            $retryable = preg_match('/timeout|temporar|rate|quota|server|unavailable/i', $message) === 1;
            $code = method_exists($exception, 'getErrors') ? 'GAM_API_ERROR' : class_basename($exception);

            throw new GamTransportException($message, $code, $retryable, false, previous: $exception);
        }
    }

    private function credential(GamConnection $connection): object
    {
        $credential = $connection->credential()->firstOrFail();
        $builder = new OAuth2TokenBuilder;
        if ($credential->credential_type === GamCredentialType::ServiceAccount) {
            return $builder
                ->withJsonKeyFilePath($this->secrets->resolveFile($credential->reference))
                ->withScopes(implode(' ', $credential->scopes ?: [config('gam.oauth.scope')]))
                ->build();
        }

        $material = $this->secrets->readJson($credential->reference);

        return $builder
            ->withClientId($material['client_id'] ?? null)
            ->withClientSecret($material['client_secret'] ?? null)
            ->withRefreshToken($material['refresh_token'] ?? null)
            ->build();
    }

    private function normalize(mixed $value, SplObjectStorage $seen, int $depth = 0): mixed
    {
        if ($depth > 20 || is_scalar($value) || $value === null) return $value;
        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->normalize($item, $seen, $depth + 1), $value);
        }
        if (! is_object($value)) return (string) $value;
        if ($seen->contains($value)) return null;
        $seen->attach($value);
        $result = [];
        for ($class = new ReflectionClass($value); $class; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || array_key_exists($property->getName(), $result)) continue;
                $result[$property->getName()] = $this->normalize($property->getValue($value), $seen, $depth + 1);
            }
        }

        return array_filter($result, fn (mixed $item) => $item !== null);
    }
}
