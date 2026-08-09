<?php

namespace App\Services\Compliance;

use App\Enums\AdsTxtComplianceStatus;

final class AdsTxtComparator
{
    public function __construct(private readonly AdsTxtParser $parser) {}

    /** @return array<string, mixed> */
    public function compare(string $requiredContent, string $liveContent, array $requiredFindings = []): array
    {
        $required = $this->parser->parse($requiredContent);
        $live = $this->parser->parse($liveContent);
        $requiredByLine = collect($required['records'])->keyBy('canonical');
        $liveByLine = collect($live['records'])->keyBy('canonical');
        $correct = $requiredByLine->only($liveByLine->keys())->values()->all();
        $missing = $requiredByLine->except($liveByLine->keys())->values()->all();
        $additional = $liveByLine->except($requiredByLine->keys())->values()->all();

        $requiredDirectives = collect($required['directives'])->where('effective', true)
            ->keyBy(fn (array $item): string => $item['name'].'|'.$item['value']);
        $liveDirectives = collect($live['directives'])->where('effective', true)
            ->keyBy(fn (array $item): string => $item['name'].'|'.$item['value']);
        $missingDirectives = $requiredDirectives->except($liveDirectives->keys())->values()->all();

        $requiredIdentity = collect($required['records'])->keyBy('identity');
        $liveIdentity = collect($live['records'])->groupBy('identity');
        $conflicts = [];
        foreach ($liveIdentity as $identity => $records) {
            if ($records->pluck('canonical')->unique()->count() > 1) {
                $conflicts[] = ['identity' => $identity, 'records' => $records->pluck('canonical')->values()->all(), 'message' => 'The same advertising-system account has conflicting live declarations.'];
            }
            if ($requiredIdentity->has($identity) && ! $records->contains('canonical', $requiredIdentity->get($identity)['canonical'])) {
                $conflicts[] = ['identity' => $identity, 'records' => $records->pluck('canonical')->values()->all(), 'message' => 'The live relationship or certification ID conflicts with the required record.'];
            }
        }
        foreach ($requiredFindings as $finding) {
            if (str_contains((string) ($finding['code'] ?? ''), 'CONFLICT')) {
                $conflicts[] = ['identity' => null, 'records' => [], 'message' => (string) ($finding['message'] ?? 'Canonical supply-chain records conflict.')];
            }
        }

        $invalid = array_values(array_merge($live['invalid'], $live['duplicates']));
        $hasConfiguredRecord = collect($required['records'])->contains(
            fn (array $record): bool => ! $record['is_placeholder'],
        );
        $status = match (true) {
            $conflicts !== [] => AdsTxtComplianceStatus::Conflict,
            $invalid !== [] => AdsTxtComplianceStatus::Invalid,
            ! $hasConfiguredRecord => AdsTxtComplianceStatus::NotConfigured,
            $missing !== [] || $missingDirectives !== [] => $correct !== [] ? AdsTxtComplianceStatus::Partial : AdsTxtComplianceStatus::Missing,
            default => AdsTxtComplianceStatus::Compliant,
        };

        return [
            'status' => $status->value,
            'required_records' => $required['records'],
            'live_records' => $live['records'],
            'correct' => $correct,
            'missing' => $missing,
            'additional' => $additional,
            'invalid' => $invalid,
            'conflicts' => $conflicts,
            'required_directives' => $required['directives'],
            'live_directives' => $live['directives'],
            'missing_directives' => $missingDirectives,
            'warnings' => $live['warnings'],
        ];
    }
}
