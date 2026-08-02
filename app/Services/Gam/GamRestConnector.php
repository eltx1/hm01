<?php

namespace App\Services\Gam;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamConnectorInterface;
use App\Services\Gam\Data\GamResult;
use App\Services\Gam\Exceptions\GamTransportException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Version-stable Google Ad Manager REST v1 connector.
 *
 * This connector never embeds a dated API version. Unsupported methods remain
 * explicit so the hybrid capability router can select the audited fallback
 * before a request is sent.
 */
final class GamRestConnector implements GamConnectorInterface
{
    private const API_ROOT = 'https://admanager.googleapis.com/v1';

    public function __construct(
        private readonly GamConnection $gamConnection,
        private readonly GamOAuthTokenProvider $tokens,
        private readonly GamOperationExecutor $executor,
    ) {}

    public function connection(): GamConnection { return $this->gamConnection; }

    public function testConnection(array $options = []): GamResult { return $this->getCurrentNetwork($options); }

    public function getCurrentNetwork(array $options = []): GamResult
    {
        if (! $this->gamConnection->network_code) {
            return GamResult::failure('VALIDATION', 'NETWORK_CODE_REQUIRED', 'Select a GAM network code before testing this connection.');
        }

        return $this->read('getCurrentNetwork', 'networks', 'get', $this->resource('networks', $this->gamConnection->network_code), [], $options);
    }

    public function listAccessibleNetworks(array $options = []): GamResult
    {
        return $this->read('listAccessibleNetworks', 'networks', 'list', '/networks', [], $options);
    }

    public function getNetworkByCode(string $networkCode, array $options = []): GamResult
    {
        return $this->read('getNetworkByCode', 'networks', 'get', $this->resource('networks', $networkCode), [], $options);
    }

    public function createCompany(array $attributes, array $options = []): GamResult { return $this->unsupported('createCompany', 'companies.create'); }
    public function updateCompany(array $attributes, array $options = []): GamResult { return $this->unsupported('updateCompany', 'companies.patch'); }

    public function createAdUnit(array $attributes, array $options = []): GamResult
    {
        return $this->write('createAdUnit', 'adUnits', 'create', $this->collection('adUnits'), $attributes, $options);
    }

    public function updateAdUnit(array $attributes, array $options = []): GamResult
    {
        return $this->patchResource('updateAdUnit', 'adUnits', $attributes, $options);
    }

    public function createPlacement(array $attributes, array $options = []): GamResult
    {
        return $this->write('createPlacement', 'placements', 'create', $this->collection('placements'), $attributes, $options);
    }

    public function createCustomTargetingKey(array $attributes, array $options = []): GamResult
    {
        if (isset($attributes['name']) && ! isset($attributes['adTagName'])) $attributes['adTagName'] = $attributes['name'];
        unset($attributes['name']);

        return $this->write('createCustomTargetingKey', 'customTargetingKeys', 'create', $this->collection('customTargetingKeys'), $attributes, $options);
    }

    public function createCustomTargetingValue(array $attributes, array $options = []): GamResult
    {
        $key = $attributes['customTargetingKey'] ?? $attributes['customTargetingKeyId'] ?? null;
        if ($key !== null) $attributes['customTargetingKey'] = $this->relatedResource('customTargetingKeys', (string) $key);
        if (isset($attributes['name']) && ! isset($attributes['adTagName'])) $attributes['adTagName'] = $attributes['name'];
        unset($attributes['customTargetingKeyId'], $attributes['name']);

        return $this->write('createCustomTargetingValue', 'customTargetingValues', 'create', $this->collection('customTargetingValues'), $attributes, $options);
    }

    public function createOrder(array $attributes, array $options = []): GamResult
    {
        $attributes = $this->normalizeOrder($attributes, false);
        return $this->batchWrite('createOrder', 'orders', 'batchCreate', 'batchCreate', 'order', $attributes, $options);
    }

    public function updateOrder(array $attributes, array $options = []): GamResult
    {
        $attributes = $this->normalizeOrder($attributes, true);
        return $this->batchWrite('updateOrder', 'orders', 'batchUpdate', 'batchUpdate', 'order', $attributes, $options, true);
    }

    public function createLineItem(array $attributes, array $options = []): GamResult { return $this->unsupported('createLineItem', 'lineItems.create'); }
    public function updateLineItem(array $attributes, array $options = []): GamResult { return $this->unsupported('updateLineItem', 'lineItems.patch'); }
    public function createCreative(array $attributes, array $options = []): GamResult { return $this->unsupported('createCreative', 'creatives.create'); }
    public function associateCreative(array $attributes, array $options = []): GamResult { return $this->unsupported('associateCreative', 'lineItemCreativeAssociations.create'); }
    public function pauseLineItem(array $filterStatement, array $options = []): GamResult { return $this->unsupported('pauseLineItem', 'lineItems.pause'); }
    public function activateLineItem(array $filterStatement, array $options = []): GamResult { return $this->unsupported('activateLineItem', 'lineItems.activate'); }
    public function resumeLineItem(array $filterStatement, array $options = []): GamResult { return $this->unsupported('resumeLineItem', 'lineItems.resume'); }

