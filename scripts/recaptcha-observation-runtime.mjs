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

    var RECAPTCHA_OBSERVATION_ACTION = 'horus_cf_pass';

    // Local, read-only explanation of the latest observation attempt for the
    // browser console: HorusMediaLoader.getRecaptchaObservation(). Nothing is
    // sent anywhere and neither the token nor the site key is ever recorded.
    function recaptchaObservationClock() {
        try {
            return window.performance && typeof window.performance.now === 'function'
                ? Math.round(window.performance.now()) : null;
        } catch (error) { return null; }
    }

    function recaptchaObservationError(error, siteKey) {
        var text = '';
        try { text = String(error && error.message || error || ''); } catch (ignored) {}
        if (siteKey) text = text.split(siteKey).join('<site-key>');
        text = text.replace(/[A-Za-z0-9_-]{60,}/g, '<redacted>').slice(0, 200);
        return text || null;
    }

    function recaptchaObservationNote(config, observation, phase, reason, error) {
        observation.phase = phase;
        observation.reason = reason || null;
        if (error !== undefined) observation.error = error;
        try {
            if (typeof log === 'function') log(config, 'reCAPTCHA observation', phase, reason || '', observation.error || '');
        } catch (ignored) {}
    }

    function recaptchaObservationDebugState() {
        try {
            var config = state.config;
            var options = config && config.recaptchaObservation;
            var enabled = Boolean(options && options.enabled === true);
            var observation = state.recaptchaObservation || state.recaptchaObservationSkip || null;
            var requests = { anchor: 0, reload: 0 };
            try {
                var entries = window.performance && typeof window.performance.getEntriesByType === 'function'
                    ? window.performance.getEntriesByType('resource') : [];
                Array.prototype.forEach.call(entries || [], function (entry) {
                    var name = String(entry && entry.name || '');
                    if (!/^https:\/\/www\.(google\.com|recaptcha\.net)\/recaptcha\//.test(name)) return;
                    if (name.indexOf('/anchor') !== -1) requests.anchor += 1;
                    if (name.indexOf('/reload') !== -1) requests.reload += 1;
                });
            } catch (ignored) {}
            return {
                enabled: enabled,
                phase: observation ? observation.phase : 'not_scheduled',
                reason: observation ? observation.reason || null : (enabled ? 'WAITING_FOR_CLOUDFLARE_PASS' : 'DISABLED_FOR_SITE'),
                error: observation && observation.error || null,
                tokenReceived: Boolean(observation && observation.tokenReceived === true),
                cloudflare: trafficGateRuntimeState().status,
                action: RECAPTCHA_OBSERVATION_ACTION,
                googleRequests: requests,
                timingsMs: observation && observation.timings ? {
                    scheduled: observation.timings.scheduled, started: observation.timings.started,
                    sdkLoaded: observation.timings.sdkLoaded, finished: observation.timings.finished
                } : null
            };
        } catch (error) {
            return { phase: 'unavailable', reason: 'DEBUG_STATE_FAILED' };
        }
    }

    function scheduleRecaptchaObservation(config) {
        try {
            var options = config && config.recaptchaObservation;
            if (!options || options.enabled !== true || state.recaptchaObservation) return;
            if (typeof options.siteKey !== 'string' || !/^[A-Za-z0-9_-]{20,200}$/.test(options.siteKey)) {
                state.recaptchaObservationSkip = { phase: 'not_started', reason: 'INVALID_SITE_KEY' };
                return;
            }
            var gateStatus = trafficGateRuntimeState().status;
            if (gateStatus !== TRAFFIC_GATE_STATES.passed) {
                // Intentional: observation runs only after Cloudflare PASS.
                state.recaptchaObservationSkip = { phase: 'not_started', reason: 'CLOUDFLARE_' + gateStatus };
                return;
            }
            var observation = { phase: 'scheduled', reason: null, error: null, tokenReceived: false,
                step: 'scheduled', timings: { scheduled: recaptchaObservationClock() } };
            state.recaptchaObservation = observation;
            state.recaptchaObservationSkip = null;
            function ineligibleReason() {
                if (state.config !== config) return 'CONFIG_REPLACED';
                if (config.recaptchaObservation.enabled !== true) return 'DISABLED_FOR_SITE';
                if (config.status !== 'active' || config.immediatePause) return 'SITE_PAUSED';
                if (!hostAllowed(currentHostname(), config.allowedHostnames)) return 'HOST_NOT_ALLOWED';
                var status = trafficGateRuntimeState().status;
                if (status !== TRAFFIC_GATE_STATES.passed) return 'CLOUDFLARE_' + status;
                if (window.__HM_RELEASE_HANDOFF_FAILED__) return 'LOADER_HANDOFF_FAILED';
                return null;
            }
            function start() {
                try {
                    observation.timings.started = recaptchaObservationClock();
                    var blocked = ineligibleReason();
                    if (blocked) { recaptchaObservationNote(config, observation, 'cancelled', blocked); return; }
                    // Do not replace or reconfigure a publisher's existing CAPTCHA.
                    if (window.grecaptcha || document.querySelector('script[src*="/recaptcha/"], .grecaptcha-badge')) {
                        recaptchaObservationNote(config, observation, 'publisher_recaptcha_present', 'PUBLISHER_RECAPTCHA_PRESENT');
                        return;
                    }
                    var sdk = document.createElement('script');
                    sdk.async = true;
                    sdk.fetchPriority = 'low';
                    sdk.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(options.siteKey);
                    sdk.setAttribute('data-hm-recaptcha-observation', '1');
                    observation.step = 'sdk_loading';
                    recaptchaObservationNote(config, observation, 'loading', null);
                    var stopBadgeObserver = hideObservationBadge(options.siteKey);
                    var expired = false;
                    var timeout = window.setTimeout(function () {
                        finish('timeout', { sdk_loading: 'SDK_LOAD_TIMEOUT', google_ready_wait: 'GOOGLE_READY_TIMEOUT',
                            executing: 'EXECUTE_TIMEOUT' }[observation.step] || 'TIMEOUT');
                    }, 10000);
                    function finish(phase, reason, error) {
                        if (expired) return;
                        expired = true;
                        window.clearTimeout(timeout);
                        sdk.onload = sdk.onerror = null;
                        stopBadgeObserver();
                        observation.timings.finished = recaptchaObservationClock();
                        recaptchaObservationNote(config, observation, phase, reason, error);
                    }
                    sdk.onerror = function () { finish('unavailable', 'SDK_NETWORK_ERROR'); };
                    sdk.onload = function () {
                        try {
                            observation.timings.sdkLoaded = recaptchaObservationClock();
                            var blockedOnLoad = expired ? 'EXPIRED' : ineligibleReason();
                            if (blockedOnLoad) { finish('cancelled', blockedOnLoad); return; }
                            var api = window.grecaptcha;
                            if (!api || typeof api.ready !== 'function' || typeof api.execute !== 'function') {
                                finish('unavailable', 'SDK_API_MISSING'); return;
                            }
                            observation.step = 'google_ready_wait';
                            api.ready(function () {
                                try {
                                    var blockedOnReady = expired ? 'EXPIRED' : ineligibleReason();
                                    if (blockedOnReady) { finish('cancelled', blockedOnReady); return; }
                                    observation.step = 'executing';
                                    recaptchaObservationNote(config, observation, 'executing', null);
                                    Promise.resolve(api.execute(options.siteKey, { action: RECAPTCHA_OBSERVATION_ACTION })).then(
                                        function (token) {
                                            // Token deliberately discarded: only whether one arrived is kept.
                                            observation.tokenReceived = typeof token === 'string' && token.length > 0;
                                            finish('completed', observation.tokenReceived ? null : 'EMPTY_TOKEN');
                                        },
                                        function (error) { finish('unavailable', 'EXECUTE_REJECTED', recaptchaObservationError(error, options.siteKey)); }
                                    );
                                } catch (error) { finish('unavailable', 'EXECUTE_THREW', recaptchaObservationError(error, options.siteKey)); }
                            });
                        } catch (error) { finish('unavailable', 'SDK_START_FAILED', recaptchaObservationError(error, options.siteKey)); }
                    };
                    (document.head || document.documentElement).appendChild(sdk);
                } catch (error) {
                    recaptchaObservationNote(config, observation, 'unavailable', 'START_FAILED', recaptchaObservationError(error, options.siteKey));
                }
            }
            // Give advertising startup first use of the main thread and network.
            window.setTimeout(function () {
                try {
                    if (typeof window.requestIdleCallback === 'function') {
                        window.requestIdleCallback(start, { timeout: 2000 });
                    } else start();
                } catch (error) { recaptchaObservationNote(config, observation, 'unavailable', 'SCHEDULE_FAILED'); }
            }, 1000);
        } catch (error) {} // Optional observation cannot reject a serving promise.
    }
`;
