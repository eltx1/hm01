import {readFileSync} from 'node:fs';
export function safeCurrentMainRefresh(raw) {
  if (raw?.schema_version!==1 || raw.operation!=='CURRENT_MAIN_ESTIMATES'
      || !['ELIGIBLE','PROGRESS','PENDING','COMPLETED','BLOCKED','UNAVAILABLE'].includes(raw.status)) throw new Error('INVALID_RESULT');
  return {schema_version:1,operation:'CURRENT_MAIN_ESTIMATES',status:raw.status};
}
if (process.argv[1]?.endsWith('/validate-current-main-refresh.mjs')) {
  try { const bytes=readFileSync(process.argv[2]); if(bytes.length>2048)throw new Error(); console.log(JSON.stringify(safeCurrentMainRefresh(JSON.parse(bytes)))); }
  catch { console.error('Current main refresh result unavailable. Raw output withheld.'); process.exitCode=1; }
}
