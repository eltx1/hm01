// Prints the reCAPTCHA observation diagnostic only when it is the expected closed summary.
import {readFileSync} from 'node:fs';

const CODE = /^[A-Z][A-Z0-9_]{0,63}$/;
const BOOL = (value) => value === true || value === false;
const NULLABLE_BOOL = (value) => value === null || BOOL(value);
const NULLABLE_CODE = (value) => value === null || (typeof value === 'string' && CODE.test(value));
const KEYS = ['schema_version', 'diagnostic', 'reason', 'enabled_sites', 'sites', 'class'];
const SITE_KEYS = ['site_active', 'config_active', 'cloudflare_gate_enabled', 'cloudflare_gate_readiness', 'saved_key_valid',
  'published_enabled', 'published_key_valid', 'published_key_matches_saved', 'published_delivery', 'page'];
const PAGE_KEYS = ['checked', 'http', 'has_horus_loader', 'has_publisher_recaptcha'];

function validSite(site) {
  if (!site || typeof site !== 'object' || Array.isArray(site)) return false;
  if (Object.keys(site).some((key) => !SITE_KEYS.includes(key)) || SITE_KEYS.some((key) => !(key in site))) return false;
  if (!BOOL(site.site_active) || !BOOL(site.config_active) || !BOOL(site.saved_key_valid)) return false;
  for (const key of ['cloudflare_gate_enabled', 'published_enabled', 'published_key_valid', 'published_key_matches_saved']) if (!NULLABLE_BOOL(site[key])) return false;
  if (!NULLABLE_CODE(site.cloudflare_gate_readiness) || !NULLABLE_CODE(site.published_delivery)) return false;
  const page = site.page;
  if (!page || typeof page !== 'object' || Array.isArray(page) || Object.keys(page).some((key) => !PAGE_KEYS.includes(key))) return false;
  return BOOL(page.checked) && NULLABLE_CODE(page.http) && NULLABLE_BOOL(page.has_horus_loader) && NULLABLE_BOOL(page.has_publisher_recaptcha);
}

export function validate(text) {
  const lines = text.trim().split('\n');
  if (lines.length !== 1) return null;
  let result;
  try { result = JSON.parse(lines[0]); } catch { return null; }
  if (!result || typeof result !== 'object' || Array.isArray(result)) return null;
  if (Object.keys(result).some((key) => !KEYS.includes(key))) return null;
  if (result.schema_version !== 1 || result.diagnostic !== 'RECAPTCHA_OBSERVATION' || !CODE.test(result.reason ?? '')) return null;
  if ('class' in result && !/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(result.class)) return null;
  if ('enabled_sites' in result && !(Number.isInteger(result.enabled_sites) && result.enabled_sites >= 0 && result.enabled_sites <= 50)) return null;
  if ('sites' in result && (!Array.isArray(result.sites) || result.sites.length > 50 || !result.sites.every(validSite))) return null;
  return result;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  let text = '';
  try { text = readFileSync(process.argv[2], 'utf8'); } catch {}
  const result = validate(text);
  if (!result) {
    console.log(JSON.stringify({schema_version: 1, diagnostic: 'RECAPTCHA_OBSERVATION', reason: 'RESULT_INVALID'}));
    process.exit(1);
  }
  console.log(JSON.stringify(result, null, 2));
  process.exit(result.reason === 'OK' ? 0 : 1);
}
