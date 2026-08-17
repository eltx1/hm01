(() => {
    'use strict';

    const PROTOCOL_VERSION = 1;
    const GATE_ORIGIN = 'https://verify.horusmedia.net';
    const ADMIN_ORIGIN = 'https://app.horusmedia.net';
    const TURNSTILE_SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    const PROVIDER = 'CLOUDFLARE_TURNSTILE_CLIENT_ONLY';
    const MAX_RETRIES = 1;
    const DEFAULT_TEST_TIMINGS = Object.freeze({
        initialWaitMs: 1500,
        maxWaitMs: 6000,
        retryIntervalMs: 1500,
    });

    const TYPES = Object.freeze({
        hello: 'HORUS_TRAFFIC_GATE_HELLO',
        ready: 'HORUS_TRAFFIC_GATE_READY',
        pass: 'HORUS_TRAFFIC_GATE_PASS',
        error: 'HORUS_TRAFFIC_GATE_ERROR',
        timeout: 'HORUS_TRAFFIC_GATE_TIMEOUT',
        denied: 'HORUS_TRAFFIC_GATE_DENIED',
    });

    const STATES = Object.freeze({
        booting: 'BOOTING',
        parentValidated: 'PARENT_VALIDATED',
        turnstileLoading: 'TURNSTILE_LOADING',
        challengeRunning: 'CHALLENGE_RUNNING',
        passed: 'PASSED',
        error: 'ERROR',
        timeout: 'TIMEOUT',
        denied: 'DENIED',
    });

    let state = STATES.booting;
    let boundParent = null;
    let widgetId = null;
    let challengeTimer = null;
    let retryTimer = null;
    let retries = 0;
    let terminal = false;

    const widgetContainer = document.getElementById('horus-turnstile');

    function setState(next) {
        state = next;
        if (document.documentElement?.dataset) {
            document.documentElement.dataset.gateState = next;
        }
    }

    function validPageNonce(value) {
        return typeof value === 'string' && /^[A-Za-z0-9_-]{16,128}$/.test(value);
    }

    function validSitePublicKey(value) {
        return typeof value === 'string' && /^[A-Za-z0-9_-]{3,64}$/.test(value);
    }

    function validTurnstileSiteKey(value) {
        return typeof value === 'string' && /^[A-Za-z0-9_-]{3,255}$/.test(value);
    }

    function parsedHttpsOrigin(origin) {
        try {
            const url = new URL(origin);
            if (url.protocol !== 'https:' || url.origin !== origin || url.username || url.password) {
                return null;
            }

            return url;
        } catch {
            return null;
        }
    }

    function safeErrorCode(value) {
        const code = String(value ?? 'TURNSTILE_ERROR');
        return /^[A-Za-z0-9_-]{1,32}$/.test(code) ? code : 'TURNSTILE_ERROR';
    }

    function post(type, extra = {}) {
        if (!boundParent) {
            return;
        }

        const message = {
            type,
            protocolVersion: PROTOCOL_VERSION,
            pageNonce: boundParent.pageNonce,
            state,
            ...extra,
        };
        boundParent.source.postMessage(message, boundParent.origin);
    }

    function clearTimers() {
        if (challengeTimer !== null) {
            clearTimeout(challengeTimer);
            challengeTimer = null;
        }
        if (retryTimer !== null) {
            clearTimeout(retryTimer);
            retryTimer = null;
        }
    }

    function removeWidget() {
        if (widgetId === null || typeof window.turnstile?.remove !== 'function') {
            return;
        }

        try {
            window.turnstile.remove(widgetId);
        } catch {
            // Best-effort cleanup only. No exception detail leaves this frame.
        }
        widgetId = null;
    }

    function finish(type, nextState, extra = {}) {
        if (terminal) {
            return;
        }
        terminal = true;
        clearTimers();
        setState(nextState);
        post(type, extra);
        removeWidget();
    }

    function deny(category = 'UNAUTHORIZED_PARENT') {
        finish(TYPES.denied, STATES.denied, { category });
    }

    function fail(category = 'TURNSTILE_ERROR', code = null) {
        finish(TYPES.error, STATES.error, {
            category,
            ...(code ? { code: safeErrorCode(code) } : {}),
        });
    }

    function timeout(category = 'TURNSTILE_TIMEOUT') {
        finish(TYPES.timeout, STATES.timeout, { category });
    }

    function validTimings(timings) {
        if (!timings || typeof timings !== 'object') {
            return null;
        }

        const initialWaitMs = Number(timings.initialWaitMs);
        const maxWaitMs = Number(timings.maxWaitMs);
        const retryIntervalMs = Number(timings.retryIntervalMs);
        if (!Number.isInteger(initialWaitMs) || initialWaitMs < 500 || initialWaitMs > 5000) return null;
        if (!Number.isInteger(maxWaitMs) || maxWaitMs < 2000 || maxWaitMs > 15000) return null;
        if (!Number.isInteger(retryIntervalMs) || retryIntervalMs < 500 || retryIntervalMs > 10000) return null;
        if (maxWaitMs < initialWaitMs) return null;

        return { initialWaitMs, maxWaitMs, retryIntervalMs };
    }

    function originAuthorized(config, parentUrl) {
        if (!config || config.siteKey !== boundParent.sitePublicKey || !Array.isArray(config.allowedHostnames)) {
            return false;
        }

        const allowed = config.allowedHostnames
            .filter((hostname) => typeof hostname === 'string')
            .map((hostname) => hostname.trim().toLowerCase())
            .filter((hostname) => /^[a-z0-9.-]+$/.test(hostname));

        return allowed.includes(parentUrl.hostname.toLowerCase());
    }

    async function loadSiteConfiguration(sitePublicKey) {
        const response = await fetch(`/configs/${encodeURIComponent(sitePublicKey)}/production.json`, {
            method: 'GET',
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'error',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return null;
        }

        const config = await response.json();
        return config && typeof config === 'object' ? config : null;
    }

    function loadTurnstileScript() {
        if (typeof window.turnstile?.render === 'function') {
            return Promise.resolve();
        }

        setState(STATES.turnstileLoading);
        return new Promise((resolve, reject) => {
            const existing = document.getElementById('horus-turnstile-api');
            if (existing) {
                existing.addEventListener('load', () => {
                    typeof window.turnstile?.render === 'function' ? resolve() : reject(new Error('TURNSTILE_API_UNAVAILABLE'));
                }, { once: true });
                existing.addEventListener('error', () => reject(new Error('TURNSTILE_SCRIPT_ERROR')), { once: true });
                return;
            }

            const script = document.createElement('script');
            script.id = 'horus-turnstile-api';
            script.src = TURNSTILE_SCRIPT_URL;
            script.async = true;
            script.defer = true;
            script.addEventListener('load', () => {
                typeof window.turnstile?.render === 'function' ? resolve() : reject(new Error('TURNSTILE_API_UNAVAILABLE'));
            }, { once: true });
            script.addEventListener('error', () => reject(new Error('TURNSTILE_SCRIPT_ERROR')), { once: true });
            document.head.appendChild(script);
        });
    }

    function handleTurnstileError(errorCode, timings) {
        if (terminal) {
            return;
        }

        if (retries < MAX_RETRIES && widgetId !== null && typeof window.turnstile?.reset === 'function') {
            retries += 1;
            retryTimer = setTimeout(() => {
                retryTimer = null;
                if (terminal) return;
                try {
                    window.turnstile.reset(widgetId);
                } catch {
                    fail('TURNSTILE_RESET_ERROR');
                }
            }, timings.retryIntervalMs);
            return;
        }

        fail('TURNSTILE_ERROR', errorCode);
    }

    function renderTurnstile(siteKey, timings, testMode) {
        if (!widgetContainer || typeof window.turnstile?.render !== 'function') {
            fail('TURNSTILE_API_UNAVAILABLE');
            return;
        }

        setState(STATES.challengeRunning);
        post(TYPES.ready, { provider: PROVIDER, testMode });
        challengeTimer = setTimeout(() => timeout('GATE_MAX_WAIT'), timings.maxWaitMs);

        try {
            widgetId = window.turnstile.render(widgetContainer, {
                sitekey: siteKey,
                execution: 'render',
                retry: 'never',
                'response-field': false,
                callback: () => finish(TYPES.pass, STATES.passed, { provider: PROVIDER }),
                'error-callback': (errorCode) => handleTurnstileError(errorCode, timings),
                'timeout-callback': () => timeout('TURNSTILE_TIMEOUT'),
                'unsupported-callback': () => fail('UNSUPPORTED_BROWSER'),
            });
        } catch {
            fail('TURNSTILE_RENDER_ERROR');
        }
    }

    async function startNormalMode(parentUrl) {
        let config;
        try {
            config = await loadSiteConfiguration(boundParent.sitePublicKey);
        } catch {
            fail('STATIC_CONFIG_UNAVAILABLE');
            return;
        }

        if (!config || !originAuthorized(config, parentUrl)) {
            deny();
            return;
        }

        const gate = config.trafficGate;
        const timings = validTimings(gate?.timings);
        if (!gate || gate.enabled !== true || gate.readiness !== 'READY'
            || gate.provider !== PROVIDER || gate.gateOrigin !== GATE_ORIGIN
            || !validTurnstileSiteKey(gate.siteKey) || !timings) {
            fail('GATE_NOT_READY');
            return;
        }

        setState(STATES.parentValidated);
        try {
            await loadTurnstileScript();
        } catch {
            fail('TURNSTILE_SCRIPT_ERROR');
            return;
        }
        renderTurnstile(gate.siteKey, timings, false);
    }

    async function startAdminTestMode(parentUrl, data) {
        if (parentUrl.origin !== ADMIN_ORIGIN) {
            deny('ADMIN_TEST_ORIGIN_REQUIRED');
            return;
        }

        if (!validTurnstileSiteKey(data.candidateSiteKey)) {
            fail('INVALID_TEST_SITE_KEY');
            return;
        }

        setState(STATES.parentValidated);
        try {
            await loadTurnstileScript();
        } catch {
            fail('TURNSTILE_SCRIPT_ERROR');
            return;
        }
        renderTurnstile(data.candidateSiteKey, DEFAULT_TEST_TIMINGS, true);
    }

    async function onParentMessage(event) {
        if (state !== STATES.booting || event.source !== window.parent) {
            return;
        }

        const data = event.data;
        if (!data || typeof data !== 'object' || data.type !== TYPES.hello
            || data.protocolVersion !== PROTOCOL_VERSION || !validPageNonce(data.pageNonce)
            || !validSitePublicKey(data.sitePublicKey)) {
            return;
        }

        const parentUrl = parsedHttpsOrigin(event.origin);
        if (!parentUrl) {
            return;
        }

        boundParent = {
            source: event.source,
            origin: event.origin,
            pageNonce: data.pageNonce,
            sitePublicKey: data.sitePublicKey,
        };

        if (window.location.origin !== GATE_ORIGIN) {
            deny('INVALID_GATE_ORIGIN');
            return;
        }

        if (data.testMode === true) {
            await startAdminTestMode(parentUrl, data);
            return;
        }

        await startNormalMode(parentUrl);
    }

    setState(STATES.booting);
    window.addEventListener('message', onParentMessage, false);
})();