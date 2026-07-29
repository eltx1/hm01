<?php

namespace App\Services\Reporting\Contracts;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Models\ReportSourceConnection;
use Carbon\CarbonInterface;

interface ReportSourceConnectorInterface
{
    public function fetch(
        ReportSourceConnection $connection,
        CarbonInterface $from,
        CarbonInterface $to,
        ReportGranularity $granularity,
        ReportFinality $finality,
        array $options = [],
    ): array;
}
