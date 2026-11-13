// Included in the compiled loader, but never part of the advertising promise chain.
export const recaptchaObservationRuntime = String.raw`
    function scheduleRecaptchaObservation(config) {
        try {
            var options = config && config.recaptchaObservation;
            if (!options || options.enabled !== true || typeof options.siteKey !== 'string'
                || !/^[A-Za-z0-9_-]{20,200}$/.test(options.siteKey)
                || trafficGateRuntimeState().status !== TRAFFIC_GATE_STATES.passed
                || state.recaptchaObservation) return;
            var observation = { phase: 'scheduled' };
            state.recaptchaObservation = observation;
            function eligible() {
                return state.config === config && config.recaptchaObservation.enabled === true
                    && config.status === 'active' && !config.immediatePause
                    && hostAllowed(currentHostname(), config.allowedHostnames)
                    && trafficGateRuntimeState().status === TRAFFIC_GATE_STATES.passed
                    && !window.__HM_RELEASE_HANDOFF_FAILED__;
            }
            function start() {
                try {
                    if (!eligible()) { observation.phase = 'cancelled'; return; }
                    // Do not replace or reconfigure a publisher's existing CAPTCHA.
                    if (window.grecaptcha || document.querySelector('script[src*="/recaptcha/"], .grecaptcha-badge')) {
                        observation.phase = 'publisher_recaptcha_present'; return;
                    }
                    var sdk = document.createElement('script');
                    sdk.async = true;
                    sdk.fetchPriority = 'low';
                    sdk.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(options.siteKey);
                    sdk.setAttribute('data-hm-recaptcha-observation', '1');
                    observation.phase = 'loading';
                    var expired = false;
                    var timeout = window.setTimeout(function () { finish('timeout'); }, 10000);
                    function finish(phase) {
                        if (expired) return;
                        expired = true;
                        window.clearTimeout(timeout);
                        sdk.onload = sdk.onerror = null;
                        observation.phase = phase;
                    }
                    sdk.onerror = function () { finish('unavailable'); };
                    sdk.onload = function () {
                        try {
                            if (expired || !eligible()) { finish('cancelled'); return; }
                            var api = window.grecaptcha;
                            if (!api || typeof api.ready !== 'function' || typeof api.execute !== 'function') {
                                finish('unavailable'); return;
                            }
                            api.ready(function () {
                                try {
                                    if (expired || !eligible()) { finish('cancelled'); return; }
                                    observation.phase = 'executing';
                                    Promise.resolve(api.execute(options.siteKey, { action: 'horus_cf_pass' })).then(
                                        function () { finish('completed'); },
                                        function () { finish('unavailable'); }
                                    ); // Token deliberately discarded: no storage, POST or verification.
                                } catch (error) { finish('unavailable'); }
                            });
                        } catch (error) { finish('unavailable'); }
                    };
                    (document.head || document.documentElement).appendChild(sdk);
                } catch (error) { observation.phase = 'unavailable'; }
            }
            // Give advertising startup first use of the main thread and network.
            window.setTimeout(function () {
                try {
                    if (typeof window.requestIdleCallback === 'function') {
                        window.requestIdleCallback(start, { timeout: 2000 });
                    } else start();
                } catch (error) { observation.phase = 'unavailable'; }
            }, 1000);
        } catch (error) {} // Optional observation cannot reject a serving promise.
    }
`;
