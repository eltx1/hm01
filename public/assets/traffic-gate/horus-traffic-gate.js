(() => {
    'use strict';

    const PROTOCOL_VERSION = 2;
    const GATE_ORIGIN = 'https://verify.horusmedia.net';
    const ADMIN_ORIGIN = 'https://app.horusmedia.net';
    const TURNSTILE_SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    const PROVIDER = 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED';
    const MAX_RETRIES = 1;
    const HARD_BOOT_TIMEOUT_MS = 15000;
    const DEFAULT_TEST_TIMINGS = Object.freeze({
        initialWaitMs: 1500,
        maxWaitMs: 10000,
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
    let handshakeLocked = false;
    let handshakeStartedAt = null;
    let boundParent = null;
    let widgetId = null;
    let challengeTimer = null;
    let retryTimer = null;
    let retries = 0;
    let terminal = false;
    let verificationPending = false;
    let verificationController = null;

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
            if (url.protocol !== 'https:' || url.origin !== origin || url.username || url.password || url.port !== '') {
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

    function armDeadline(delayMs, category) {
        if (terminal) {
            return;
        }
        if (challengeTimer !== null) {
            clearTimeout(challengeTimer);
        }
        challengeTimer = setTimeout(() => timeout(category), Math.max(1, delayMs));
    }

    function applyConfiguredDeadline(maxWaitMs) {
        const elapsed = handshakeStartedAt === null ? 0 : Math.max(0, Date.now() - handshakeStartedAt);
        const remaining = maxWaitMs - elapsed;
        if (remaining <= 0) {
            timeout('GATE_MAX_WAIT');
            return false;
        }
        armDeadline(remaining, 'GATE_MAX_WAIT');
        return true;
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
        verificationController?.abort();
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

    async function verifyToken(token, timings) {
        if (terminal || verificationPending) return;
        if (typeof token !== 'string' || !token || token.length > 2048 || !window.crypto?.randomUUID) {
            fail('INVALID_VERIFICATION_TOKEN');
            return;
        }
        verificationPending = true;
        const requestId = window.crypto.randomUUID();
        for (let attempt = 0; attempt < 2 && !terminal; attempt += 1) {
            verificationController = new AbortController();
            const requestTimer = setTimeout(() => verificationController?.abort(), 4000);
            let retryable = true;
            try {
                const response = await fetch('https://siteverify.horusmedia.net/verify', {
                    method: 'POST', credentials: 'omit', cache: 'no-store', redirect: 'error',
                    signal: verificationController.signal,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token, pageNonce: boundParent.pageNonce, requestId }),
                });
                const result = await response.json();
                if (terminal) return;
                if (response.ok && result.success === true && result.pageNonce === boundParent.pageNonce) {
                    finish(TYPES.pass, STATES.passed, { provider: PROVIDER, serverVerified: true });
                    return;
                }
                retryable = (response.status === 429 || response.status >= 500) && result.retryable === true;
            } catch {
                if (terminal) return;
            } finally { clearTimeout(requestTimer); }
            if (!retryable) { fail('VERIFICATION_REJECTED'); return; }
            if (attempt === 0) await new Promise((resolve) => setTimeout(resolve, timings.retryIntervalMs));
        }
        if (!terminal) fail('VERIFICATION_UNAVAILABLE');
    }

    function renderTurnstile(siteKey, timings, testMode) {
        if (terminal) {
            return;
        }
        if (!widgetContainer || typeof window.turnstile?.render !== 'function') {
            fail('TURNSTILE_API_UNAVAILABLE');
            return;
        }

        setState(STATES.challengeRunning);
        post(TYPES.ready, { provider: PROVIDER, testMode });

        try {
            const renderedWidgetId = window.turnstile.render(widgetContainer, {
                sitekey: siteKey,
                execution: 'render',
                action: 'horus_ads',
                cData: boundParent.pageNonce,
                retry: 'never',
                'response-field': false,
                callback: (token) => verifyToken(token, timings),
                'error-callback': (errorCode) => handleTurnstileError(errorCode, timings),
                'timeout-callback': () => timeout('TURNSTILE_TIMEOUT'),
                'unsupported-callback': () => fail('UNSUPPORTED_BROWSER'),
            });
            widgetId = renderedWidgetId;
            if (terminal) {
                removeWidget();
            }
        } catch {
            fail('TURNSTILE_RENDER_ERROR');
        }
    }

    async function startNormalMode(parentUrl) {
        // A well-formed, source-bound HELLO may prepare the library concurrently
        // with the independent config read. Never render a challenge or verify a
        // token until that config authorizes the parent and gate settings.
        const configuration = loadSiteConfiguration(boundParent.sitePublicKey);
        // Observe rejection immediately, even if config is slow/denied or the
        // deadline expires first. Library availability is not authorization.
        const turnstileReady = loadTurnstileScript().then(() => true, () => false);
        let config;
        try {
            config = await configuration;
        } catch {
            fail('STATIC_CONFIG_UNAVAILABLE');
            return;
        }

        if (terminal) return;
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
        if (!applyConfiguredDeadline(timings.maxWaitMs)) {
            return;
        }

        setState(STATES.parentValidated);
        const libraryAvailable = await turnstileReady;
        if (terminal) return;
        if (!libraryAvailable) {
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
        if (!applyConfiguredDeadline(DEFAULT_TEST_TIMINGS.maxWaitMs)) {
            return;
        }

        setState(STATES.parentValidated);
        try {
            await loadTurnstileScript();
        } catch {
            fail('TURNSTILE_SCRIPT_ERROR');
            return;
        }
        if (terminal) return;
        renderTurnstile(data.candidateSiteKey, DEFAULT_TEST_TIMINGS, true);
    }

    async function onParentMessage(event) {
        if (handshakeLocked || state !== STATES.booting || event.source !== window.parent) {
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

        handshakeLocked = true;
        handshakeStartedAt = Date.now();
        boundParent = {
            source: event.source,
            origin: event.origin,
            pageNonce: data.pageNonce,
            sitePublicKey: data.sitePublicKey,
        };
        armDeadline(HARD_BOOT_TIMEOUT_MS, 'GATE_BOOT_TIMEOUT');

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
