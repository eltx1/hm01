// Included in the compiled loader, but never part of the advertising promise chain.
export const recaptchaObservationRuntime = String.raw`
    function hideObservationBadge(siteKey) {
        var observer = null;
        // Keep a key-scoped CSS rule for late SDK responses and badge recreation.
        // The observer below also covers engines without :has support at startup.
        var style = document.createElement('style');
        style.setAttribute('data-hm-recaptcha-badge-style', '1');
        var selectors = [];
        ['https://www.google.com/recaptcha/', 'https://www.recaptcha.net/recaptcha/'].forEach(function (origin) {
            selectors.push('.grecaptcha-badge:has(iframe[src^="' + origin + '"][src*="k=' + siteKey + '&"])');
            selectors.push('.grecaptcha-badge:has(iframe[src^="' + origin + '"][src$="k=' + siteKey + '"])');
        });
        style.textContent = selectors.join(',') + '{visibility:hidden!important}';
        (document.head || document.documentElement).appendChild(style);
        function inspect(node) {
            if (!node || node.nodeType !== 1) return;
            var frames = node.tagName === 'IFRAME' ? [node] : node.querySelectorAll('iframe');
            for (var i = 0; i < frames.length; i++) {
                var frame = frames[i];
                try {
                    var url = new URL(frame.src);
                    if ((url.hostname !== 'www.google.com' && url.hostname !== 'www.recaptcha.net')
                        || url.pathname.indexOf('/recaptcha/') !== 0 || url.searchParams.get('k') !== siteKey) continue;
                    var badge = frame.closest('.grecaptcha-badge');
                    if (!badge) continue;
                    badge.style.setProperty('visibility', 'hidden', 'important');
                    badge.setAttribute('data-hm-recaptcha-badge', 'hidden');
                } catch (error) {}
            }
        }
        // Watch only during SDK startup. Match the exact public site key, never
        // hide publisher-owned widgets with a blanket .grecaptcha-badge rule.
        if (window.MutationObserver && document.body) {
            observer = new window.MutationObserver(function (records) {
                try {
                    records.forEach(function (record) {
                        if (record.type === 'attributes') inspect(record.target);
                        else Array.prototype.forEach.call(record.addedNodes || [], inspect);
                    });
                } catch (error) {}
            });
            observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
        }
        return function () {
            try { inspect(document.body); } catch (error) {}
            if (observer) observer.disconnect();
        };
    }

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
                    var stopBadgeObserver = hideObservationBadge(options.siteKey);
                    var expired = false;
                    var timeout = window.setTimeout(function () { finish('timeout'); }, 10000);
                    function finish(phase) {
                        if (expired) return;
                        expired = true;
                        window.clearTimeout(timeout);
                        sdk.onload = sdk.onerror = null;
                        stopBadgeObserver();
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
