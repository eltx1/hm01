<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $canonical = strtoupper(trim((string) config('reporting.canonical_currency', 'USD')));
        if (! preg_match('/^[A-Z]{3}$/D', $canonical)) {
            $canonical = 'USD';
        }

        $connections = DB::table('report_source_connections')
            ->where('connection_type', 'SITE_GAM_AD_UNIT')
            ->orderBy('id')
            ->get();

        foreach ($connections as $connection) {
            $configuration = json_decode((string) ($connection->configuration ?? '[]'), true);
            if (! is_array($configuration)) {
                $configuration = [];
            }

            $binding = DB::table('site_gam_report_bindings')
                ->where('report_source_connection_id', $connection->id)
                ->first();

            $networkCurrency = null;
            if ($binding) {
                $networkCurrency = DB::table('gam_networks')
                    ->where('gam_connection_id', $binding->gam_connection_id)
                    ->where('network_code', $binding->network_code)
                    ->orderByDesc('is_current')
                    ->orderByDesc('last_seen_at')
                    ->value('currency_code');
            }
            $networkCurrency = strtoupper(trim((string) ($networkCurrency ?: $connection->currency)));

            $configuration['currency_policy'] = 'CANONICAL_REPORTING_CURRENCY';
            $configuration['report_currency'] = $canonical;
            $configuration['source_network_currency'] = $networkCurrency;
            $configuration['currency_normalization_requested_at'] = now()->toIso8601String();

            if (strtoupper((string) $connection->currency) === $canonical) {
                DB::table('report_source_connections')->where('id', $connection->id)->update([
                    'configuration' => json_encode($configuration, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);

                continue;
            }

            $hasClosedDaily = DB::table('daily_reports')
                ->join('financial_periods', 'financial_periods.id', '=', 'daily_reports.financial_period_id')
                ->where('daily_reports.report_source_connection_id', $connection->id)
                ->where('financial_periods.status', '!=', 'OPEN')
                ->exists();
            $hasClosedHourly = DB::table('hourly_reports')
                ->join('financial_periods', 'financial_periods.id', '=', 'hourly_reports.financial_period_id')
                ->where('hourly_reports.report_source_connection_id', $connection->id)
                ->where('financial_periods.status', '!=', 'OPEN')
                ->exists();

            if ($hasClosedDaily || $hasClosedHourly) {
                $configuration['canonical_currency_upgrade_blocked'] = true;
                $configuration['canonical_currency_upgrade_reason'] = 'CLOSED_FINANCIAL_HISTORY';

                DB::table('report_source_connections')->where('id', $connection->id)->update([
                    'configuration' => json_encode($configuration, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);

                continue;
            }

            unset($configuration['google_jobs'], $configuration['sync_due']);
            $configuration['canonical_currency_upgrade_blocked'] = false;
            $configuration['currency_rebackfill_required'] = true;
            $configuration['currency_normalized_at'] = now()->toIso8601String();

            DB::table('report_source_connections')->where('id', $connection->id)->update([
                'currency' => $canonical,
                'configuration' => json_encode($configuration, JSON_UNESCAPED_SLASHES),
                'last_error' => null,
                'updated_at' => now(),
            ]);

            DB::table('report_import_jobs')
                ->where('report_source_connection_id', $connection->id)
                ->whereIn('status', ['PENDING', 'FAILED'])
                ->update([
                    'status' => 'DUPLICATE',
                    'error_message' => null,
                    'next_retry_at' => null,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: Google-provided USD report values must never
        // be relabeled back into a network currency without re-running the source report.
    }
};
