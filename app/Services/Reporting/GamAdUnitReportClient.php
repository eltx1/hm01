<?php

namespace App\Services\Reporting;

use App\Models\GamConnection;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamOfficialSoapTransport;
use App\Services\Gam\GamOperationExecutor;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GamAdUnitReportClient
{
    public function __construct(
        private readonly GamSoapTransportInterface $transport,
        private readonly GamOperationExecutor $operations,
    ) {}

    public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
    {
        $response = null;
        $result = $this->operations->execute($connection, 'reporting.ad_unit.'.$method, $service, $method, $payload,
            function () use ($connection, $service, $method, $payload, &$response): array {
                $transport = $this->transport instanceof GamOfficialSoapTransport ? $this->transport->withTimeout(15) : $this->transport;
                $response = $transport->call($connection, $service, $method, $payload);

                // Download URLs contain temporary credentials. Never persist them in audit payloads.
                return $method === 'getReportDownloadUrlWithOptions' ? ['download_url_received' => true] : $response;
            }, ['write' => false, 'dry_run' => false, 'max_attempts' => 1]);
        if (! $result->success || ! is_array($response)) {
            throw new RuntimeException($result->errorMessage ?: 'Google Ad Manager reporting request failed.');
        }

        return $response;
    }

    public function units(GamConnection $connection, string $input, bool $search = false): array
    {
        $numeric = ctype_digit($input);
        $query = $numeric ? 'id = :unit' : ($search ? '(name LIKE :unit OR adUnitCode LIKE :unit)' : '(name = :unit OR adUnitCode = :unit)');
        $response = $this->call($connection, 'InventoryService', 'getAdUnitsByStatement', [
            'filterStatement' => [
                'query' => 'WHERE '.$query.' ORDER BY id ASC LIMIT '.($search ? '20' : '2'),
                'values' => [['key' => 'unit', 'value' => [
                    '__type' => $numeric ? 'NumberValue' : 'TextValue',
                    'value' => $search && ! $numeric ? '%'.$input.'%' : $input,
                ]]],
            ],
        ]);

        return array_values($response['results'] ?? []);
    }

    public function download(GamConnection $connection, string $jobId): string
    {
        $response = $this->call($connection, 'ReportService', 'getReportDownloadUrlWithOptions', [
            'reportJobId' => $jobId,
            'reportDownloadOptions' => [
                'exportFormat' => 'CSV_DUMP', 'includeReportProperties' => false,
                'includeTotalsRow' => false, 'useGzipCompression' => false,
            ],
        ]);
        $url = (string) ($response['value'] ?? '');
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $trusted = collect(['googleapis.com', 'google.com', 'doubleclick.net'])
            ->contains(fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain));
        if (! $trusted || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            throw new RuntimeException('Google returned an invalid report download location.');
        }
        try {
            $download = Http::connectTimeout(10)->timeout(40)->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 15])->get($url);
            if (! $download->successful()) {
                throw new RuntimeException('Report download failed.');
            }
            $stream = $download->toPsrResponse()->getBody();
            $maximum = (int) config('reporting.csv_max_bytes', 25 * 1024 * 1024);
            $deadline = microtime(true) + 40;
            $csv = '';
            while (! $stream->eof()) {
                $csv .= $stream->read(65536);
                if (strlen($csv) > $maximum || microtime(true) > $deadline) {
                    throw new RuntimeException('Report exceeds the download size limit.');
                }
            }
            $stream->close();

            return $csv;
        } catch (\Throwable) {
            // HTTP exceptions may include the signed URL; keep it out of logs and the UI.
            throw new RuntimeException('The Google report could not be downloaded safely. It will be retried.');
        }
    }
}
