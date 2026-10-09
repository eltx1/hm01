import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('estimate display is an explicit opt-in and never an age-based promotion', () => {
    const unified = read('app/Services/Reporting/UnifiedReportService.php');
    const website = read('app/Services/Reporting/AdminWebsitePerformanceService.php');
    assert.match(unified, /bool \$includeEstimates = false/);
    assert.match(website, /function summaries\([^\n]+bool \$includeEstimates = false/);
    assert.match(website, /function summary\([^\n]+bool \$includeEstimates = false/);
    const policy = read('app/Services/Reporting/ReportDisplayQuery.php');
    assert.match(policy, /if \(! \$includeEstimates\)[\s\S]+ReportFinality::Finalized/);
    assert.match(policy, /ReportFinality::Estimated->value, ReportFinality::Finalized->value/);
    assert.match(policy, /ReportImportStatus::Completed->value/);
    assert.match(policy, /whereColumn\('report_import_jobs.report_source_connection_id', 'daily_reports.report_source_connection_id'\)/);
    assert.doesNotMatch(policy, /now\(|today\(|->(?:update|save|create)\(/);
});

test('financial and balance entry points never opt into reporting estimates', () => {
    for (const path of [
        'app/Services/Reporting/FinancialPeriodService.php',
        'app/Services/Reporting/PublisherFinanceService.php',
        'app/Services/Reporting/PublisherStatementService.php',
        'app/Services/Reporting/PublisherPaymentService.php',
    ]) assert.doesNotMatch(read(path), /includeEstimates|ReportDisplayQuery/, path);
    assert.match(read('app/Http/Controllers/Admin/ReportingController.php'), /adminSummary\([^\n]+includeEstimates: true/);
    assert.match(read('app/Http/Controllers/Admin/WebsiteReportController.php'), /summary\([^\n]+includeEstimates: true/);
});

test('estimate CSV labels are opt-in and missing primary cards are not zero-filled', () => {
    const csv = read('app/Services/Reporting/PerformanceReportCsv.php');
    assert.match(csv, /bool \$includeFinality = false/);
    assert.match(csv, /\$includeFinality \? \['Includes estimates'\]/);
    assert.match(csv, /\$day\['has_estimates'\]/);
    const overview = read('resources/views/admin/reporting/index.blade.php');
    assert.match(overview, /\$summary\['available'\] \? \$value : 'Unavailable'/);
    assert.match(overview, /Includes estimates/);
    assert.match(overview, /Awaiting finalization/);
});

test('changed report Blade directives stay compiler-recognizable and finality badges wrap', () => {
    for (const path of [
        'resources/views/admin/reporting/index.blade.php',
        'resources/views/admin/reporting/website.blade.php',
        'resources/views/admin/reporting/websites.blade.php',
        'resources/views/publisher/finance/_performance.blade.php',
        'resources/views/components/report-coverage.blade.php',
        'resources/views/components/report-performance-table.blade.php',
        'resources/views/components/video-performance.blade.php',
        'resources/views/components/video-performance-table.blade.php',
    ]) assert.doesNotMatch(read(path), /\w@(if|elseif|else|endif|foreach|endforeach|unless|endunless)\b/, path);
    assert.match(read('resources/css/reporting-experience.css'), /\.report-finality-state\s*\{[^}]*white-space: normal/);
});
