import {readFileSync} from 'node:fs';
export const mainRecoveryStages=['INPUTS','ENVIRONMENT_IDENTITY','RELEASE_MARKER','CRON_EVIDENCE','CRON_TARGET','BOOTSTRAP','BOOTSTRAP_CONFIG','BOOTSTRAP_MANIFEST','DATABASE','SCHEDULE','CACHE_CONFIGURATION','HOST_NAMESPACE','PROC_VISIBILITY','PROC_SCAN','READ_LEASE','LEASE_ELIGIBILITY','OBSERVATION_STABILITY','FINAL_RECHECK','COMPARE_AND_SWAP','COMPLETE'];
export function safeMainReportingRecovery(raw) {
  if (raw?.schema_version !== 1 || raw.target !== 'MAIN_SYNC_SCHEDULER_MUTEX'
      || !['preview','apply'].includes(raw.mode)
      || !['UNAVAILABLE','NO_ACTION','BLOCKED','ELIGIBLE','RELEASED'].includes(raw.status)
      || ![0,1].includes(raw.changed_rows) || !mainRecoveryStages.includes(raw.stage)
      || (raw.status === 'RELEASED') !== (raw.changed_rows === 1)
      || (raw.status === 'RELEASED' && raw.mode !== 'apply')
      || (raw.status === 'ELIGIBLE' && raw.mode !== 'preview')) throw new Error('INVALID_RESULT');
  return {schema_version:1,target:raw.target,mode:raw.mode,status:raw.status,changed_rows:raw.changed_rows,stage:raw.stage};
}
if (process.argv[1]?.endsWith('/validate-main-reporting-recovery.mjs')) {
  try {
    const bytes=readFileSync(process.argv[2]);
    if (bytes.length>2048) throw new Error();
    console.log(JSON.stringify(safeMainReportingRecovery(JSON.parse(bytes))));
  } catch { console.error('Main reporting recovery result unavailable. Raw output withheld.'); process.exitCode=1; }
}
