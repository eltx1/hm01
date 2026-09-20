// Account operations only. Default is read-only; --apply is explicit and audited by CI.
import { readFile, appendFile } from 'node:fs/promises';
const apply = process.argv.includes('--apply');
const account = process.env.CLOUDFLARE_ACCOUNT_ID;
const token = process.env.CLOUDFLARE_API_TOKEN;
if (!account || !token) throw new Error('Cloudflare deployment credentials are missing');
const root = 'https://api.cloudflare.com/client/v4';
const accountPath = `/accounts/${encodeURIComponent(account)}`;
async function api(path, method = 'GET', body) {
    if (method !== 'GET' && !apply) throw new Error('Write attempted in dry-run');
    const response = await fetch(root + path, {
        method, redirect: 'error', signal: AbortSignal.timeout(30000),
        headers: { Authorization: `Bearer ${token}`, ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }) },
        ...(body ? { body: body instanceof FormData ? body : JSON.stringify(body) } : {}),
    });
    const data = await response.json();
    // Never include API payloads in exceptions: widget responses contain a secret.
    if (!response.ok || data.success !== true) throw new Error(`Cloudflare ${method} failed (${response.status}); codes=${(data.errors || []).map(item => item.code).join(',')}`);
    return data.result;
}
const widgets = await api(accountPath + '/challenges/widgets?per_page=100');
const candidates = widgets.filter(widget => widget.name === 'Horus Ad Traffic Gate' && widget.mode === 'invisible' && widget.domains?.includes('verify.horusmedia.net'));
if (candidates.length !== 1) throw new Error('Expected exactly one existing Horus Ad Traffic Gate widget');
const widget = await api(accountPath + '/challenges/widgets/' + encodeURIComponent(candidates[0].sitekey));
if (!widget.secret || widget.mode !== 'invisible' || !widget.domains?.includes('verify.horusmedia.net')) throw new Error('Existing widget is not ready');
const zones = await api('/zones?name=horusmedia.net');
if (zones.length !== 1 || zones[0].account?.id !== account) throw new Error('Expected the Horus zone in the configured account');
const domains = await api(accountPath + '/workers/domains');
const domain = domains.find(item => item.hostname === 'siteverify.horusmedia.net');
if (domain && domain.service !== 'siteverify') throw new Error('Custom domain already belongs to another Worker');
const scripts = await api(accountPath + '/workers/scripts');
const existing = scripts.find(item => item.id === 'siteverify');
if (existing) {
    const settings = await api(accountPath + '/workers/scripts/siteverify/settings');
    if (!settings.tags?.includes('horus-traffic-gate')) throw new Error('Refusing to overwrite an unrecognized siteverify Worker');
}
console.log('Readiness: existing invisible widget found; zone and Worker/domain ownership checked.');
if (apply) {
    const source = await readFile(new URL('../workers/siteverify/index.mjs', import.meta.url), 'utf8');
    const form = new FormData();
    form.set('metadata', new Blob([JSON.stringify({
        main_module: 'index.mjs', compatibility_date: '2026-09-01',
        tags: ['horus-traffic-gate'],
        bindings: [{ name: 'TURNSTILE_SECRET', type: 'secret_text', text: widget.secret }],
        observability: { enabled: false },
    })], { type: 'application/json' }));
    form.set('index.mjs', new Blob([source], { type: 'application/javascript+module' }), 'index.mjs');
    await api(accountPath + '/workers/scripts/siteverify', 'PUT', form);
    await api(accountPath + '/workers/scripts/siteverify/subdomain', 'POST', { enabled: false, previews_enabled: false });
    if (!domain) await api(accountPath + '/workers/domains', 'PUT', {
        hostname: 'siteverify.horusmedia.net', service: 'siteverify', environment: 'production', zone_id: zones[0].id,
    });
    const readback = await api(accountPath + '/workers/domains');
    if (!readback.some(item => item.hostname === 'siteverify.horusmedia.net' && item.service === 'siteverify')) throw new Error('Custom-domain readback failed');
    const settings = await api(accountPath + '/workers/scripts/siteverify/settings');
    if (!settings.bindings?.some(item => item.name === 'TURNSTILE_SECRET' && item.type === 'secret_text')) throw new Error('Secret binding readback failed');
}
const summary = `${apply ? 'Applied' : 'Dry-run passed'}: siteverify Worker + siteverify.horusmedia.net; existing widget reused; secret never written to files/logs; no WAF or publisher changes.\n`;
console.log(summary);
if (process.env.GITHUB_STEP_SUMMARY) await appendFile(process.env.GITHUB_STEP_SUMMARY, summary);
