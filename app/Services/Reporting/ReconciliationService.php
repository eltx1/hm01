<?php

namespace App\Services\Reporting;

use App\Enums\ReconciliationStatus;
use App\Models\ReconciliationRun;
use App\Models\ReportImportJob;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReconciliationService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function forImport(
        ReportImportJob $job,
        array $storedTotals,
        array $sourceTotals,
        ?User $actor = null,
    ): ReconciliationRun {
        $fields = ['ad_requests', 'matched_requests', 'impressions', 'clicks', 'gross_revenue_minor'];
        $differences = [];
        $maxBasisPoints = 0;
        foreach ($fields as $field) {
            if (! array_key_exists($field, $sourceTotals)) {
                continue;
            }
            $source = (int) $sourceTotals[$field];
            $stored = (int) ($storedTotals[$field] ?? 0);
            $difference = $stored - $source;
            $basisPoints = $source !== 0 ? (int) round(abs($difference) * 10000 / abs($source)) : ($stored === 0 ? 0 : 10000);
            $differences[$field] = [
                'source' => $source,
                'stored' => $stored,
                'difference' => $difference,
                'difference_basis_points' => $basisPoints,
            ];
            $maxBasisPoints = max($maxBasisPoints, $basisPoints);
        }

        $threshold = (int) config('reporting.discrepancy_warning_bp', 100);
        $status = $sourceTotals === [] || $maxBasisPoints <= $threshold
            ? ReconciliationStatus::Matched
            : ReconciliationStatus::Warning;
        $warnings = $status === ReconciliationStatus::Warning
            ? [['code' => 'SOURCE_TOTAL_DISCREPANCY', 'maximum_difference_basis_points' => $maxBasisPoints]]
            : [];

        $run = ReconciliationRun::withoutGlobalScopes()->create([
            'organization_id' => $job->organization_id,
            'report_source_connection_id' => $job->report_source_connection_id,
            'report_import_job_id' => $job->id,
            'period_start' => $job->period_start,
            'period_end' => $job->period_end,
            'granularity' => $job->granularity,
            'status' => $status,
            'source_totals' => $sourceTotals ?: null,
            'stored_totals' => $storedTotals,
            'differences' => $differences ?: null,
            'discrepancy_basis_points' => $maxBasisPoints,
            'warnings' => $warnings ?: null,
            'started_at' => now(),
            'completed_at' => now(),
            'created_by' => $actor?->id,
        ]);

        if ($actor) {
            $this->audit->record('reporting.reconciliation.completed', $job->organization_id, $actor, $run, newValues: [
                'status' => $status->value,
                'discrepancy_basis_points' => $maxBasisPoints,
                'warnings' => $warnings,
            ]);
        }

        return $run;
    }

    public function recordRemediation(ReconciliationRun $run, string $note, User $actor): ReconciliationRun
    {
        if (! $actor->isHorusAdministrator() || ! $actor->hasPermission('finance.reconciliation.manage')) {
            abort(403);
        }
        $note = trim($note);
        if (mb_strlen($note) < 12) {
            throw ValidationException::withMessages(['remediation_note' => 'A specific remediation note of at least 12 characters is required.']);
        }

        return DB::transaction(function () use ($run, $note, $actor): ReconciliationRun {
            $run = ReconciliationRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);
            $before = $run->status;
            $run->update([
                'status' => in_array($run->status, [ReconciliationStatus::Warning, ReconciliationStatus::Failed], true)
                    ? ReconciliationStatus::Resolved
                    : $run->status,
                'remediation_note' => $note,
                'remediated_at' => now(),
                'remediated_by' => $actor->id,
            ]);
            $this->audit->record('finance.reconciliation.remediation_recorded', $run->organization_id ?? $actor->organization_id, $actor, $run, [
                'status' => $before->value,
            ], [
                'status' => $run->status->value,
                'remediation_note' => $note,
                'financial_totals_mutated' => false,
            ]);

            return $run->refresh();
        });
    }
}
