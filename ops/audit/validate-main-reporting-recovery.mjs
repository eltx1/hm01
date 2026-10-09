import {readFileSync} from 'node:fs';
export function safeMainReportingRecovery(raw) {
  if (raw?.schema_version !== 1 || raw.target !== 'MAIN_SYNC_SCHEDULER_MUTEX'
      || !['preview','apply'].includes(raw.mode)
      || !['UNAVAILABLE','NO_ACTION','BLOCKED','ELIGIBLE','RELEASED'].includes(raw.status)
      || ![0,1].includes(raw.changed_rows)
      || (raw.status === 'RELEASED') !== (raw.changed_rows === 1)
      || (raw.status === 'RELEASED' && raw.mode !== 'apply')
      || (raw.status === 'ELIGIBLE' && raw.mode !== 'preview')) throw new Error('INVALID_RESULT');
  return {schema_version:1,target:raw.target,mode:raw.mode,status:raw.status,changed_rows:raw.changed_rows};
}
if (process.argv[1]?.endsWith('/validate-main-reporting-recovery.mjs')) {
  try {
    const bytes=readFileSync(process.argv[2]);
    if (bytes.length>2048) throw new Error();
    console.log(JSON.stringify(safeMainReportingRecovery(JSON.parse(bytes))));
  } catch { console.error('Main reporting recovery result unavailable. Raw output withheld.'); process.exitCode=1; }
}
