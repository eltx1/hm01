import {readFileSync} from 'node:fs';
import {pathToFileURL} from 'node:url';

export function safeMainReportingLeaseResult(raw) {
  const keys = ['schema_version', 'observation', 'audit_status', 'lease_status', 'configuration_status', 'global_topology'];
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)
      || Object.keys(raw).length !== keys.length || keys.some(key => !Object.hasOwn(raw, key))
      || raw.schema_version !== 1 || raw.observation !== 'MAIN_REPORTING_LEASE' || raw.global_topology !== 'UNPROVEN') {
    throw new Error('INVALID_RESULT');
  }
  const choose = (value, allowed) => {
    if (!allowed.includes(value)) throw new Error('INVALID_RESULT');
    return value;
  };
  const unavailable = raw.audit_status === 'UNAVAILABLE';
  return {schema_version: 1, observation: 'MAIN_REPORTING_LEASE',
    audit_status: choose(raw.audit_status, ['RECORDED', 'ALREADY_RECORDED', 'UNAVAILABLE']),
    lease_status: choose(raw.lease_status, unavailable ? ['UNAVAILABLE'] : ['ACTIVE', 'EXPIRED', 'ABSENT']),
    configuration_status: choose(raw.configuration_status, unavailable ? ['UNAVAILABLE'] : ['PASS', 'FAIL']),
    global_topology: 'UNPROVEN'};
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const bytes = readFileSync(process.argv[2]);
    if (bytes.length > 1024) throw new Error('INVALID_RESULT');
    const rawText = bytes.toString('utf8');
    const raw = JSON.parse(rawText);
    const safe = safeMainReportingLeaseResult(raw);
    // Reject duplicate properties and alternate encodings; PHP emits this canonical envelope.
    if (rawText.trim() !== JSON.stringify(safe)) throw new Error('INVALID_RESULT');
    console.log(JSON.stringify(safe));
    if (safe.audit_status === 'UNAVAILABLE') process.exitCode = 1;
  } catch {
    console.error('Main reporting observation result withheld.');
    process.exitCode = 1;
  }
}
