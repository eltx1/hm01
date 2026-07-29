<?php

namespace App\Services\Reporting;

use App\Enums\ReportSourceCode;
use App\Models\ReportSourceConnection;
use App\Services\Reporting\Connectors\GamReportConnector;
use App\Services\Reporting\Connectors\NativeReportConnector;
use App\Services\Reporting\Connectors\PassthroughReportConnector;
use App\Services\Reporting\Contracts\ReportSourceConnectorInterface;

final class ReportSourceManager
{
    public function __construct(
        private readonly GamReportConnector $gam,
        private readonly NativeReportConnector $native,
        private readonly PassthroughReportConnector $passthrough,
    ) {
    }

    public function for(ReportSourceConnection $connection): ReportSourceConnectorInterface
    {
        $connection->loadMissing('source');
        $code = $connection->source->code;

        return match ($code) {
            ReportSourceCode::HorusGam,
            ReportSourceCode::McmPartnerGam,
            ReportSourceCode::PublisherGam => $this->gam,
            ReportSourceCode::Mgid,
            ReportSourceCode::Taboola,
            ReportSourceCode::Speakol,
            ReportSourceCode::Outbrain => $this->native,
            default => $this->passthrough,
        };
    }
}
