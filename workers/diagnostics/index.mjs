// Independent observational endpoint. Never imports or changes the admission Worker.
const REGISTRY = 'https://cdn.horusmedia.net/configs/';
const METRICS = ['config', 'cloudflare_total', 'first_ad', 'after_pass'];
async function readJson(message, maxBytes, timeoutMs = 1500) {
    if (Number(message.headers.get('Content-Length') || 0) > maxBytes) throw new Error('BODY_LIMIT');
    const reader = message.body?.getReader();
    if (!reader) throw new Error('BODY_INVALID');
    let timer, size = 0; const chunks = [];
    const timeout = new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('BODY_TIMEOUT')), timeoutMs); });
    try {
        while (true) {
            const part = await Promise.race([reader.read(), timeout]);
            if (part.done) break;
            size += part.value.byteLength;
            if (size > maxBytes) throw new Error('BODY_LIMIT');
            chunks.push(part.value);
        }
        const bytes = new Uint8Array(size); let offset = 0;
        for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
        return JSON.parse(new TextDecoder().decode(bytes));
    } finally {
        clearTimeout(timer);
        try { const cancelled = reader.cancel(); cancelled?.catch?.(() => {}); } catch {}
    }
}
function families(agent) {
    const device = /Android/i.test(agent) ? 'android' : /iPhone|iPad|iPod/i.test(agent) ? 'ios' : /Windows|Macintosh|X11|Linux/i.test(agent) ? 'desktop' : 'other';
    const browser = /Edg\//i.test(agent) ? 'edge' : /Firefox|FxiOS/i.test(agent) ? 'firefox' : /Chrome|CriOS/i.test(agent) ? 'chrome' : /Safari/i.test(agent) ? 'safari' : 'other';
    return {device, browser};
}
export function createCollector({upstream = fetch, now = Date.now, bodyTimeoutMs = 1500} = {}) {
    const registry = new Map(), limits = new Map();
    return async function collect(request, env) {
        const headers = {'Content-Type': 'application/json', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff', 'Vary': 'Origin'};
        const respond = (status, code) => new Response(JSON.stringify({code}), {status, headers});
        const url = new URL(request.url);
        if (url.pathname === '/health' && request.method === 'GET') return respond(200, typeof env.HORUS_DIAGNOSTICS?.writeDataPoint === 'function' ? 'CONFIGURED' : 'NOT_CONFIGURED');
        const match = /^\/collect\/([A-Za-z0-9_-]{3,64})$/.exec(url.pathname);
        if (!match || url.search) return respond(404, 'NOT_FOUND');
        if (!['POST', 'OPTIONS'].includes(request.method)) return respond(405, 'METHOD_NOT_ALLOWED');
        const siteKey = match[1], origin = request.headers.get('Origin');
        let parsed;
        try {
            parsed = new URL(origin);
            if (!['https:', 'http:'].includes(parsed.protocol) || parsed.origin !== origin || parsed.port) return respond(403, 'ORIGIN_REJECTED');
        } catch { return respond(403, 'ORIGIN_REJECTED'); }
        if (typeof env.HORUS_DIAGNOSTICS?.writeDataPoint !== 'function') return respond(503, 'NOT_CONFIGURED');
        // Best-effort per-isolate abuse bound only; never a visitor/admission identifier.
        const key = request.headers.get('CF-Connecting-IP') || 'unknown';
        const limit = limits.get(key);
        if (limit && limit.until > now() && limit.count >= 10) return respond(429, 'RATE_LIMITED');
        if (!limit || limit.until <= now()) limits.set(key, {until: now()+60000, count: 1});
        else limit.count++;
        if (limits.size > 512) limits.delete(limits.keys().next().value);
        let entry = registry.get(siteKey);
        if (!entry || entry.until <= now()) {
            const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 2000);
            try {
                const response = await upstream(REGISTRY+siteKey+'/production.json', {redirect: 'manual', signal: controller.signal, headers: {Accept: 'application/json'}});
                if (!response.ok) return respond(503, 'REGISTRY_UNAVAILABLE');
                const config = await readJson(response, 524288, bodyTimeoutMs);
                const perf = config?.observability?.performance;
                entry = {until: now()+30000,
                    enabled: config?.siteKey === siteKey && config.status === 'active' && !config.immediatePause && perf?.enabled === true,
                    sampleBp: perf?.sampleBp, version: config?.configVersion,
                    hosts: Array.isArray(config?.allowedHostnames) ? config.allowedHostnames.filter(h => typeof h === 'string').slice(0, 50) : []};
                if (!Number.isInteger(entry.sampleBp) || entry.sampleBp < 1 || entry.sampleBp > 1000 || !Number.isInteger(entry.version) || entry.version < 1 || entry.version > 1000000000) entry.enabled = false;
                registry.set(siteKey, entry);
                if (registry.size > 256) registry.delete(registry.keys().next().value);
            } catch { return respond(503, 'REGISTRY_UNAVAILABLE'); }
            finally { clearTimeout(timer); }
        }
        if (!entry.enabled || !entry.hosts.includes(parsed.hostname.toLowerCase())) return respond(403, 'SITE_NOT_ENABLED');
        headers['Access-Control-Allow-Origin'] = origin;
        if (request.method === 'OPTIONS') {
            headers['Access-Control-Allow-Methods'] = 'POST'; headers['Access-Control-Allow-Headers'] = 'Content-Type';
            headers['Access-Control-Max-Age'] = '600'; return new Response(null, {status: 204, headers});
        }
        if (!/^(?:text\/plain|application\/json)(?:;|$)/i.test(request.headers.get('Content-Type') || '')) return respond(415, 'CONTENT_TYPE_REJECTED');
        let body;
        try { body = await readJson(request, 2048, bodyTimeoutMs); }
        catch (error) { return respond(error.message === 'BODY_TIMEOUT' ? 408 : error.message === 'BODY_LIMIT' ? 413 : 400, 'BODY_REJECTED'); }
        if (!body || Array.isArray(body) || body.schema !== 1 || Object.keys(body).some(k => !['schema', 'metrics', 'outcome', 'configVersion', 'sampleBp'].includes(k))
            || !body.metrics || Array.isArray(body.metrics) || typeof body.metrics !== 'object'
            || !['rendered', 'empty', 'incomplete', 'error'].includes(body.outcome)) return respond(400, 'SCHEMA_REJECTED');
        if (body.configVersion !== entry.version || body.sampleBp !== entry.sampleBp) return respond(409, 'STALE_CONFIGURATION');
        const values = Object.entries(body.metrics);
        if (values.some(([name, ms]) => !METRICS.includes(name) || !Number.isInteger(ms) || ms < 0 || ms > 60000)
            || (body.outcome === 'rendered' && !Object.hasOwn(body.metrics, 'first_ad'))) return respond(400, 'SCHEMA_REJECTED');
        const {device, browser} = families(request.headers.get('User-Agent') || '');
        const weight = 10000/entry.sampleBp;
        // Only bounded dimensions/numbers reach Analytics Engine. No URL, IP, token,
        // nonce, raw UA, ad identifiers or event stream ever reaches Laravel.
        const point = (metric, ms) => {
            const pending = env.HORUS_DIAGNOSTICS.writeDataPoint({indexes: [siteKey], blobs: [siteKey, device, browser, metric, String(entry.version)], doubles: [ms, weight]});
            pending?.catch?.(() => {});
        };
        try {
            point('pages', 0); point('outcome:'+body.outcome, 0);
            for (const [name, ms] of values) point(name, ms);
        } catch { return respond(503, 'WRITE_UNAVAILABLE'); }
        return respond(200, 'RECORDED_CLIENT_OBSERVATION');
    };
}
const collector = createCollector();
export default {fetch: (request, env) => collector(request, env)};
