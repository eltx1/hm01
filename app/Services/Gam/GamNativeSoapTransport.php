<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\Exceptions\GamTransportException;
use SoapClient;
use SoapFault;
use SoapHeader;
use SoapVar;
use Throwable;

final class GamNativeSoapTransport implements GamSoapTransportInterface
{
    public function __construct(private readonly GamOAuthTokenProvider $tokens)
    {
    }

    public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
    {
        if (! class_exists(SoapClient::class)) {
            throw new GamTransportException('The PHP SOAP extension is required for live Google Ad Manager calls.', 'SOAP_EXTENSION_MISSING');
        }

        if (! $connection->is_enabled) {
            throw new GamTransportException('The selected GAM connection is disabled.', 'CONNECTION_DISABLED');
        }

        $version = config('gam.api_version');
        $namespace = "https://www.google.com/apis/ads/publisher/{$version}";
        $baseUrl = rtrim((string) config('gam.soap.base_url'), '/');
        $wsdl = "{$baseUrl}/{$version}/{$service}?wsdl";
        $token = $this->tokens->accessToken($connection->loadMissing('credential'));

        try {
            $context = stream_context_create([
                'http' => [
                    'header' => "Authorization: Bearer {$token}\r\n",
                    'timeout' => (int) config('gam.soap.timeout', 30),
                ],
            ]);

            $client = new SoapClient($wsdl, [
                'exceptions' => true,
                'trace' => false,
                'cache_wsdl' => config('gam.soap.wsdl_cache', true) ? WSDL_CACHE_BOTH : WSDL_CACHE_NONE,
                'connection_timeout' => (int) config('gam.soap.connect_timeout', 10),
                'stream_context' => $context,
                'keep_alive' => true,
                'user_agent' => $connection->application_name,
            ]);

            $client->__setSoapHeaders(new SoapHeader($namespace, 'RequestHeader', [
                'networkCode' => $connection->network_code,
                'applicationName' => $connection->application_name,
            ]));

            $arguments = $payload === [] ? [] : [$this->soapValue($payload, $namespace)];
            $response = $client->__soapCall($method, $arguments);

            return $this->normalize($response);
        } catch (SoapFault $exception) {
            $message = trim($exception->getMessage());
            $retryable = preg_match('/timeout|temporar|rate|quota|server|unavailable/i', $message) === 1;

            throw new GamTransportException($message ?: 'Google Ad Manager SOAP request failed.', (string) $exception->faultcode, $retryable, false, previous: $exception);
        } catch (GamTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new GamTransportException('Google Ad Manager transport failed before a response was received.', 'TRANSPORT_FAILURE', true, true, previous: $exception);
        }
    }

    private function soapValue(mixed $value, string $namespace): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $type = $value['__type'] ?? null;
        unset($value['__type']);

        $converted = [];
        foreach ($value as $key => $item) {
            $converted[$key] = $this->soapValue($item, $namespace);
        }

        return $type ? new SoapVar((object) $converted, SOAP_ENC_OBJECT, (string) $type, $namespace) : $converted;
    }

    private function normalize(mixed $value): array
    {
        $normalized = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($normalized)) {
            return ['value' => $normalized];
        }

        return $normalized;
    }
}
