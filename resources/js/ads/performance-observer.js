// Invoked by the optional sampled bootstrap, after verified PASS and ad startup.
// summarizeStartupTrace is bundled ahead of this file at build time.
(function () {
    'use strict';
    try {
        var api = window.HorusMediaLoader;
        var config = api && api.getConfig();
        var perf = config && config.observability && config.observability.performance;
        if (!perf || perf.enabled !== true || window.__HM_PERFORMANCE_SENT__
            || perf.collectorOrigin !== 'https://metrics.horusmedia.net'
            || !/^[A-Za-z0-9_-]{3,64}$/.test(config.siteKey || '')
            || !Number.isInteger(config.configVersion) || config.configVersion < 1 || config.configVersion > 1000000000
            || !Number.isInteger(perf.sampleBp) || perf.sampleBp < 1 || perf.sampleBp > 1000
            || !Array.isArray(config.allowedHostnames) || !config.allowedHostnames.includes(window.location.hostname.toLowerCase())) return;
        // Document-level ownership prevents duplicate inclusion from sending twice.
        window.__HM_PERFORMANCE_SENT__ = true;
        var started = Date.now();
        function eligible() {
            return api.getConfig() === config && config.status === 'active' && !config.immediatePause
                && api.getTrafficGateState().state === 'PASSED';
        }
        function inspect() {
            try {
                if (!eligible()) return;
                var summary = summarizeStartupTrace(api.getStartupTrace());
                // Truncated traces cannot establish this page's complete timing.
                if (summary.dropped > 0 || summary.cloudflare_outcome !== 'CF pass') return;
                var rendered = summary.slots.some(function (slot) { return slot.nonempty_render_recorded; });
                if (!rendered && Date.now() - started < 30000) { window.setTimeout(inspect, 1000); return; }
                var values = {
                    config: summary.timing_ms.config,
                    cloudflare_total: summary.timing_ms.cloudflare_total,
                    first_ad: summary.timing_ms.page_init_to_first_nonempty_render,
                    after_pass: summary.timing_ms.after_pass_to_first_request
                }, metrics = {};
                Object.keys(values).forEach(function (name) {
                    var value = values[name];
                    if (typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 60000) metrics[name] = Math.round(value);
                });
                // First-ad metrics currently describe GPT display slots only.
                // No GPT evidence is incomplete, never proof that ads failed.
                var outcome = rendered && Object.prototype.hasOwnProperty.call(metrics, 'first_ad') ? 'rendered'
                    : summary.slots.some(function (slot) { return slot.error_recorded; }) ? 'error'
                    : summary.slots.length > 0 && summary.slots.every(function (slot) { return slot.no_fill_recorded; }) ? 'empty' : 'incomplete';
                var pending = window.fetch(perf.collectorOrigin + '/collect/' + config.siteKey, {
                    method: 'POST', credentials: 'omit', mode: 'cors', cache: 'no-store', redirect: 'error',
                    referrerPolicy: 'no-referrer', keepalive: true, headers: { 'Content-Type': 'text/plain' },
                    body: JSON.stringify({schema: 1, configVersion: config.configVersion, sampleBp: perf.sampleBp, metrics: metrics, outcome: outcome})
                });
                if (pending && typeof pending.catch === 'function') pending.catch(function () {});
            } catch (_) {}
        }
        inspect();
    } catch (_) {}
})();
