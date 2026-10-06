import {readFileSync} from 'node:fs';
export function safeSchedulerResult(raw) {
  if (raw?.schema_version !== 1 || raw?.diagnostic !== 'SCHEDULER_HEALTH') throw new Error('INVALID_RESULT');
  const choose = (v, options) => { if (!options.includes(v)) throw new Error('INVALID_RESULT'); return v; };
  const output = {schema_version: 1, diagnostic: 'SCHEDULER_HEALTH',
    scheduler_status: choose(raw.scheduler_status, ['MISSING', 'FRESH', 'STALE']),
    heartbeat_progress: choose(raw.heartbeat_progress, ['ADVANCING', 'NO_ADVANCE', 'FIRST_OBSERVED_TICK', 'UNAVAILABLE']),
    storage_status: choose(raw.storage_status, ['READY', 'NOT_WRITABLE']),
    bootstrap_cache_status: choose(raw.bootstrap_cache_status, ['READY', 'NOT_WRITABLE']),
    cron_status: choose(raw.cron_status, ['UNAVAILABLE', 'NO_USER_ENTRY', 'MULTIPLE', 'SINGLE_VALID_TARGET', 'INVALID_TARGET', 'UNCLASSIFIED']),
    mutex_statuses: {},
  };
  if (!raw.mutex_statuses || typeof raw.mutex_statuses !== 'object' || Array.isArray(raw.mutex_statuses)) throw new Error('INVALID_RESULT');
  for (const label of ['HEARTBEAT', 'VIDEO_SYNC', 'MAIN_SYNC', 'STATIC_DELIVERY']) {
    output.mutex_statuses[label] = choose(raw.mutex_statuses[label] ?? 'UNAVAILABLE', ['ABSENT', 'EXPIRED', 'ACTIVE', 'UNAVAILABLE']);
  }
  return output;
}
if (process.argv[1]?.endsWith('/validate-scheduler-incident.mjs')) {
  try {
    const bytes = readFileSync(process.argv[2]);
    if (bytes.length > 8192) throw new Error('INVALID_RESULT');
    console.log(JSON.stringify(safeSchedulerResult(JSON.parse(bytes))));
  } catch { console.error('Invalid scheduler result withheld.'); process.exitCode = 1; }
}
