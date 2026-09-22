<?php

namespace App\Services\Reporting\Connectors;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Models\GamConnection;
use App\Models\ReportSourceConnection;
use App\Services\Gam\GamConnectorManager;
use App\Services\Reporting\Contracts\ReportSourceConnectorInterface;
use Carbon\CarbonInterface;
use RuntimeException;

final class GamReportConnector implements ReportSourceConnectorInterface
{
    public function __construct(private readonly GamConnectorManager $connectors)
    {
    }

    public function fetch(
        ReportSourceConnection $connection,
        CarbonInterface $from,
        CarbonInterface $to,
        ReportGranularity $granularity,
        ReportFinality $finality,
        array $options = [],
    ): array {
        $gam = GamConnection::withoutGlobalScopes()->findOrFail($connection->connection_id);
        $dimensions = [
            $granularity === ReportGranularity::Hourly ? 'DATE_HOUR' : 'DATE',
            'AD_UNIT_ID', 'LINE_ITEM_ID', 'COUNTRY_CODE', 'DEVICE_CATEGORY_NAME',
            'BROWSER_NAME', 'OPERATING_SYSTEM_NAME', 'CREATIVE_SIZE',
        ];
        $columns = [
            'AD_SERVER_AD_REQUESTS', 'AD_SERVER_MATCHED_REQUESTS', 'AD_SERVER_UNFILLED_IMPRESSIONS',
            'AD_SERVER_IMPRESSIONS', 'AD_SERVER_CLICKS', 'AD_SERVER_CPM_AND_CPC_REVENUE',
            'ACTIVE_VIEW_VIEWABLE_IMPRESSIONS_RATE', 'VIDEO_VIEWERSHIP_START',
            'VIDEO_VIEWERSHIP_COMPLETE',
        ];

        $result = $this->connectors->for($gam)->runReport([
            'dimensions' => $dimensions,
            'columns' => $columns,
            'dateRangeType' => 'CUSTOM_DATE',
            'startDate' => $from->toDateString(),
            'endDate' => $to->toDateString(),
            'statement' => $options['statement'] ?? null,
        ], [
            'dry_run' => false,
            'idempotency_key' => hash('sha256', 'report-read|'.$connection->id.'|'.$granularity->value.'|'.$from->toIso8601String().'|'.$to->toIso8601String()),
        ]);

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?: 'The GAM report request failed.');
        }

        $data = (array) $result->data;
        $rows = data_get($data, 'rows')
            ?? data_get($data, 'rval.rows')
            ?? data_get($data, 'report.rows')
            ?? data_get($data, 'data')
            ?? [];

        return [
            'external_report_id' => (string) (
                data_get($data, 'report_id')
                ?? data_get($data, 'id')
                ?? hash('sha256', json_encode([$connection->id, $from->toDateString(), $to->toDateString(), $granularity->value], JSON_THROW_ON_ERROR))
            ),
            'rows' => is_array($rows) ? $rows : [],
            'totals' => (array) (data_get($data, 'totals') ?? []),
            'metadata' => ['gam_connection_id' => $gam->id, 'network_code' => $gam->network_code],
        ];
    }
}
