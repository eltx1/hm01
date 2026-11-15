// Injected only as a small optional bootstrap. The observer is a separate asset.
export const performanceObservationRuntime = String.raw`
    function schedulePerformanceObservation(config) {
        try {
            var perf = config && config.observability && config.observability.performance;
            if (!perf || perf.enabled !== true || state.performanceObservationAttempted
                || state.config !== config || config.status !== 'active' || config.immediatePause
                || trafficGateRuntimeState().status !== TRAFFIC_GATE_STATES.passed
                || !hostAllowed(currentHostname(), config.allowedHostnames)
                || !Number.isInteger(perf.sampleBp) || perf.sampleBp < 1 || perf.sampleBp > 1000
                || perf.collectorOrigin !== 'https://metrics.horusmedia.net'
                || !/^https:\/\/cdn\.horusmedia\.net\/runtime\/performance\/hm-performance\.[a-f0-9]{16}\.js$/.test(perf.scriptUrl || '')
                || !window.crypto || typeof window.crypto.getRandomValues !== 'function') return;
            state.performanceObservationAttempted = true;
            var random = new Uint32Array(1); window.crypto.getRandomValues(random);
            if (random[0] / 4294967296 >= perf.sampleBp / 10000) return;
            var start = function () {
                try {
                    if (state.config !== config || config.status !== 'active' || config.immediatePause
                        || trafficGateRuntimeState().status !== TRAFFIC_GATE_STATES.passed) return;
                    var script = document.createElement('script');
                    script.src = perf.scriptUrl; script.async = true; script.referrerPolicy = 'no-referrer';
                    script.setAttribute('data-hm-performance-observer', '1');
                    (document.head || document.documentElement).appendChild(script);
                } catch (_) {}
            };
            window.setTimeout(function () {
                try {
                    if (typeof window.requestIdleCallback === 'function') window.requestIdleCallback(start, { timeout: 2000 });
                    else start();
                } catch (_) {}
            }, 1000);
        } catch (_) {}
    }
`;
