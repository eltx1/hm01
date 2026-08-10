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
