// Prints the diagnostic only when it is the expected closed summary.
import {readFileSync} from 'node:fs';

const CODE = /^[A-Z][A-Z0-9_]{0,63}$/;
const COUNT = (value) => Number.isInteger(value) && value >= 0 && value < 1e9;
const KEYS = ['schema_version', 'diagnostic', 'reason', 'active_bindings', 'states', 'errors', 'google_accepted_format_dimension',
  'rows_last_14_days', 'formats_by_website_count', 'completed_days_compared_with_ledger', 'days_exact_match',
  'days_within_one_cent', 'days_different', 'class'];

export function validate(text) {
  const lines = text.trim().split('\n');
  if (lines.length !== 1) return null;
  let result;
  try { result = JSON.parse(lines[0]); } catch { return null; }
  if (!result || typeof result !== 'object' || Array.isArray(result)) return null;
  if (Object.keys(result).some((key) => !KEYS.includes(key))) return null;
  if (result.schema_version !== 1 || result.diagnostic !== 'AD_FORMAT_BREAKDOWN' || !CODE.test(result.reason ?? '')) return null;
  if ('class' in result && !/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(result.class)) return null;
  for (const key of ['states', 'errors', 'formats_by_website_count']) {
    if (key in result && (typeof result[key] !== 'object' || Array.isArray(result[key]) && result[key].length
      || Object.entries(result[key]).some(([name, count]) => !CODE.test(name) || !COUNT(count)))) return null;
  }
  for (const key of ['active_bindings', 'rows_last_14_days', 'completed_days_compared_with_ledger', 'days_exact_match', 'days_within_one_cent', 'days_different']) {
    if (key in result && !COUNT(result[key])) return null;
  }
  if ('google_accepted_format_dimension' in result && ![true, false, null].includes(result.google_accepted_format_dimension)) return null;
  return result;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  let text = '';
  try { text = readFileSync(process.argv[2], 'utf8'); } catch {}
  const result = validate(text);
  if (!result) {
    console.log(JSON.stringify({schema_version: 1, diagnostic: 'AD_FORMAT_BREAKDOWN', reason: 'RESULT_INVALID'}));
    process.exit(1);
  }
  console.log(JSON.stringify(result, null, 2));
  process.exit(result.reason === 'OK' ? 0 : 1);
}
