// Compiled into the Loader. Local diagnostic events only: no network, storage,
// tokens, nonces, URLs, account IDs, consent strings or raw provider errors.
export const startupTraceRuntime = String.raw`
    var STARTUP_PHASES = ['Horus init', 'CFG start', 'CFG ready', 'CFG wait', 'CFG error',
        'CF cold', 'CF prepare', 'CF prepared', 'CF adopt', 'CF transport', 'CF document loaded', 'CF start', 'CF ready', 'CF token', 'CF verify', 'CF retry', 'CF pass', 'CF reject', 'CF error', 'CF timeout',
        'Privacy ready', 'Privacy reject', 'Core handoff', 'Core ready', 'Core wait',
        'Horus start', 'Horus wait', 'GPT load', 'GPT ready', 'GPT call', 'GPT request', 'GPT response',
        'GPT render', 'GPT empty', 'GPT error', 'GPT fetched', 'GPT wait', 'GPT onload', 'Core error', 'VAST call', 'VAST no-fill', 'VAST error', 'Video start', 'Video content'];
    function startupTraceState() {
        if (!state.startupTrace) state.startupTrace = {
            events: [], seen: {}, dropped: 0, sequence: 0,
            origin: Date.now(), slots: typeof WeakMap === 'function' ? new WeakMap() : null,
            services: typeof WeakSet === 'function' ? new WeakSet() : null
        };
        return state.startupTrace;
    }
    function startupSlotNumber(slot, sameAs) {
        try {
            var trace = startupTraceState();
            if (!slot || (typeof slot !== 'object' && typeof slot !== 'function') || !trace.slots) return 0;
            if (sameAs && sameAs !== slot) {
                var shared = startupSlotNumber(sameAs);
                if (shared) trace.slots.set(slot, shared);
            }
            if (!trace.slots.has(slot)) trace.slots.set(slot, ++trace.sequence);
            return trace.slots.get(slot);
        } catch (error) { return 0; }
    }
    function startupTrace(phase, detail) {
        // Diagnostic failure must never change authorization or ad scheduling.
        try {
            if (STARTUP_PHASES.indexOf(phase) === -1) return;
            var trace = startupTraceState(), clean = {};
            detail = detail || {};
            ['slot', 'attempt', 'code'].forEach(function (key) {
                var value = detail[key];
                if (typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 1000000) clean[key] = Math.floor(value);
            });
            if (['binding', 'stale', 'detached', 'source', 'readiness_missing', 'pending_deadline', 'frame_error'].indexOf(detail.reason) !== -1) clean.reason = detail.reason;
            if (['ready', 'pending'].indexOf(detail.mode) !== -1) clean.mode = detail.mode;
            if (['config', 'controls', 'manifest', 'core', 'gpt', 'dom', 'privacy', 'policy'].indexOf(detail.resource) !== -1) clean.resource = detail.resource;
            if (['preroll', 'midroll', 'postroll'].indexOf(detail.position) !== -1) clean.position = detail.position;
            var key = phase + '|' + JSON.stringify(clean);
            if (trace.seen[key]) return;
            if (trace.events.length >= 160) { trace.dropped += 1; return; }
            trace.seen[key] = true;
            var now = window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now() - trace.origin;
            var event = Object.assign({ ms: Math.max(0, Math.round(now)), phase: phase }, clean);
            trace.events.push(event);
            if (window.console && typeof window.console.log === 'function') window.console.log('[HM] +' + event.ms + 'ms ' + phase, clean);
        } catch (error) { /* Local diagnostics are best effort only. */ }
    }
    function startupSnapshot() {
        try {
            var trace = startupTraceState();
            return { schema: 1, version: VERSION, build: 'startup-trace-2', runtimeBuild: 'source-inflight-1', dropped: trace.dropped,
                events: trace.events.map(function (entry) { return Object.assign({}, entry); }) };
        } catch (error) { return { schema: 1, events: [] }; }
    }
    function traceStartupWait(promise, resource, readyPhase, attempt) {
        // Observe a slow step without aborting/retrying it or releasing any gate.
        var timer = window.setTimeout(function () { startupTrace(resource === 'core' ? 'Core wait' : 'CFG wait', { resource: resource, attempt: attempt }); }, 5000);
        return promise.then(function (value) {
            window.clearTimeout(timer);
            if (readyPhase) startupTrace(readyPhase, { resource: resource, attempt: attempt });
            return value;
        }, function (error) {
            window.clearTimeout(timer);
            startupTrace('CFG error', { resource: resource, attempt: attempt });
            throw error;
        });
    }
    function traceGptService(service) {
        try {
            var trace = startupTraceState();
            if (!service || typeof service.addEventListener !== 'function' || !trace.services || trace.services.has(service)) return;
            trace.services.add(service);
            [['slotRequested', 'GPT request'], ['slotResponseReceived', 'GPT response'], ['slotRenderEnded', 'GPT render'], ['slotOnload', 'GPT onload']].forEach(function (pair) {
                service.addEventListener(pair[0], function (event) {
                    if (!event || !event.slot) return;
                    startupTrace(pair[0] === 'slotRenderEnded' && event.isEmpty ? 'GPT empty' : pair[1], { slot: startupSlotNumber(event.slot) });
                });
            });
        } catch (error) { /* Do not intercept/replace GPT, its queues or requests. */ }
    }
`;
