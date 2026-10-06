import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';
import {safeSchedulerResult} from '../../ops/audit/validate-scheduler-incident.mjs';
const audit = readFileSync('ops/audit/scheduler-incident.php', 'utf8');
const workflow = readFileSync('.github/workflows/diagnose-scheduler-incident.yml', 'utf8');
const fixture = {schema_version: 1, diagnostic: 'SCHEDULER_HEALTH', scheduler_status:'STALE', storage_status:'READY', bootstrap_cache_status:'READY', cron_status:'SINGLE_VALID_TARGET', mutex_statuses:{HEARTBEAT:'ACTIVE'}};
test('read-only bootstrap and no tenant, finance or source reads', () => {
  for (const required of ['SET TRANSACTION READ ONLY', 'beforeBootstrapping', 'afterBootstrapping', 'getCachedPackagesPath', 'getCachedServicesPath', 'Stale service manifest', "'logging.default' => 'null'", "config(['cache.default' => 'array'])", 'SCHEDULE_CACHE_DRIVER', 'SCHEDULE_CACHE_STORE', '$db->rollBack()']) assert.ok(audit.includes(required), required);
  assert.ok(audit.indexOf('SET TRANSACTION READ ONLY') < audit.indexOf('->bootstrap()'));
  assert.doesNotMatch(audit, /->(?:insert|update|delete|save|sync|ensure|lockForUpdate)\s*\(|Artisan::call|Cache::|getMessage\(\)|fwrite\(STDERR/);
  assert.doesNotMatch(audit, /table\(['"](?:sites|daily_reports|report_import_jobs|report_dimensions|site_gam)|gross_revenue|publisher_earnings|natega|GamAdUnitReportClient/);
});
test('only protected main accesses production and raw output is never printed', () => {
  assert.match(workflow, /github.event_name == 'push' && github.ref == 'refs\/heads\/main'/);
  assert.match(workflow, /needs: validate/);
  assert.equal((workflow.match(/environment: production/g)||[]).length,1);
  assert.match(workflow, /StrictHostKeyChecking=yes/);
  assert.match(workflow, /validate-scheduler-incident.mjs/);
  assert.match(workflow, /display_errors=0 -d log_errors=0/);
  assert.doesNotMatch(workflow, /banner-delivery|banner-source|video-reporting-incident|HM_PROBE_APPLY|schedule:clear-cache|artisan reporting:|--apply|upload-artifact|cat "\$RUNNER_TEMP\/scheduler/);
});
test('public result reconstruction discards every extra field', () => {
  const result = safeSchedulerResult({...fixture, secrets:'private', money:99, site:'private', timestamp:'private', mutex_statuses:{HEARTBEAT:'ACTIVE', private:'private'}});
  assert.deepEqual(Object.keys(result), ['schema_version','diagnostic','scheduler_status','storage_status','bootstrap_cache_status','cron_status','mutex_statuses']);
  assert.doesNotMatch(JSON.stringify(result), /private|money|timestamp|secret/);
});
test('invalid or injected classifications are rejected', () => {
  for (const bad of [{...fixture,scheduler_status:'private data'}, {...fixture,cron_status:17}, {...fixture,mutex_statuses:{HEARTBEAT:'private data'}}]) assert.throws(()=>safeSchedulerResult(bad),/INVALID_RESULT/);
});
