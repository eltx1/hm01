from pathlib import Path
import re

# 1. Engine-aware readiness + source-aware reporting.
p = Path('app/Services/Monetization/SiteMonetizationReadinessService.php')
s = p.read_text()
old = """        private readonly AdsTxtComplianceService $adsTxt,\n        private readonly RuntimePolicyResolver $runtimePolicies,\n    ) {}"""
new = """        private readonly AdsTxtComplianceService $adsTxt,\n        private readonly RuntimePolicyResolver $runtimePolicies,\n        private readonly ReportingHealthService $reportingHealth,\n    ) {}"""
if old not in s:
    raise SystemExit('readiness constructor anchor missing')
s = s.replace(old, new, 1)
s = s.replace("$this->module('display', 'GAM / Display Monetization'", "$this->module('display', 'Display Monetization'", 1)

pattern = re.compile(r"\n    private function reporting\(Site \$site\): array\n    \{.*?\n    \}\n\n    private function clickGuard", re.S)
replacement = r'''
    private function reporting(Site $site): array
    {
        $health = $this->reportingHealth->forSite($site);
        $status = match ($health['status']) {
            'ACTIVE' => MonetizationStatus::Active,
            'DEGRADED' => MonetizationStatus::Degraded,
            'NOT_CONFIGURED' => MonetizationStatus::NotConfigured,
            default => MonetizationStatus::Pending,
        };

        return $this->module(
            'reporting',
            'Reporting',
            $status,
            MonetizationDependency::Recommended,
            $health['reason'],
            in_array($health['status'], ['DEGRADED', 'PENDING'], true)
                ? 'Horus Media must review the affected aggregated reporting source.'
                : null,
            null,
            $health['last_update'],
            ['sources' => $health['sources']],
        );
    }

    private function clickGuard'''
s, count = pattern.subn(replacement, s, count=1)
if count != 1:
    raise SystemExit(f'reporting method replacement count {count}')

anchor = """        $critical = collect($modules)->where('dependency', MonetizationDependency::Critical->value);"""
insert = """        if ($site->serving_mode === ServingMode::HorusDirect) {\n            $engineModules = collect($modules)->whereIn('key', ['display', 'prebid', 'native']);\n            $engineAvailable = $engineModules->contains(fn (array $module): bool => in_array(\n                $module['status'],\n                [MonetizationStatus::Active->value, MonetizationStatus::Degraded->value],\n                true,\n            ));\n            if (! $engineAvailable) {\n                return $this->module(\n                    'overall',\n                    'Monetization Overall',\n                    MonetizationStatus::ActionRequired,\n                    MonetizationDependency::Critical,\n                    'No monetization engine is currently available for this GAM-optional website.',\n                    'Enable and repair standalone Header Bidding or Direct Monetization.',\n                    $this->publisherRoute('publisher.sites.show', $site),\n                    $site->updated_at,\n                );\n            }\n        }\n\n        $critical = collect($modules)->where('dependency', MonetizationDependency::Critical->value);"""
if anchor not in s:
    raise SystemExit('overall anchor missing')
s = s.replace(anchor, insert, 1)
p.write_text(s)

# 2. Site 360 includes unified engine control center without duplicating controllers.
p = Path('resources/views/publisher/sites/show.blade.php')
s = p.read_text()
anchor = '<section id="serving" class="detail-grid workspace-section">'
if anchor not in s:
    raise SystemExit('Site 360 serving anchor missing')
s = s.replace(anchor, "@include('admin.sites.serving-control-center')\n\n" + anchor, 1)
p.write_text(s)

# 3. Scheduled transition observation; no daemon and no browser event ingestion.
p = Path('routes/console.php')
s = p.read_text()
use_anchor = 'use App\\Services\\Compliance\\AdsTxtVerifier;\n'
if use_anchor not in s:
    raise SystemExit('console use anchor missing')
s = s.replace(use_anchor, use_anchor + 'use App\\Services\\Monetization\\MonetizationHealthMonitor;\n', 1)
command_anchor = "Schedule::command('operations:heartbeat scheduler')->everyMinute()->withoutOverlapping();"
command = r'''Artisan::command('monetization:health-check {--site=}', function (MonetizationHealthMonitor $monitor): int {
    $sites = Site::withoutGlobalScopes()
        ->where('status', 'ACTIVE')
        ->when($this->option('site'), fn ($query, $id) => $query->whereKey($id))
        ->get();
    foreach ($sites as $site) {
        $states = $monitor->observe($site);
        $broken = collect($states)->where('status', 'BROKEN')->count();
        $this->line($site->display_name.': '.($broken === 0 ? 'HEALTHY' : $broken.' condition(s) require attention'));
    }

    return Command::SUCCESS;
})->purpose('Observe multi-engine monetization health and emit deduplicated state-transition notifications.');

Schedule::command('operations:heartbeat scheduler')->everyMinute()->withoutOverlapping();'''
if command_anchor not in s:
    raise SystemExit('console schedule anchor missing')
s = s.replace(command_anchor, command, 1)
s = s.replace("Schedule::command('adtech:probe')->everyFifteenMinutes()->withoutOverlapping(10);", "Schedule::command('adtech:probe')->everyFifteenMinutes()->withoutOverlapping(10);\nSchedule::command('monetization:health-check')->everyFifteenMinutes()->withoutOverlapping(10);", 1)
p.write_text(s)
