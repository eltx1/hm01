<?php

namespace App\Services\Reporting;

use App\Enums\GamConnectionType;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportSourceCode;
use App\Models\CampaignNetworkInstance;
use App\Models\DemandAccount;
use App\Models\DemandSite;
use App\Models\GamConnection;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class ReportingBridge
{
    public function __construct(private readonly ReportImportService $imports)
    {
    }

    public function connectionForGam(GamConnection $gam, ?User $actor = null): ReportSourceConnection
    {
        $sourceCode = match ($gam->type) {
            GamConnectionType::HorusGam => ReportSourceCode::HorusGam,
            GamConnectionType::McmPartnerGam => ReportSourceCode::McmPartnerGam,
            GamConnectionType::PublisherGam => ReportSourceCode::PublisherGam,
        };

        return $this->connection(
            $sourceCode,
            $gam->organization_id,
            'GAM_CONNECTION',
            $gam->id,
            $gam->name,
            $gam->network_code,
            data_get($gam->configuration, 'currency', config('reporting.default_currency', 'USD')),
            $actor,
        );
    }

    public function connectionForDemand(DemandAccount $account, ?User $actor = null): ReportSourceConnection
    {
        $account->loadMissing(['network', 'financialBinding.connection']);
        if ($account->financialBinding?->is_enabled && $account->financialBinding->connection?->is_enabled) {
            return $account->financialBinding->connection;
        }
        $sourceCode = ReportSourceCode::tryFrom($account->network->code->value) ?? ReportSourceCode::CustomCsv;

        return $this->connection(
            $sourceCode,
            $account->organization_id,
            'DEMAND_ACCOUNT',
            $account->id,
            $account->name,
            $account->account_identifier,
            data_get($account->configuration, 'currency', config('reporting.default_currency', 'USD')),
            $actor,
        );
    }

    public function manualConnection(
        ReportSourceCode $sourceCode,
        ?string $organizationId,
        string $name,
        string $currency = 'USD',
        ?User $actor = null,
    ): ReportSourceConnection {
        $syntheticId = 'manual-'.Str::slug($name).'-'.strtolower($currency);

        return $this->connection(
            $sourceCode,
            $organizationId,
            'MANUAL',
            substr(hash('sha256', $syntheticId), 0, 32),
            $name,
            null,
            $currency,
            $actor,
        );
    }

    public function recordCampaignRows(CampaignNetworkInstance $instance, array $rows, ?User $actor = null): void
    {
        $instance->loadMissing(['campaign.advertiser', 'connection']);
        $connection = $this->connectionForGam($instance->connection, $actor);
        $normalized = [];
        foreach ($rows as $row) {
            $siteIds = (array) $instance->site_ids;
            $normalized[] = array_merge($row, [
                'date' => $row['report_date'] ?? $row['date'] ?? now()->toDateString(),
                'organization_id' => $instance->campaign->organization_id,
                'advertiser_id' => $instance->campaign->advertiser_id,
                'campaign_id' => $instance->campaign_id,
                'gam_connection_id' => $instance->gam_connection_id,
                'site_id' => count($siteIds) === 1 ? $siteIds[0] : null,
                'gross_revenue_minor' => (int) ($row['spend_minor'] ?? 0),
                'spend_minor' => (int) ($row['spend_minor'] ?? 0),
                'video_starts' => (int) ($row['views'] ?? 0),
                'currency' => $instance->campaign->currency,
            ]);
        }
        if ($normalized === []) {
            return;
        }
        $dates = collect($normalized)->pluck('date')->map(fn ($date) => CarbonImmutable::parse($date));
        $external = 'campaign:'.$instance->id.':'.hash('sha256', json_encode(
            collect($rows)->pluck('external_report_id')->filter()->sort()->values()->all(),
            JSON_THROW_ON_ERROR
        ));
        $this->imports->importRows(
            $connection,
            $normalized,
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $dates->min(),
            $dates->max(),
            $actor,
            $external,
            importType: 'CAMPAIGN_BRIDGE',
        );
    }

    public function recordDemandRows(
        DemandAccount $account,
        array $rows,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?User $actor = null,
        ?DemandSite $site = null,
        ?string $externalReportId = null,
        string $importType = 'DEMAND_BRIDGE',
    ): void {
        if ($rows === []) {
            return;
        }
        $site?->loadMissing('site');
        $connection = $this->connectionForDemand($account, $actor);
        $normalized = collect($rows)->map(fn (array $row) => array_merge($row, [
            'date' => $row['date'] ?? $row['report_date'] ?? $from->toDateString(),
            'site_id' => $site?->site_id ?? $row['site_id'] ?? null,
            'publisher_id' => $site?->site?->publisher_id ?? $row['publisher_id'] ?? null,
            'demand_network_id' => $account->demand_network_id,
            'currency' => data_get($account->configuration, 'currency', 'USD'),
            'gross_revenue_minor' => $row['gross_revenue_minor'] ?? $row['revenue_minor'] ?? 0,
        ]))->all();

        $this->imports->importRows(
            $connection,
            $normalized,
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $from,
            $to,
            $actor,
            $externalReportId,
            importType: $importType,
        );
    }

    private function connection(
        ReportSourceCode $sourceCode,
        ?string $organizationId,
        string $connectionType,
        ?string $connectionId,
        string $name,
        ?string $accountIdentifier,
        string $currency,
        ?User $actor,
    ): ReportSourceConnection {
        $definition = config('reporting.sources.'.$sourceCode->value, ['name' => str($sourceCode->value)->headline()]);
        $source = ReportSource::query()->firstOrCreate(
            ['code' => $sourceCode],
            [
                'name' => $definition['name'],
                'is_primary' => (bool) ($definition['primary'] ?? false),
                'is_enabled' => true,
                'capabilities' => $definition['capabilities'] ?? [],
            ],
        );

        return ReportSourceConnection::withoutGlobalScopes()->updateOrCreate(
            [
                'report_source_id' => $source->id,
                'connection_type' => $connectionType,
                'connection_id' => $connectionId,
            ],
            [
                'organization_id' => $organizationId,
                'name' => $name,
                'account_identifier' => $accountIdentifier,
                'currency' => strtoupper($currency),
                'timezone' => config('reporting.default_timezone', 'UTC'),
                'status' => 'ACTIVE',
                'is_enabled' => true,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ],
        );
    }
}
