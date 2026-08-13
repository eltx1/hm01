<?php

namespace Database\Seeders;

use App\Enums\ReportSourceCode;
use App\Models\ReportSource;
use App\Models\RevenueRule;
use App\Services\Reporting\RevenueRuleService;
use Illuminate\Database\Seeder;

class ReportingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('reporting.sources', []) as $code => $definition) {
            ReportSource::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'is_primary' => (bool) ($definition['primary'] ?? false),
                    'is_enabled' => true,
                    'capabilities' => array_values(array_unique(array_merge(
                        $definition['capabilities'] ?? [],
                        collect($definition['finalized_methods'] ?? [])->map(fn (string $method): string => 'FINALIZED_'.$method)->all(),
                    ))),
                    'metadata' => ['seeded' => true],
                ],
            );
        }

        if (! RevenueRule::withoutGlobalScopes()->where('scope_type', 'GLOBAL')->whereNull('scope_id')->exists()) {
            app(RevenueRuleService::class)->createRule([
                'name' => 'Default global revenue share',
                'scope_type' => 'GLOBAL',
                'scope_id' => null,
                'effective_from' => now()->startOfMonth()->toDateString(),
                'publisher_share_bp' => (int) config('reporting.default_publisher_share_bp', 7000),
                'horus_share_bp' => (int) config('reporting.default_horus_share_bp', 3000),
                'mcm_partner_share_bp' => (int) config('reporting.default_mcm_share_bp', 0),
                'reason' => 'Initial platform default',
            ], null);
        }

        ReportSource::query()->where('code', ReportSourceCode::HorusGam->value)->update(['is_primary' => true]);
    }
}
