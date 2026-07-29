<?php

namespace App\Services\Reporting\Connectors;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Models\ReportSourceConnection;
use App\Services\Reporting\Contracts\ReportSourceConnectorInterface;
use Carbon\CarbonInterface;

final class PassthroughReportConnector implements ReportSourceConnectorInterface
{
    public function fetch(
        ReportSourceConnection $connection,
        CarbonInterface $from,
        CarbonInterface $to,
        ReportGranularity $granularity,
        ReportFinality $finality,
        array $options = [],
    ): array {
        return [
            'external_report_id' => $options['external_report_id'] ?? null,
            'rows' => $options['rows'] ?? [],
            'totals' => $options['totals'] ?? [],
            'metadata' => ['passthrough' => true],
        ];
    }
}
