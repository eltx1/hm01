<?php

namespace App\Services\MobileApps;

use App\Models\MobileAppReportBinding;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MobileAppReportScope
{
    public function ensure(MobileAppReportBinding $binding): array
    {
        $app = $binding->mobileApp;
        if (! $app || $app->organization_id !== $binding->organization_id) throw new RuntimeException('The app reporting owner is invalid.');
        return ['store_app_id' => $app->store_app_id, 'effective_from' => $binding->starts_on->toDateString(),
            'fingerprint' => hash('sha256', implode('|', [$app->id, $app->publisher_id, $app->organization_id, $app->platform,
                $app->store_app_id, $binding->network_code, $binding->ad_unit_id, $binding->starts_on->toDateString()]))];
    }

    public function assertCurrent(MobileAppReportBinding $binding, array $scope): void
    {
        if ($scope !== $this->ensure($binding->fresh('mobileApp'))) throw new RuntimeException('The app report scope changed. Retry.');
    }

    public function checkpoint(MobileAppReportBinding $binding, array $scope, string $key, ?array $job, ?string $networkCurrency = null): void
    {
        DB::transaction(function () use ($binding, $scope, $key, $job, $networkCurrency) {
            $this->assertCurrent($binding, $scope);
            $connection = $binding->connection()->lockForUpdate()->firstOrFail();
            $configuration = $connection->configuration ?? [];
            if ($job) $configuration['google_jobs'][$key] = $job;
            else unset($configuration['google_jobs'][$key]);
            if ($networkCurrency) $configuration['source_network_currency'] = $networkCurrency;
            $connection->update(['configuration' => $configuration]);
        });
    }
}
