<?php

namespace App\Services\TrafficGate;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TrafficGateSettingsValidator
{
    public const KEYS = [
        'traffic_gate.enabled',
        'traffic_gate.site_key',
        'traffic_gate.policy',
        'traffic_gate.initial_wait_ms',
        'traffic_gate.max_wait_ms',
        'traffic_gate.retry_interval_ms',
        'traffic_gate.activity_recovery_enabled',
    ];

    /** @param array<string, mixed> $values */
    public function validate(array $values): void
    {
        $input = [
            'enabled' => $values['traffic_gate.enabled'] ?? null,
            'site_key' => $values['traffic_gate.site_key'] ?? null,
            'policy' => $values['traffic_gate.policy'] ?? null,
            'initial_wait_ms' => $values['traffic_gate.initial_wait_ms'] ?? null,
            'max_wait_ms' => $values['traffic_gate.max_wait_ms'] ?? null,
            'retry_interval_ms' => $values['traffic_gate.retry_interval_ms'] ?? null,
            'activity_recovery_enabled' => $values['traffic_gate.activity_recovery_enabled'] ?? null,
        ];

        Validator::make($input, [
            'enabled' => ['required', 'boolean'],
            'site_key' => ['nullable', 'string', 'min:3', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'policy' => ['required', Rule::in(['STRICT', 'BALANCED', 'PERMISSIVE'])],
            'initial_wait_ms' => ['required', 'integer', 'min:500', 'max:5000'],
            'max_wait_ms' => ['required', 'integer', 'min:2000', 'max:15000'],
            'retry_interval_ms' => ['required', 'integer', 'min:500', 'max:10000'],
            'activity_recovery_enabled' => ['required', 'boolean'],
        ])->validate();

        if ((int) $input['max_wait_ms'] < (int) $input['initial_wait_ms']) {
            throw ValidationException::withMessages([
                'value' => 'Traffic Gate max wait must be greater than or equal to the initial wait.',
            ]);
        }
    }
}
