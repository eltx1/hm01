<?php

namespace App\Services\Settings;

use App\Services\SupplyChain\DomainNormalizer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TypedSettingsRegistry
{
    public function __construct(private readonly DomainNormalizer $domains) {}

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        $definitions = [
            new SettingDefinition(
                'general.company_name', 'GENERAL', 'Public company name', 'string', 'app.name',
                ['required', 'string', 'min:2', 'max:120'], [],
                'Public product/company name shown by Horus Media.', 'PUBLIC'
            ),
            new SettingDefinition(
                'advertiser_campaigns.enabled', 'ADVERTISER CAMPAIGNS', 'Advertiser Campaigns Enabled', 'boolean', 'campaigns.advertiser_campaigns_enabled',
                ['required', 'boolean'], [],
                'Controls whether normal Advertiser users may create or submit Direct Advertiser campaigns. Delivery still requires an eligible GAM-backed capability.',
                'SAFE', true, true,
                'Turning this off blocks new Advertiser campaign creation/submission and new or resumed delivery while preserving existing campaigns, history, finance, and emergency pause/complete actions.'
            ),
            new SettingDefinition(
                'traffic_gate.enabled', 'CLIENT TRAFFIC GATE', 'Client Traffic Gate Enabled', 'boolean', 'traffic_gate.enabled',
                ['required', 'boolean'], [],
                'Requests the optional client-only soft traffic filter globally. Incomplete or invalid configuration remains inactive.',
                'SAFE', true, true,
                'Changing this setting republishes active website static configuration. It does not verify humans, clear IVT, or contact Laravel per visitor.'
            ),
            new SettingDefinition(
                'traffic_gate.site_key', 'CLIENT TRAFFIC GATE', 'Cloudflare Turnstile public site key', 'string', 'traffic_gate.site_key',
                ['nullable', 'string', 'min:3', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'], [],
                'Public client-side Turnstile site key for the Client Traffic Gate. This is not a secret and no Turnstile secret exists in this feature.',
                'PUBLIC', true, true,
                'Replacing the public site key republishes active website static configuration through normal batching.'
            ),
            new SettingDefinition(
                'traffic_gate.policy', 'CLIENT TRAFFIC GATE', 'Client Traffic Gate policy', 'enum', 'traffic_gate.policy',
                ['required', 'string'], ['STRICT', 'BALANCED', 'PERMISSIVE'],
                'Constrained client behavior preset. Task 48 publishes the contract only; browser behavior is not implemented here.',
                'SAFE', true, true,
                'Policy changes alter future client gate behavior once a later runtime task activates the gate.'
            ),
            new SettingDefinition(
                'traffic_gate.initial_wait_ms', 'CLIENT TRAFFIC GATE', 'Initial wait (ms)', 'integer', 'traffic_gate.initial_wait_ms',
                ['required', 'integer', 'min:500', 'max:5000'], [],
                'Bounded initial client wait. Allowed range: 500–5000 ms.',
                'SAFE', true, true,
                'Timing changes republish active website static configuration and remain bounded to prevent unbounded blocking.'
            ),
            new SettingDefinition(
                'traffic_gate.max_wait_ms', 'CLIENT TRAFFIC GATE', 'Maximum wait (ms)', 'integer', 'traffic_gate.max_wait_ms',
                ['required', 'integer', 'min:2000', 'max:15000'], [],
                'Bounded maximum client wait. Allowed range: 2000–15000 ms and must be at least the initial wait.',
                'SAFE', true, true,
                'Timing changes republish active website static configuration and remain bounded to prevent unbounded blocking.'
            ),
            new SettingDefinition(
                'traffic_gate.retry_interval_ms', 'CLIENT TRAFFIC GATE', 'Retry interval (ms)', 'integer', 'traffic_gate.retry_interval_ms',
                ['required', 'integer', 'min:500', 'max:10000'], [],
                'Bounded client retry interval. Allowed range: 500–10000 ms.',
                'SAFE', true, true,
                'Timing changes republish active website static configuration and remain bounded to prevent excessive retry behavior.'
            ),
            new SettingDefinition(
                'traffic_gate.activity_recovery_enabled', 'CLIENT TRAFFIC GATE', 'Activity recovery enabled', 'boolean', 'traffic_gate.activity_recovery_enabled',
                ['required', 'boolean'], [],
                'Publishes whether the BALANCED policy may later use trusted browser activity recovery. No recovery logic is executed by Task 48.',
                'SAFE', true, true,
                'Changing this setting republishes active website static configuration through normal batching.'
            ),
            new SettingDefinition(
                'supply_chain.manager_domain', 'SUPPLY CHAIN', 'Manager domain', 'domain', 'supply-chain.manager_domain',
                ['required', 'string', 'max:253'], [],
                'Advertising-system domain used by MANAGERDOMAIN and the Horus schain node.', 'PUBLIC', true, true,
                'Changing this value affects generated Ads.txt, sellers.json/schain identity and future static publications.'
            ),
            new SettingDefinition(
                'supply_chain.contact_email', 'SUPPLY CHAIN', 'Public sellers.json contact email', 'email', 'supply-chain.contact_email',
                ['required', 'email:rfc', 'max:254'], [],
                'Public contact email emitted in sellers.json.', 'PUBLIC'
            ),
            new SettingDefinition(
                'supply_chain.contact_address', 'SUPPLY CHAIN', 'Public sellers.json contact address', 'string', 'supply-chain.contact_address',
                ['required', 'string', 'max:500'], [],
                'Public business/contact address emitted in sellers.json.', 'PUBLIC'
            ),
            new SettingDefinition(
                'supply_chain.tag_id', 'SUPPLY CHAIN', 'TAG-ID', 'string', 'supply-chain.tag_id',
                ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'], [],
                'Optional public TAG certification identifier emitted in sellers.json.', 'PUBLIC'
            ),
            new SettingDefinition(
                'supply_chain.ads_txt_fresh_for_days', 'SUPPLY CHAIN', 'Ads.txt freshness window', 'integer', 'ads-txt.fresh_for_days',
                ['required', 'integer', 'min:1', 'max:90'], [],
                'Number of days before stored Ads.txt verification evidence is considered stale.', 'SAFE'
            ),
            new SettingDefinition(
                'reporting.discrepancy_warning_bp', 'REPORTING', 'Reconciliation warning threshold', 'integer', 'reporting.discrepancy_warning_bp',
                ['required', 'integer', 'min:0', 'max:10000'], [],
                'Difference threshold in basis points used for reporting reconciliation warnings.', 'SAFE'
            ),
            new SettingDefinition(
                'reporting.retry_delay_minutes', 'REPORTING', 'Import retry delay', 'integer', 'reporting.retry_delay_minutes',
                ['required', 'integer', 'min:1', 'max:1440'], [],
                'Safe retry delay for failed report imports.', 'SAFE'
            ),
            new SettingDefinition(
                'reporting.hourly_lookback_hours', 'REPORTING', 'Hourly import lookback', 'integer', 'reporting.hourly_lookback_hours',
                ['required', 'integer', 'min:1', 'max:168'], [],
                'Number of hours included in routine hourly report backfill windows.', 'SAFE'
            ),
            new SettingDefinition(
                'reporting.daily_lookback_days', 'REPORTING', 'Daily import lookback', 'integer', 'reporting.daily_lookback_days',
                ['required', 'integer', 'min:1', 'max:31'], [],
                'Number of days included in routine daily report backfill windows.', 'SAFE'
            ),
        ];

        return collect($definitions)->keyBy(fn (SettingDefinition $definition) => $definition->key)->all();
    }

    public function get(string $key): SettingDefinition
    {
        $definition = $this->all()[$key] ?? null;
        if (! $definition) {
            throw ValidationException::withMessages(['key' => 'Unknown or non-editable setting key.']);
        }

        return $definition;
    }

    public function normalize(string $key, mixed $value): mixed
    {
        return $this->normalizeDefinition($this->get($key), $value);
    }

    public function normalizeDefinition(SettingDefinition $definition, mixed $value): mixed
    {
        if (! $definition->runtimeEditable) {
            throw ValidationException::withMessages(['key' => 'This setting is not runtime editable.']);
        }

        $rules = $definition->rules;
        if ($definition->type === 'enum') {
            $rules[] = Rule::in($definition->allowedValues);
        }
        Validator::make(['value' => $value], ['value' => $rules])->validate();

        if (($value === null || $value === '') && in_array('nullable', $definition->rules, true)) {
            return null;
        }

        return match ($definition->type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw ValidationException::withMessages(['value' => 'The value must be true or false.']),
            'domain' => $this->normalizeDomain((string) $value),
            'email' => strtolower(trim((string) $value)),
            'enum' => (string) $value,
            default => trim((string) $value),
        };
    }

    private function normalizeDomain(string $value): string
    {
        try {
            return $this->domains->normalize($value);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['value' => 'The value must be a valid normalized domain without a path, port, or credentials.']);
        }
    }
}
