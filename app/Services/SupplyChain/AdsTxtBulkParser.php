<?php

namespace App\Services\SupplyChain;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AdsTxtBulkParser
{
    public const MAX_BYTES = 2_097_152;

    public const MAX_LINES = 5_000;

    public function __construct(private readonly DomainNormalizer $domains) {}

    /**
     * @return array{
     *     records: list<array{domain: string, publisher_account_id: string, relationship: string, certification_authority_id: ?string, raw_record: string, source_line: int}>,
     *     invalid: list<array{line: int, content: string, message: string}>,
     *     ignored: int,
     *     duplicates: int,
     *     total_lines: int
     * }
     */
    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'ads_txt_file' => 'The ads.txt import is larger than 2 MB.',
            ]);
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\R/u', $contents) ?: [];
        if (count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages([
                'ads_txt_file' => 'The ads.txt import may contain at most '.self::MAX_LINES.' lines.',
            ]);
        }

        $records = [];
        $invalid = [];
        $ignored = 0;
        $duplicates = 0;
        $seen = [];

        foreach ($lines as $offset => $source) {
            $lineNumber = $offset + 1;
            $line = trim((string) preg_replace('/\s+#.*$/u', '', trim($source)));
            if ($line === '' || str_starts_with($line, '#')) {
                $ignored++;

                continue;
            }
            if (preg_match('/^[A-Z][A-Z0-9_-]*\s*=/i', $line) === 1) {
                $ignored++;

                continue;
            }

            try {
                $record = $this->parseRecord($line, $lineNumber);
            } catch (InvalidArgumentException $exception) {
                $invalid[] = [
                    'line' => $lineNumber,
                    'content' => mb_substr($line, 0, 500),
                    'message' => $exception->getMessage(),
                ];

                continue;
            }

            $identity = strtolower($record['domain'])."\0".$record['publisher_account_id']."\0".$record['relationship']."\0".($record['certification_authority_id'] ?? '');
            if (isset($seen[$identity])) {
                $duplicates++;

                continue;
            }
            $seen[$identity] = true;
            $records[] = $record;
        }

        if ($records === [] && $invalid === []) {
            throw ValidationException::withMessages([
                'ads_txt_records' => 'Paste or upload at least one ads.txt seller record.',
            ]);
        }

        return compact('records', 'invalid', 'ignored', 'duplicates') + ['total_lines' => count($lines)];
    }

    /** @return array{domain: string, publisher_account_id: string, relationship: string, certification_authority_id: ?string, raw_record: string, source_line: int} */
    private function parseRecord(string $line, int $lineNumber): array
    {
        $parts = array_map('trim', explode(',', $line));
        if (count($parts) < 3 || count($parts) > 4) {
            throw new InvalidArgumentException('Expected 3 or 4 comma-separated fields.');
        }

        try {
            $domain = $this->domains->normalize($parts[0]);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Advertising system domain is invalid.');
        }
        if ($domain === 'localhost' || ! str_contains($domain, '.') || str_ends_with($domain, '.local') || str_ends_with($domain, '.internal') || filter_var($domain, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Advertising system domain must be a public hostname.');
        }

        $publisher = $parts[1];
        if ($publisher === '' || strlen($publisher) > 255 || preg_match('/[\s,\x00-\x1F\x7F]/u', $publisher)) {
            throw new InvalidArgumentException('Publisher account ID is invalid.');
        }

        $relationship = strtoupper($parts[2]);
        if (! in_array($relationship, ['DIRECT', 'RESELLER'], true)) {
            throw new InvalidArgumentException('Relationship must be DIRECT or RESELLER.');
        }

        $authority = strtolower($parts[3] ?? '');
        if ($authority !== '' && (strlen($authority) > 128 || preg_match('/^[a-z0-9._-]+$/', $authority) !== 1)) {
            throw new InvalidArgumentException('Certification authority ID is invalid.');
        }

        $rawRecord = implode(', ', array_filter([$domain, $publisher, $relationship, $authority], fn (string $value): bool => $value !== ''));

        return [
            'domain' => $domain,
            'publisher_account_id' => $publisher,
            'relationship' => $relationship,
            'certification_authority_id' => $authority ?: null,
            'raw_record' => $rawRecord,
            'source_line' => $lineNumber,
        ];
    }
}
