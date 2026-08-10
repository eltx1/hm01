<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditRecorder
{
    public function record(
        string $event,
        ?string $organizationId = null,
        ?Model $actor = null,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();
        $metadata = array_merge([
            'method' => $request->method(),
            'route' => $request->route()?->getName(),
        ], $metadata);

        return AuditLog::query()->create([
            'organization_id' => $organizationId,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'request_id' => $request->header('X-Request-ID'),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
            'old_values' => $this->redact($oldValues) ?: null,
            'new_values' => $this->redact($newValues) ?: null,
            'metadata' => $this->redact($metadata) ?: null,
        ]);
    }

    /** @param array<string|int, mixed> $values */
    private function redact(array $values): array
    {
        $sensitive = [
            'password', 'password_confirmation', 'token', 'token_hash', 'secret',
            'api_key', 'api_token', 'access_token', 'refresh_token', 'client_secret',
            'authorization', 'credential', 'credentials', 'service_account_json', 'private_key',
            'two_factor_secret', 'two_factor_recovery_codes', 'payment_details',
            'account_reference', 'routing_reference', 'tax_identifier',
            'bank_account', 'bank_account_number', 'iban', 'swift', 'bic',
            'paypal_email', 'wise_account', 'contract_file_path',
            'publisher_invoice_path',
        ];

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
