import {readFileSync, mkdtempSync, writeFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';
import assert from 'node:assert/strict';
import test from 'node:test';
import {safeMainReportingLeaseResult} from '../../ops/audit/validate-main-reporting-lease.mjs';

const audit = readFileSync('ops/audit/observe-main-reporting-lease.php', 'utf8');
const workflow = readFileSync('.github/workflows/observe-main-reporting-lease.yml', 'utf8');
const fixture = {schema_version: 1, observation: 'MAIN_REPORTING_LEASE', audit_status: 'RECORDED',
  lease_status: 'ACTIVE', configuration_status: 'PASS', global_topology: 'UNPROVEN'};

test('public result is a closed enum-only envelope', () => {
  assert.deepEqual(safeMainReportingLeaseResult(fixture), fixture);
  for (const audit_status of ['RECORDED', 'ALREADY_RECORDED']) {
    for (const lease_status of ['ACTIVE', 'EXPIRED', 'ABSENT']) {
      for (const configuration_status of ['PASS', 'FAIL']) {
        const value = {...fixture, audit_status, lease_status, configuration_status};
        assert.deepEqual(safeMainReportingLeaseResult(value), value);
      }
    }
  }
  const unavailable = {...fixture, audit_status: 'UNAVAILABLE', lease_status: 'UNAVAILABLE', configuration_status: 'UNAVAILABLE'};
  assert.deepEqual(safeMainReportingLeaseResult(unavailable), unavailable);
  for (const [key, value] of Object.entries({expiration: 1000, owner: 'private', key: 'private', host: 'private', session_id: 1,
    timestamp: 'private', database: 'private', operation_id: 'private', observed_release: 'private', amount: 12})) {
    assert.throws(() => safeMainReportingLeaseResult({...fixture, [key]: value}), /INVALID_RESULT/);
  }
});

test('invalid, missing, injected, nested and falsely proven classifications are rejected', () => {
  for (const bad of [null, [], true, 'private', {...fixture, schema_version: '1'}, {...fixture, global_topology: 'PROVEN'},
    {...fixture, audit_status: 'UNAVAILABLE'}, {...fixture, lease_status: 'UNAVAILABLE'},
    {...fixture, configuration_status: ['PASS']}, {...fixture, lease_status: 'ACTIVE\nprivate'},
    {...fixture, observation: 'other'}, {...fixture, audit_status: null}]) {
    assert.throws(() => safeMainReportingLeaseResult(bad), /INVALID_RESULT/);
  }
  for (const key of Object.keys(fixture)) {
    const bad = {...fixture}; delete bad[key];
    assert.throws(() => safeMainReportingLeaseResult(bad), /INVALID_RESULT/);
  }
});

test('CLI rejects duplicate keys, overlong responses, noncanonical JSON and unexpected output without leaking input', () => {
  const directory = mkdtempSync(join(tmpdir(), 'hm-lease-validator-'));
  const path = join(directory, 'result.json');
  try {
    for (const text of ['private', JSON.stringify({...fixture, secret: 'private'}), JSON.stringify(fixture).replace('"ACTIVE"', '"private"'),
      JSON.stringify(fixture).replace('"schema_version":1', '"schema_version":1,"schema_version":1'),
      JSON.stringify(fixture) + 'private', JSON.stringify(fixture, null, 2), 'private'.repeat(1024)]) {
      writeFileSync(path, text);
      const child = spawnSync(process.execPath, ['ops/audit/validate-main-reporting-lease.mjs', path], {encoding: 'utf8'});
      assert.equal(child.status, 1);
      assert.equal(child.stdout, '');
      assert.equal(child.stderr, 'Main reporting observation result withheld.\n');
    }
    writeFileSync(path, JSON.stringify(fixture) + '\n');
    const valid = spawnSync(process.execPath, ['ops/audit/validate-main-reporting-lease.mjs', path], {encoding: 'utf8'});
    assert.equal(valid.status, 0);
    assert.equal(valid.stdout, JSON.stringify(fixture) + '\n');
    assert.equal(valid.stderr, '');
  } finally { rmSync(directory, {recursive: true}); }
});

test('guarded bootstrap preserves cached manifests and isolates provider cache writes', () => {
  for (const fragment of ['config_loaded_from_cache', 'getCachedPackagesPath', 'getCachedServicesPath',
    '$manifest->manifest = $packages', "$services['providers'] != $providers->collapse()->toArray()",
    "'logging.default' => 'null'", "'logging.deprecations.channel' => 'null'", "'app.debug' => false",
    "$config->set('cache.default', 'array')", "config(['cache.default' => 'array'])", 'SET TRANSACTION READ ONLY',
    'SCHEDULE_CACHE_DRIVER', 'SCHEDULE_CACHE_STORE', 'ConnectionEstablished::class', 'beforeStartingTransaction',
    'setReconnector', 'getRawPdo()', 'getRawReadPdo()', 'PDO::ATTR_PERSISTENT']) assert.ok(audit.includes(fragment), fragment);
  assert.ok(audit.indexOf('SET TRANSACTION READ ONLY') < audit.indexOf('->bootstrap()'));
  assert.ok(audit.indexOf('$db->rollBack();') < audit.indexOf("config(['cache' => $originalCache])"));
  assert.doesNotMatch(audit, /Artisan::call|schedule:run|shell_exec|system\s*\(|exec\s*\(/);
});

test('read-only next-transaction window suppresses only raw session SQL in callbacks', () => {
  assert.match(audit, /if \(\$sessionProbeEnabled && \$session !== null && \(string\) \$pdo->query/);
  assert.match(audit, /\$sessionProbeEnabled = false;\s*try \{\s*\$connection->statement\('SET TRANSACTION READ ONLY'\);\s*\$connection->beginTransaction\(\);\s*\} finally \{\s*\$sessionProbeEnabled = true;/);
  assert.match(audit, /HorusMainReportingLeaseObservation::beginReadOnlyTransaction\(\$db, \$sessionProbeEnabled\);\s*\$guard\(\);/);
  const guard = audit.slice(audit.indexOf('$guard = static function'), audit.indexOf('$app->afterBootstrapping'));
  assert.ok(guard.indexOf('assertSnapshot') < guard.indexOf('if ($sessionProbeEnabled'));
  assert.ok(guard.indexOf('getRawPdo()') < guard.indexOf('if ($sessionProbeEnabled'));
  assert.ok(guard.indexOf('getDefaultConnection()') < guard.indexOf('if ($sessionProbeEnabled'));
});

test('only the actual main cache expiration is read and reporting data and locks stay untouched', () => {
  assert.match(audit, /count\(\$events\) !== 1/);
  assert.match(audit, /CacheEventMutex/);
  assert.match(audit, /\$event->mutex->store \?\? \$originalScheduleStore/);
  assert.match(audit, /\$scheduleStore\['prefix'\] \?\? \$originalCache\['prefix'\]/);
  assert.match(audit, /\$db->table\(\$lockTable\)->where\('key', \$key\)->first\(\['expiration'\]\)/);
  assert.match(audit, /\$event->expiresAt/);
  assert.match(audit, /'lease_created_at' => 'UNPROVEN'/);
  assert.match(audit, /'original_lease_ttl' => 'UNPROVEN'/);
  assert.match(audit, /'lease_age_seconds' => 'UNKNOWN'/);
  assert.match(audit, /LEGACY_OR_DIFFERENT_DURATION/);
  assert.match(audit, /'remaining_seconds' => \$expiration === null \? null : max\(0, \$expiration - \$observedAt\)/);
  assert.doesNotMatch(audit, /GET_LOCK|RELEASE_LOCK|IS_USED_LOCK|forceRelease|restoreLock|->(?:acquire|release|lock|forget|delete|update|upsert|save|sync|lockForUpdate)\s*\(|Cache::|first\(\['owner'\]/);
  assert.doesNotMatch(audit, /table\(['"](?:sites|daily_reports|report_import_jobs|report_dimensions|site_gam)|gross_revenue|publisher_earnings|GamAdUnitReportClient/);
});

test('private observation uses null actor, bounded metadata, deterministic primary key and read-back verification', () => {
  for (const fragment of ['operations.main_reporting_lease_observed', 'auditId(', 'matchesExisting(',
    'UniqueConstraintViolationException', '$audit->id = $auditId', 'actor: null', 'request: new Illuminate\\Http\\Request',
    '$verified->metadata != $metadata', "'observed_release' => $snapshot['release']", "'expiration_utc'",
    "'expiry_age_seconds'", "'configured_schedule_ttl_seconds'", "'single_writer_inventory' => 'UNPROVEN'",
    "'nonmultiplexed_topology' => 'UNPROVEN'", 'TransactionCommitting::class', '$insertAvailable = false']) assert.ok(audit.includes(fragment), fragment);
  assert.equal((audit.match(/->record\(/g) || []).length, 1);
  assert.doesNotMatch(audit, /getMessage\(|var_dump|print_r|fwrite|file_put_contents|error_log\(/);
});

test('release guard permits only explicit known releases plus expected main and pins full marker and realpath', () => {
  for (const sha of ['5c3861f8ffdb97cec42c35476894e22b845beb3b', 'c1514c9daf9324a63b6b758c1068e1ab530766da', '4d3e0adbe403b45e8975fe7b1580d015e4ddcdb8']) assert.ok(audit.includes(sha));
  assert.match(audit, /self::snapshot\(\$link, \$expected\) !== \$snapshot/);
  assert.match(audit, /getcwd\(\) !== \$snapshot\['root'\]/);
  assert.match(audit, /clearstatcache\(true\)/);
  assert.match(audit, /realpath\(\$link\)/);
  assert.match(audit, /is_link\(\$root\.'\/\.horus-release'\)/);
  assert.doesNotMatch(audit + workflow, /ALLOWED_PREVIOUS|github.event.before/);
});

test('production workflow is main-only, protected, validated with PHP 8.4 and fixed known-host SSH', () => {
  assert.match(workflow, /github.event_name == 'push' && github.ref == 'refs\/heads\/main'/);
  assert.match(workflow, /needs: validate/);
  assert.match(workflow, /php-version: '8.4'/);
  assert.match(workflow, /php tests\/Deployment\/main-reporting-lease-observation-test.php/);
  assert.equal((workflow.match(/environment: production/g) || []).length, 1);
  assert.match(workflow, /StrictHostKeyChecking=yes/);
  assert.match(workflow, /HORUS_PRODUCTION_KNOWN_HOSTS/);
  assert.match(workflow, /cancel-in-progress: false/);
  assert.match(workflow, /GITHUB_REPOSITORY.*GITHUB_RUN_ID.*sha256sum/);
  assert.match(workflow, /cd -P/);
  assert.match(workflow, /display_errors=0 -d log_errors=0/);
  assert.match(workflow, /node ops\/audit\/validate-main-reporting-lease.mjs/);
  assert.doesNotMatch(workflow, /workflow_dispatch|workflow_run|pull_request_target|upload-artifact|GITHUB_RUN_ATTEMPT|set -x|ssh-keyscan|scp |artisan |--apply|schedule:clear-cache|cat .*main-lease|tee /);
});

const phpAvailable = spawnSync('php', ['--version']).status === 0;
test('standalone PHP fixtures execute when PHP is available (mandatory separately in CI)', {skip: !phpAvailable}, () => {
  const result = spawnSync('php', ['tests/Deployment/main-reporting-lease-observation-test.php'], {encoding: 'utf8'});
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stdout, 'Main reporting lease pure fixtures: PASS\n');
});