    public function archiveObject(array $attributes, array $options = []): GamResult
    {
        $resource = (string) ($attributes['resource'] ?? '');
        $names = array_values(array_filter(array_map('strval', (array) ($attributes['names'] ?? []))));
        if (! in_array($resource, ['adUnits', 'placements', 'orders'], true) || $names === []) {
            throw new InvalidArgumentException('archiveObject requires a supported resource and one or more canonical resource names.');
        }

        return $this->write('archiveObject', $resource, 'batchArchive', $this->collection($resource).':batchArchive', ['names' => $names], $options);
    }

    public function runReport(array $reportQuery, array $options = []): GamResult
    {
        $name = (string) ($reportQuery['name'] ?? '');
        if ($name !== '') {
            return $this->write('runReport', 'reports', 'run', '/'.ltrim($this->canonicalName($name, 'reports'), '/').':run', [], array_merge($options, ['write' => false, 'dry_run' => false]));
        }

        $create = $this->write('createReport', 'reports', 'create', $this->collection('reports'), $reportQuery, array_merge($options, ['write' => true]));
        if (! $create->success || $create->dryRun) return $create;
        $createdName = (string) data_get($create->data, 'name', '');
        if ($createdName === '') return GamResult::failure('UPSTREAM', 'REPORT_NAME_MISSING', 'GAM created a report without returning its resource name.', $create->operationId);

        return $this->write('runReport', 'reports', 'run', '/'.ltrim($createdName, '/').':run', [], array_merge($options, ['write' => false, 'dry_run' => false]));
    }

    public function getObjectByRemoteId(string $service, string $remoteId, array $options = []): GamResult
    {
        $resource = $this->resourceForService($service);
        if ($resource === null) return $this->unsupported('getObjectByRemoteId', $service.'.get');

        return $this->read('getObjectByRemoteId', $resource, 'get', $this->resource($resource, $remoteId), [], $options);
    }

    private function patchResource(string $operation, string $resource, array $attributes, array $options): GamResult
    {
        $name = $this->canonicalName((string) ($attributes['name'] ?? $attributes['id'] ?? ''), $resource);
        $attributes['name'] = $name;
        unset($attributes['id']);
        $query = [];
        if ($mask = $options['update_mask'] ?? $this->updateMask($attributes)) $query['updateMask'] = $mask;

        return $this->request($operation, $resource, 'patch', 'PATCH', '/'.$name, $attributes, $query, array_merge(['write' => true], $options));
    }

    private function batchWrite(string $operation, string $resource, string $method, string $action, string $itemKey, array $attributes, array $options, bool $update = false): GamResult
    {
        $request = [$itemKey => $attributes];
        if ($update) {
            $request['updateMask'] = $options['update_mask'] ?? $this->updateMask($attributes);
        } else {
            // GAM follows the Google AIP batch envelope: each create request
            // repeats the same parent used by the outer batch endpoint.
            $request['parent'] = 'networks/'.$this->networkCode();
        }

        return $this->write($operation, $resource, $method, $this->collection($resource).':'.$action, ['requests' => [$request]], $options);
    }

    private function read(string $operation, string $service, string $method, string $path, array $query, array $options): GamResult
    {
        return $this->request($operation, $service, $method, 'GET', $path, [], $query, array_merge($options, ['write' => false, 'dry_run' => false]));
    }

    private function write(string $operation, string $service, string $method, string $path, array $payload, array $options): GamResult
    {
        return $this->request($operation, $service, $method, 'POST', $path, $payload, [], array_merge(['write' => true], $options));
    }

    private function request(string $operation, string $service, string $method, string $verb, string $path, array $payload, array $query, array $options): GamResult
    {
        $auditPayload = ['httpMethod' => $verb, 'path' => $path, 'query' => $query, 'body' => $payload];
        return $this->executor->execute(
            $this->gamConnection,
            $operation,
            'REST:'.$service,
            $method,
            $auditPayload,
            fn () => $this->send($verb, $path, $payload, $query),
            $options,
        );
    }

    private function send(string $verb, string $path, array $payload, array $query): array
    {
        try {
            $request = Http::withToken($this->tokens->accessToken($this->gamConnection))
                ->acceptJson()->asJson()->timeout((int) config('gam.rest.timeout', 30));
            $url = rtrim((string) config('gam.rest.base_url', self::API_ROOT), '/').'/'.ltrim($path, '/');
            $response = match ($verb) {
                'GET' => $request->get($url, $query),
                'PATCH' => $request->patch($url.($query ? '?'.http_build_query($query) : ''), $payload),
                default => $request->post($url.($query ? '?'.http_build_query($query) : ''), $payload),
            };
        } catch (Throwable $exception) {
            throw new GamTransportException('GAM REST network request failed.', 'REST_NETWORK_ERROR', true, $verb === 'GET', previous: $exception);
        }

        return $this->decode($response);
    }

