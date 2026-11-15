<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Diagnostics\PerformancePolicy;
use App\Services\Diagnostics\PerformanceReport;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SitePerformanceController extends Controller
{
    public function show(Site $site, PerformanceReport $report, PerformancePolicy $policy)
    {
        return view('admin.sites.performance', ['site' => $site, 'report' => $report->forSite($site),
            'configured' => $policy->configured(), 'settings' => $site->siteConfig?->observability_settings['performance'] ?? ['enabled' => false, 'sampleBp' => 100]]);
    }

    public function update(Request $request, Site $site, PerformancePolicy $policy, AuditRecorder $audit, SiteConfigPublisher $publisher)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'sample_bp' => ['required', 'integer', 'between:1,1000']]);
        if ($request->boolean('enabled') && ! $policy->configured()) {
            throw ValidationException::withMessages(['enabled' => 'The separate observation service has not been configured. Collection remains disabled.']);
        }
        DB::transaction(function () use ($request, $site, $data, $audit, $publisher) {
            $config = $site->siteConfig()->lockForUpdate()->firstOrFail();
            $settings = $config->observability_settings ?? [];
            $before = $settings['performance'] ?? ['enabled' => false, 'sampleBp' => 100];
            $after = ['enabled' => $request->boolean('enabled'), 'sampleBp' => (int) $data['sample_bp']];
            if ($before === $after) return;
            $settings['performance'] = $after;
            $config->update(['observability_settings' => $settings]);
            $audit->record('site.performance_observation.updated', $site->organization_id, $request->user(), $site, $before, $after);
            $publisher->publishActiveProduction($site->refresh(), $request->user());
        });

        return back()->with('operation_reference', $request->header('X-Request-ID'))->with('status', 'Observation settings saved. Active websites use normal static publication.');
    }
}
