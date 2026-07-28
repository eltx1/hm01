<?php

namespace App\Services\Gam;

use App\Enums\GamOperationStatus;
use App\Models\GamApiOperation;
use App\Models\GamConnection;
use App\Models\GamError;
use App\Models\GamRemoteObject;
use App\Models\GamSyncLog;
use App\Services\Gam\Data\GamResult;
use Throwable;

final class GamOperationExecutor
{
    public function __construct(
        private readonly GamPayloadSanitizer $sanitizer,
        private readonly GamExceptionClassifier $classifier,
    ) {
    }

    public function execute(
        GamConnection $connection,
        string $operationName,
        string $service,
        string $method,
        array $payload,
        callable $callback,
        array $options = [],
    ): GamResult {
        $isWrite = (bool) ($options['write'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? $connection->dry_run_default ?? config('gam.dry_run_default', true));
        $idempotencyKey = $options['idempotency_key'] ?? ($isWrite ? $this->idempotencyKey($connection, $operationName, $payload, $options) : null);

        if ($idempotencyKey) {
            $existing = GamApiOperation::withoutGlobalScopes()
                ->where('gam_connection_id', $connection->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', GamOperationStatus::Succeeded)
                ->first();

            if ($existing) {
                return GamResult::duplicate($existing->response_payload ?? [], $existing->id);
            }
        }

        $apiOperation = GamApiOperation::withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'gam_connection_id' => $connection->id,
            'gam_sync_run_id' => $options['sync_run_id'] ?? null,
            'operation' => $operationName,
            'service' => $service,
            'method' => $method,
            'idempotency_key' => $idempotencyKey,
            'dry_run' => $dryRun,
            'status' => GamOperationStatus::Pending,
            'request_payload' => $this->sanitizer->sanitize($payload),
            'started_at' => now(),
        ]);

        if ($dryRun) {
            $planned = [
                'planned' => true,
                'service' => $service,
                'method' => $method,
                'payload' => $this->sanitizer->sanitize($payload),
            ];
            $apiOperation->update([
                'status' => GamOperationStatus::DryRun,
                'response_payload' => $planned,
                'attempts' => 0,
                'completed_at' => now(),
            ]);
            $this->syncLog($connection, $options, 'INFO', 'gam.operation.dry_run', "Planned {$operationName} without an external write.", ['operation_id' => $apiOperation->id]);

            return GamResult::dryRun($planned, $apiOperation->id);
        }

        $maxAttempts = max(1, (int) config($isWrite ? 'gam.retry.write_attempts' : 'gam.retry.read_attempts', $isWrite ? 2 : 3));
        $attempt = 0;

        do {
            $attempt++;
            try {
                $response = $callback();
                $response = is_array($response) ? $response : ['value' => $response];
                $sanitizedResponse = $this->sanitizer->sanitize($response);

                $apiOperation->update([
                    'status' => GamOperationStatus::Succeeded,
                    'response_payload' => $sanitizedResponse,
                    'remote_request_id' => data_get($response, 'requestId') ?? data_get($response, 'request_id'),
                    'attempts' => $attempt,
                    'completed_at' => now(),
                    'error_category' => null,
                    'error_code' => null,
                    'error_message' => null,
                ]);

                $this->storeRemoteMapping($connection, $response, $payload, $idempotencyKey, $options);
                $this->syncLog($connection, $options, 'INFO', 'gam.operation.succeeded', "Completed {$operationName}.", ['operation_id' => $apiOperation->id, 'attempts' => $attempt]);

                return GamResult::success($sanitizedResponse, $apiOperation->id);
            } catch (Throwable $exception) {
                $classification = $this->classifier->classify($exception);
                $mayRetry = $classification['retryable']
                    && (! $isWrite || $classification['safe_to_retry'])
                    && $attempt < $maxAttempts;

                if ($mayRetry) {
                    usleep(((int) config('gam.retry.base_delay_ms', 250)) * $attempt * 1000);
                    continue;
                }

                $apiOperation->update([
                    'status' => GamOperationStatus::Failed,
                    'attempts' => $attempt,
                    'error_category' => $classification['category']->value,
                    'error_code' => $classification['code'],
                    'error_message' => mb_substr($exception->getMessage(), 0, 10000),
                    'completed_at' => now(),
                ]);

                GamError::withoutGlobalScopes()->create([
                    'organization_id' => $connection->organization_id,
                    'gam_connection_id' => $connection->id,
                    'gam_api_operation_id' => $apiOperation->id,
                    'gam_sync_run_id' => $options['sync_run_id'] ?? null,
                    'category' => $classification['category'],
                    'code' => $classification['code'],
                    'message' => mb_substr($exception->getMessage(), 0, 10000),
                    'retryable' => $classification['retryable'],
                    'context' => $this->sanitizer->sanitize(['service' => $service, 'method' => $method, 'attempts' => $attempt]),
                    'occurred_at' => now(),
                ]);

                $this->syncLog($connection, $options, 'ERROR', 'gam.operation.failed', "Failed {$operationName}.", [
                    'operation_id' => $apiOperation->id,
                    'category' => $classification['category']->value,
                    'code' => $classification['code'],
                ]);

                return GamResult::failure(
                    $classification['category']->value,
                    $classification['code'],
                    $exception->getMessage(),
                    $apiOperation->id,
                );
            }
        } while ($attempt < $maxAttempts);

        return GamResult::failure('UNKNOWN', null, 'The GAM operation ended without a result.', $apiOperation->id);
    }

    private function idempotencyKey(GamConnection $connection, string $operation, array $payload, array $options): string
    {
        $scope = [
            'connection' => $connection->id,
            'operation' => $operation,
            'local_type' => $options['local_type'] ?? null,
            'local_id' => $options['local_id'] ?? null,
            'payload' => $payload,
        ];

        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    private function storeRemoteMapping(GamConnection $connection, array $response, array $payload, ?string $idempotencyKey, array $options): void
    {
        if (empty($options['local_type']) || empty($options['local_id']) || empty($options['remote_type'])) {
            return;
        }

        $remoteId = data_get($response, $options['remote_id_path'] ?? 'id');
        if (! is_scalar($remoteId) || (string) $remoteId === '') {
            return;
        }

        GamRemoteObject::withoutGlobalScopes()->updateOrCreate(
            [
                'gam_connection_id' => $connection->id,
                'local_object_type' => (string) $options['local_type'],
                'local_object_id' => (string) $options['local_id'],
                'remote_object_type' => (string) $options['remote_type'],
            ],
            [
                'organization_id' => $connection->organization_id,
                'remote_object_id' => (string) $remoteId,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'remote_status' => data_get($response, 'status'),
                'metadata' => $this->sanitizer->sanitize($options['mapping_metadata'] ?? []),
                'synced_at' => now(),
            ],
        );
    }

    private function syncLog(GamConnection $connection, array $options, string $level, string $event, string $message, array $context): void
    {
        if (empty($options['sync_run_id'])) {
            return;
        }

        GamSyncLog::withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'gam_connection_id' => $connection->id,
            'gam_sync_run_id' => $options['sync_run_id'],
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $this->sanitizer->sanitize($context),
            'created_at' => now(),
        ]);
    }
}
