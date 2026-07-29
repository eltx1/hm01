<?php

namespace App\Services\Reporting\Connectors;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Models\DemandAccount;
use App\Models\ReportSourceConnection;
use App\Services\Demand\DemandConnectorManager;
use App\Services\Reporting\Contracts\ReportSourceConnectorInterface;
use Carbon\CarbonInterface;
use RuntimeException;

final class NativeReportConnector implements ReportSourceConnectorInterface
{
    public function __construct(private readonly DemandConnectorManager $connectors)
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
        $account = DemandAccount::withoutGlobalScopes()->with('network')->findOrFail($connection->connection_id);
        $result = $this->connectors->for($account)->runReport($from, $to, [
            'filters' => $options['filters'] ?? [],
            'granularity' => $granularity->value,
        ]);

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?: 'The native demand report request failed.');
        }

        $data = (array) $result->data;

        return [
            'external_report_id' => (string) (
                data_get($data, 'report_id')
                ?? data_get($data, 'id')
                ?? hash('sha256', json_encode([$connection->id, $from->toDateString(), $to->toDateString()], JSON_THROW_ON_ERROR))
            ),
            'rows' => (array) (data_get($data, 'rows') ?? data_get($data, 'data') ?? []),
            'totals' => (array) (data_get($data, 'totals') ?? []),
            'metadata' => ['demand_account_id' => $account->id, 'network' => $account->network->code->value],
        ];
    }
}
