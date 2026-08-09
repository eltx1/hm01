<?php

namespace App\Services\Compliance;

final class AdsTxtParser
{
    private const DIRECTIVES = [
        'CONTACT', 'SUBDOMAIN', 'INVENTORYPARTNERDOMAIN', 'OWNERDOMAIN', 'MANAGERDOMAIN',
    ];

    /** @return array{records: list<array<string, mixed>>, directives: list<array<string, mixed>>, invalid: list<array<string, mixed>>, duplicates: list<array<string, mixed>>, warnings: list<array<string, mixed>>} */
    public function parse(string $content): array
    {
        $records = [];
        $directives = [];
        $invalid = [];
        $duplicates = [];
        $warnings = [];
        $recordKeys = [];
        $directiveKeys = [];

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $offset => $original) {
            $lineNumber = $offset + 1;
            $withoutComment = preg_replace('/\s*#.*$/', '', $original) ?? $original;
            $line = trim($withoutComment);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
                $name = strtoupper($matches[1]);
                $value = trim($matches[2]);
                if (! in_array($name, self::DIRECTIVES, true)) {
                    $directives[] = [
                        'name' => $name,
                        'value' => $value,
                        'canonical' => $name.'='.$value,
                        'supported' => false,
                        'effective' => true,
                        'line' => $lineNumber,
                    ];

                    continue;
                }
                $normalized = $this->normalizeDirective($name, $value);
                if ($normalized === null) {
                    $invalid[] = $this->problem($lineNumber, $original, 'INVALID_DIRECTIVE', 'The directive value is malformed.');

                    continue;
                }
                $key = $name.'|'.$normalized['scope'];
                $effective = true;
                if ($name === 'OWNERDOMAIN' && isset($directiveKeys[$key])) {
                    $effective = false;
                    $warnings[] = $this->problem($lineNumber, $original, 'ADDITIONAL_OWNERDOMAIN_IGNORED', 'Only the first OWNERDOMAIN declaration is effective.');
                } elseif ($name === 'MANAGERDOMAIN' && isset($directiveKeys[$key])) {
                    $invalid[] = $this->problem($lineNumber, $original, 'DUPLICATE_MANAGERDOMAIN_SCOPE', 'Only one MANAGERDOMAIN is allowed per country or global scope.');
                }
                $directiveKeys[$key] = true;
                $directives[] = [
                    'name' => $name,
                    'value' => $normalized['value'],
                    'canonical' => $name.'='.$normalized['value'],
                    'supported' => true,
                    'effective' => $effective,
                    'line' => $lineNumber,
                ];

                continue;
            }

            [$recordPart, $extension] = array_pad(explode(';', $line, 2), 2, null);
            $fields = array_map('trim', explode(',', $recordPart));
            if (count($fields) < 3 || count($fields) > 4) {
                $invalid[] = $this->problem($lineNumber, $original, 'MALFORMED_RECORD', 'An ads.txt record requires three fields and allows one certification-authority field.');

                continue;
            }

            [$domain, $accountId, $relationship] = $fields;
            $authority = $fields[3] ?? null;
            $domain = strtolower(rtrim($domain, '.'));
            $relationship = strtoupper($relationship);
            $authority = filled($authority) ? strtolower((string) $authority) : null;
            if (! $this->validDomain($domain)
                || $accountId === '' || strlen($accountId) > 255 || preg_match('/[\s,\x00-\x1F\x7F]/u', $accountId)
                || ! in_array($relationship, ['DIRECT', 'RESELLER'], true)
                || ($authority !== null && (strlen($authority) > 128 || preg_match('/^[a-z0-9._-]+$/', $authority) !== 1))
                || ($extension !== null && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $extension))) {
                $invalid[] = $this->problem($lineNumber, $original, 'INVALID_RECORD', 'One or more ads.txt record fields are invalid.');

                continue;
            }

            $canonical = implode(', ', array_filter([$domain, $accountId, $relationship, $authority], fn (?string $value): bool => filled($value)));
            if (isset($recordKeys[$canonical])) {
                $duplicates[] = $this->problem($lineNumber, $original, 'DUPLICATE_RECORD', 'This ads.txt record duplicates an earlier record.');

                continue;
            }
            $recordKeys[$canonical] = true;
            $records[] = [
                'domain' => $domain,
                'publisher_account_id' => $accountId,
                'relationship' => $relationship,
                'certification_authority_id' => $authority,
                'extension' => $extension !== null ? trim($extension) : null,
                'canonical' => $canonical,
                'identity' => $domain.'|'.$accountId,
                'is_placeholder' => $domain === 'placeholder.example.com' && $accountId === 'placeholder'
                    && $relationship === 'DIRECT' && $authority === 'placeholder',
                'line' => $lineNumber,
            ];
        }

        return compact('records', 'directives', 'invalid', 'duplicates', 'warnings');
    }

    /** @return array{value: string, scope: string}|null */
    private function normalizeDirective(string $name, string $value): ?array
    {
        if ($value === '' || preg_match('/[\x00-\x1F\x7F#]/', $value)) {
            return null;
        }
        if ($name === 'CONTACT') {
            return ['value' => $value, 'scope' => 'global'];
        }
        if ($name === 'MANAGERDOMAIN') {
            $fields = array_map('trim', explode(',', $value));
            if (count($fields) > 2 || ! $this->validDomain(strtolower(rtrim($fields[0], '.')))) {
                return null;
            }
            $country = isset($fields[1]) && $fields[1] !== '' ? strtoupper($fields[1]) : null;
            if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                return null;
            }
            $domain = strtolower(rtrim($fields[0], '.'));

            return ['value' => implode(', ', array_filter([$domain, $country])), 'scope' => $country ?: 'global'];
        }

        $domain = strtolower(rtrim($value, '.'));
        if (! $this->validDomain($domain)) {
            return null;
        }

        return ['value' => $domain, 'scope' => $name === 'SUBDOMAIN' || $name === 'INVENTORYPARTNERDOMAIN' ? $domain : 'global'];
    }

    private function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $domain) === 1;
    }

    /** @return array{line: int, content: string, code: string, message: string} */
    private function problem(int $line, string $content, string $code, string $message): array
    {
        return ['line' => $line, 'content' => mb_substr($content, 0, 1000), 'code' => $code, 'message' => $message];
    }
}
