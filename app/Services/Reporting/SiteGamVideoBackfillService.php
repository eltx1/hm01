<?php

namespace App\Services\Reporting;

use App\Models\FinancialPeriod;
use App\Models\GamConnection;
use App\Models\ReportSourceConnection;
use App\Models\Site;
use App\Models\SiteGamReportBinding;
use App\Models\SiteGamVideoReportBinding;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Explicit bounded opt-in. The normal Google importer verifies and records the money. */
final class SiteGamVideoBackfillService
{
    public function create(Site $site, string $bindingId, string $from, string $to, string $reason, User $actor): SiteGamVideoReportBinding
    {
        abort_unless($actor->isHorusAdministrator() && $actor->hasPermission('reporting.sources.manage')
            && $actor->hasPermission('finance.periods.close'), 403);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to)
            || $from > $to || substr($from, 0, 7) !== substr($to, 0, 7)
            || mb_strlen(trim($reason)) < 12 || mb_strlen($reason) > 1000) $this->invalid();
        if (CarbonImmutable::parse($from)->toDateString() !== $from || CarbonImmutable::parse($to)->toDateString() !== $to) $this->invalid();
        $original = SiteGamVideoReportBinding::withoutGlobalScopes()->with('connection.source', 'gamConnection')
            ->where('site_id', $site->id)->where('active_site_id', $site->id)->findOrFail($bindingId);
        if ($to >= $original->starts_on->toDateString() || $to >= CarbonImmutable::now($original->connection->timezone)->toDateString()) $this->invalid();

        return DB::transaction(function () use ($site, $original, $from, $to, $reason, $actor) {
            // Same network/site/period lock order as normal imports and month close.
            $gam = GamConnection::withoutGlobalScopes()->lockForUpdate()->findOrFail($original->gam_connection_id);
            app(SiteGamUnitClaims::class)->lockNetwork((string) $gam->network_code);
            $site = Site::withoutGlobalScopes()->lockForUpdate()->findOrFail($site->id);
            $current = SiteGamVideoReportBinding::withoutGlobalScopes()->with('connection.source')
                ->where('active_site_id', $site->id)->lockForUpdate()->findOrFail($original->id);
            if ($current->organization_id !== $site->organization_id || $current->gam_connection_id !== $gam->id
                || (string) $gam->network_code !== $current->network_code || ! $gam->is_enabled
                || ! app(SiteGamVideoReportingService::class)->availableConnections($site)->whereKey($gam->id)->exists()
                || ! $current->connection->is_enabled || ! $current->connection->source->is_enabled
                || $to >= $current->starts_on->toDateString() || $current->ends_on !== null) $this->invalid();
            $period = app(FinancialPeriodService::class)->periodFor(CarbonImmutable::parse($from), $current->connection->currency);
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($from < $site->created_at->toDateString() || $period->status->value !== 'OPEN') $this->invalid();
            $same = SiteGamVideoReportBinding::withoutGlobalScopes()->where('site_id', $site->id)
                ->where('gam_connection_id', $gam->id)->where('ad_unit_id', $current->ad_unit_id)
                ->whereDate('starts_on', $from)->whereDate('ends_on', $to)->whereNull('cancelled_at')
                ->whereHas('connection', fn ($q) => $q->where('configuration->historical_backfill_of', $current->id))->first();
            if ($same) return $same;
            foreach ([SiteGamReportBinding::class, SiteGamVideoReportBinding::class] as $model) {
                $query = $model::withoutGlobalScopes()->whereDate('starts_on', '<=', $to)
                    ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from))
                    ->where(fn ($q) => $q->where(fn ($q) => $q->where('network_code', $current->network_code)->where('ad_unit_id', $current->ad_unit_id))
                        ->when($model === SiteGamVideoReportBinding::class, fn ($q) => $q->orWhere('site_id', $site->id)));
                if ($model === SiteGamVideoReportBinding::class) $query->whereNull('cancelled_at');
                if ($query->exists()) $this->invalid();
            }
            $id = (string) Str::ulid();
            $connection = ReportSourceConnection::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id, 'report_source_id' => $current->connection->report_source_id,
                'name' => Str::limit($site->display_name, 170, '').' — Video history '.$from.' / '.$to,
                'connection_type' => 'SITE_GAM_VIDEO_AD_UNIT', 'connection_id' => $id,
                'account_identifier' => $current->network_code.':'.$current->ad_unit_id,
                'currency' => $current->connection->currency, 'timezone' => $current->connection->timezone,
                'configuration' => ['historical_backfill_of' => $current->id, 'historical_backfill_reason' => trim($reason),
                    'report_currency' => $current->connection->currency,
                    'source_network_currency' => data_get($current->connection->configuration, 'source_network_currency')],
                'status' => 'ACTIVE', 'is_enabled' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $binding = SiteGamVideoReportBinding::withoutGlobalScopes()->create([
                'id' => $id, 'organization_id' => $site->organization_id, 'site_id' => $site->id,
                'gam_connection_id' => $gam->id, 'report_source_connection_id' => $connection->id,
                'active_site_id' => null, 'active_unit_key' => null, 'network_code' => $current->network_code,
                'ad_unit_id' => $current->ad_unit_id, 'ad_unit_name' => $current->ad_unit_name, 'ad_unit_code' => $current->ad_unit_code,
                'starts_on' => $from, 'ends_on' => $to, 'created_by' => $actor->id,
            ]);
            app(SiteGamReportScope::class)->ensure($binding);
            app(AuditRecorder::class)->record('reporting.site_gam_video.historical_backfill_requested', $site->organization_id, $actor, $binding,
                newValues: ['original_binding_id' => $current->id, 'from' => $from, 'to' => $to, 'reason' => trim($reason)],
                metadata: ['financial_rows_changed' => false, 'forward_binding_changed' => false]);
            return $binding->load('connection');
        });
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['video_history' => 'Choose an uncovered range in one open month before the current Video activation, with a specific reason. Existing history and closed accounting cannot be replaced.']);
    }
}
