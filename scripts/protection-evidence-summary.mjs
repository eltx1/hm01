// Manual, local diagnostic samples. These are never admission or traffic totals.
const DEVICES = ['android', 'ios', 'desktop', 'other'];
const BROWSERS = ['chrome', 'safari', 'firefox', 'edge', 'other'];
const TERMINAL = {'CF pass': 'reported_pass', 'CF reject': 'reported_reject', 'CF timeout': 'timeout', 'CF error': 'error'};
const PENDING = ['CF cold', 'CF prepare', 'CF start', 'CF token', 'CF verify', 'CF retry'];

export function summarizeProtectionEvidence(input) {
    if (!input || input.schema !== 1 || !Array.isArray(input.samples) || input.samples.length < 1 || input.samples.length > 100) throw new Error('INVALID_BATCH');
    const groups = new Map();
    for (const sample of input.samples) {
        if (!sample || typeof sample.site !== 'string' || !/^[A-Za-z0-9_-]{3,64}$/.test(sample.site) || !sample.trace || sample.trace.schema !== 1
            || !Array.isArray(sample.trace.events) || sample.trace.events.length > 160) throw new Error('INVALID_SAMPLE');
        const device = DEVICES.includes(sample.device) ? sample.device : 'unknown';
        const browser = BROWSERS.includes(sample.browser) ? sample.browser : 'unknown';
        // Do not infer capture date from relative startup milliseconds or render time.
        let day = 'undated';
        if (typeof sample.capturedAt === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/.test(sample.capturedAt)) {
            const time = Date.parse(sample.capturedAt);
            if (Number.isFinite(time) && new Date(time).toISOString().slice(0, 19) === sample.capturedAt.slice(0, 19)) day = new Date(time).toISOString().slice(0, 10);
        }
        const key = JSON.stringify([sample.site, device, browser, day]);
        if (!groups.has(key)) groups.set(key, {site: sample.site, device, browser, capture_day_utc: day, snapshots: 0,
            cloudflare: {reported_pass: 0, reported_reject: 0, timeout: 0, error: 0, pending: 0, unknown: 0},
            click_guard: {reported_blocked: 0, reported_clear: 0, disabled: 0, unknown: 0}});
        const group = groups.get(key);
        group.snapshots++;
        let outcome = 'unknown';
        // Trace events are deduplicated and bounded. Count snapshot states, never
        // retries/attempts, unique people or failures per visitor. A later retry
        // makes a previous rejection provisional in this submitted snapshot.
        if (sample.trace.dropped === undefined || sample.trace.dropped === 0) {
            for (const event of sample.trace.events) {
                if (!event || typeof event !== 'object') continue;
                if (Object.hasOwn(TERMINAL, event.phase)) outcome = TERMINAL[event.phase];
                else if (PENDING.includes(event.phase)) outcome = 'pending';
            }
        }
        group.cloudflare[outcome]++;
        const guard = sample.clickGuard;
        const guardState = guard?.enabled === false ? 'disabled' : guard?.enabled === true && guard.blocked === true
            ? 'reported_blocked' : guard?.enabled === true && guard.blocked === false ? 'reported_clear' : 'unknown';
        group.click_guard[guardState]++;
    }
    return {schema: 1, source: 'Manually submitted client snapshots; unauthenticated diagnostic evidence only.',
        scope: 'Each denominator is included snapshots in that group, not requests, visitors or sampled production traffic. No fraud rate or verified PASS is derived.',
        coverage: 'Selection, duplicate submissions, missing devices, dropped traces and capture dates are uncontrolled. Day groups are comparisons, not an anomaly detector.',
        included_snapshots: input.samples.length, groups: [...groups.values()].sort((a, b) => JSON.stringify([a.site, a.device, a.browser, a.capture_day_utc]).localeCompare(JSON.stringify([b.site, b.device, b.browser, b.capture_day_utc])))};
}