    private function decode(Response $response): array
    {
        $payload = $response->json();
        if (! $response->successful()) {
            $status = $response->status();
            $code = (string) data_get($payload, 'error.status', 'HTTP_'.$status);
            $message = (string) data_get($payload, 'error.message', 'Google Ad Manager REST request failed.');
            $retryable = $status === 429 || $status >= 500;
            throw new GamTransportException($message, $code, $retryable, $retryable, $response->header('x-request-id'));
        }

        if (! is_array($payload)) return [];

        $name = data_get($payload, 'name');
        if (! is_string($name) || $name === '') {
            foreach (['orders', 'adUnits', 'placements', 'customTargetingKeys', 'customTargetingValues'] as $collection) {
                $candidate = data_get($payload, $collection.'.0.name');
                if (is_string($candidate) && $candidate !== '') { $name = $candidate; break; }
            }
        }
        if (is_string($name) && $name !== '') {
            $id = basename($name);
            $payload['id'] ??= $id;
            $payload['resourceName'] ??= $name;
            $payload['rval'] ??= [['id' => $id, 'name' => $name]];
        }

        return $payload;
    }

    private function unsupported(string $operation, string $capability): GamResult
    {
        return GamResult::failure('CONFIGURATION', 'REST_CAPABILITY_UNAVAILABLE', "Google Ad Manager REST v1 does not currently publish {$capability}; {$operation} was not sent.");
    }

    private function collection(string $resource): string { return '/networks/'.$this->networkCode().'/'.$resource; }
    private function resource(string $resource, string $id): string { return '/'.$this->canonicalName($id, $resource); }
    private function canonicalName(string $value, string $resource): string
    {
        $value = trim($value, '/');
        if (str_starts_with($value, 'networks/')) return $value;
        if ($value === '') throw new InvalidArgumentException("{$resource} resource name or ID is required.");
        if ($resource === 'networks') return 'networks/'.$value;
        return 'networks/'.$this->networkCode().'/'.$resource.'/'.$value;
    }
    private function networkCode(): string
    {
        $code = trim((string) $this->gamConnection->network_code);
        if ($code === '') throw new InvalidArgumentException('A GAM network code is required for this REST operation.');
        return $code;
    }
    private function updateMask(array $attributes): string
    {
        return collect(array_keys($attributes))->reject(fn ($key) => in_array($key, ['name', 'id'], true))->implode(',');
    }
    private function normalizeOrder(array $attributes, bool $update): array
    {
        $displayName = $attributes['displayName'] ?? $attributes['name'] ?? null;
        $remoteName = $attributes['id'] ?? ($update && str_starts_with((string) ($attributes['name'] ?? ''), 'networks/') ? $attributes['name'] : null);
        $advertiser = $attributes['advertiser'] ?? $attributes['advertiserId'] ?? data_get($this->gamConnection->configuration, 'advertiser_company_id');
        $trafficker = $attributes['trafficker'] ?? $attributes['traffickerId'] ?? data_get($this->gamConnection->configuration, 'trafficker_user_id');
        unset($attributes['id'], $attributes['advertiserId'], $attributes['traffickerId'], $attributes['status']);
        if ($displayName !== null) $attributes['displayName'] = $displayName;
        if ($advertiser !== null) $attributes['advertiser'] = $this->relatedResource('companies', (string) $advertiser);
        if ($trafficker !== null) $attributes['trafficker'] = $this->relatedResource('users', (string) $trafficker);
        if ($update && $remoteName !== null) $attributes['name'] = $this->canonicalName((string) $remoteName, 'orders');
        else unset($attributes['name']);

        return $attributes;
    }
    private function relatedResource(string $resource, string $value): string
    {
        return str_starts_with($value, 'networks/') ? $value : 'networks/'.$this->networkCode().'/'.$resource.'/'.$value;
    }
    private function resourceForService(string $service): ?string
    {
        return match (strtolower(str_replace('Service', '', $service))) {
            'network', 'networks' => 'networks', 'inventory', 'adunit', 'adunits' => 'adUnits',
            'placement', 'placements' => 'placements', 'company', 'companies' => 'companies',
            'order', 'orders' => 'orders', 'lineitem', 'lineitems' => 'lineItems',
            'customtargeting', 'customtargetingkey', 'customtargetingkeys' => 'customTargetingKeys',
            'customtargetingvalue', 'customtargetingvalues' => 'customTargetingValues',
            'report', 'reports' => 'reports', default => null,
        };
    }
}
