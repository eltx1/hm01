const MARKER = 'var TRAFFIC_GATE_PROTOCOL_VERSION = 2;';

function replaceOnce(source, search, replacement, label) {
    const next = source.replace(search, replacement);
    if (next === source) {
        throw new Error(`Traffic Gate Loader transform anchor missing: ${label}`);
    }
    return next;
}

const trafficGateRuntime = String.raw`
    // Start static work and isolated verification early. Ad initialization and CMP
    // discovery keep their DOM boundary, including CMPs installed later by the page.
    var earlyBootPreparation = null;
    var EARLY_PREPARATION_MAX_AGE_MS = 5000;

    function nextPreparationGeneration() {
        state.preparationGeneration = Number(state.preparationGeneration || 0) + 1;
        return state.preparationGeneration;
    }

    function addPreparationHint(rel, href, as, crossOrigin) {
        try {
            var key = [rel, href, as || '', crossOrigin || ''].join('|');
            state.preparationHints = state.preparationHints || {};
            if (state.preparationHints[key]) return;
            var parent = document.head || document.documentElement;
            if (!parent) return;
            var link = document.createElement('link');
            link.setAttribute('rel', rel);
            link.setAttribute('href', href);
            link.setAttribute('data-hm-preparation', '1');
            if (as) link.setAttribute('as', as);
            if (crossOrigin) link.setAttribute('crossorigin', crossOrigin);
            parent.appendChild(link);
            state.preparationHints[key] = true;
        } catch (error) {
            // Resource hints are optional. CSP/DOM failures must not break boot.
        }
    }

    function prepareStaticConnections(config, privacyReady) {
        if (!config || window.__HM_RELEASE_HANDOFF_FAILED__
            || !hostAllowed(currentHostname(), config.allowedHostnames)
            || config.status !== 'active' || config.immediatePause) return;
        var controls = normalizeControls(config.controls || {});
        if (controls.adServingDisabled) return;
        var gate = trafficGateSettings(config);
        if (gate.enabled && !controls.trafficGateDisabled) {
            if (!gate.valid) return;
            addPreparationHint('preconnect', gate.origin);
            addPreparationHint('preconnect', 'https://challenges.cloudflare.com');
            addPreparationHint('preconnect', 'https://siteverify.horusmedia.net', null, 'anonymous');
        }

        var privacy = config.privacy || {};
        var cmp = privacy.cmp || {};
        if (!privacyReady && (privacy.requireConsentBeforeAds !== false
            || String(privacy.mode || '').toUpperCase() === 'STRICT'
            || String(cmp.actionOnTimeout || '').toUpperCase() === 'BLOCK_ADS')) return;
        if (controls.gamDisabled || (window.googletag && (window.googletag.apiReady || window.googletag.pubadsReady))) return;
        var hasGam = (config.placements || []).some(function (placement) {
            return placement.enabled && placement.status === 'active' && placement.adUnitPath
                && placement.renderer !== 'PREBID_STANDALONE';
        });
        if (!hasGam) return;
        try {
            var url = new URL(config.gpt && config.gpt.url || 'https://securepubads.g.doubleclick.net/tag/js/gpt.js');
            if (url.protocol !== 'https:' || url.username || url.password || url.port
                || ['securepubads.g.doubleclick.net', 'pagead2.googlesyndication.com'].indexOf(url.hostname) === -1
                || url.pathname !== '/tag/js/gpt.js') return;
            // Match the classic script's existing fetch mode. A preload downloads
            // bytes without running GPT or any publisher-owned googletag queue.
            // Slot definition, script execution and requests stay behind PASS/CMP.
            addPreparationHint('preload', url.href, 'script');
        } catch (error) {}
    }

    function fetchBootPreparation(script, siteKey, force) {
        return Promise.all([fetchGlobalControl(script, force), fetchConfig(script, siteKey, force)]).then(function (prepared) {
            var config = prepared[1];
            config.controls = mergeControls(config.controls || {}, prepared[0] || {});
            return config;
        });
    }

    function prepareEarlyTrafficGate(config, script, siteKey) {
        if (state.config || state.booting || state.earlyTrafficGatePreparation
            || window.__HM_RELEASE_HANDOFF_FAILED__
            || !config || config.siteKey !== siteKey
            || !hostAllowed(currentHostname(), config.allowedHostnames)
            || config.status !== 'active' || config.immediatePause || servingDisabled(config)) return;
        var settings = trafficGateSettings(config);
        var controls = effectiveControls(config);
        var parent = document.body || document.documentElement;
        if (!parent || !parent.appendChild || !settings.enabled || !settings.valid
            || controls.trafficGateDisabled || trafficGateRuntimeState().started) return;

        // Do not expose config or attach a monetization callback before DOM/CMP.
        // Bind this attempt to the exact snapshot so a later refresh cannot adopt
        // its PASS (or pending iframe) for different configuration.
        var snapshot = JSON.stringify(config);
        beginTrafficGate(config);
        state.earlyTrafficGatePreparation = {
            script: script, siteKey: siteKey, snapshot: snapshot, runtime: state.trafficGate
        };
    }

    function reconcileEarlyTrafficGate(config, script, siteKey) {
        var preparation = state.earlyTrafficGatePreparation;
        state.earlyTrafficGatePreparation = null;
        if (!preparation || preparation.runtime !== state.trafficGate) return;
        if (config && preparation.script === script && preparation.siteKey === siteKey
            && preparation.snapshot === JSON.stringify(config)
            && !preparation.runtime.retryAtDomReady) return;

        // Retire a stale attempt, including a completed PASS. Old frame messages
        // lose their listener/source/nonce binding before any new attempt starts.
        // A transient early failure gets the normal DOM-time attempt once. The
        // preparation owner is cleared above, so repeated boots cannot loop.
        trafficGateSetState(TRAFFIC_GATE_STATES.unavailable, 'PREPARATION_DISCARDED');
        trafficGateCleanup();
        settleTrafficGateDecision();
        state.trafficGate = freshTrafficGateRuntime();
    }

    function startEarlyBootPreparation() {
        var script = findScript();
        var siteKey = scriptData(script, 'siteKey');
        if (!siteKey || !window.fetch || earlyBootPreparation || state.booting) return;
        var generation = nextPreparationGeneration();
        var preparation = { script: script, siteKey: siteKey, startedAt: Date.now(), promise: null };
        earlyBootPreparation = preparation;
        preparation.promise = fetchBootPreparation(script, siteKey, false).then(function (config) {
            if (generation === state.preparationGeneration
                && Date.now() - preparation.startedAt <= EARLY_PREPARATION_MAX_AGE_MS) {
                prepareStaticConnections(config, false);
                prepareEarlyTrafficGate(config, script, siteKey);
            }
            return config;
        }).catch(function () {
            // An early fetch failure must not become an unhandled rejection.
            // Normal boot gets one fresh attempt through its existing error path.
            return null;
        });
    }

    function takeBootPreparation(script, siteKey, force) {
        var preparation = earlyBootPreparation;
        earlyBootPreparation = null;
        if (!force && preparation && preparation.script === script && preparation.siteKey === siteKey
            && Date.now() - preparation.startedAt <= EARLY_PREPARATION_MAX_AGE_MS) {
            return preparation.promise.then(function (config) {
                return config && Date.now() - preparation.startedAt <= EARLY_PREPARATION_MAX_AGE_MS
                    ? config : fetchBootPreparation(script, siteKey, force);
            });
        }
        // Do not reuse an old pre-DOM snapshot after a long parser stall or a
        // forced refresh: emergency controls/config must be read again.
        return fetchBootPreparation(script, siteKey, force);
    }

    var TRAFFIC_GATE_PROTOCOL_VERSION = 2;
    var TRAFFIC_GATE_PATH = '/traffic-gate/';
    var TRAFFIC_GATE_PROVIDER = 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED';
    var TRAFFIC_GATE_DEFAULT_MAX_WAIT_MS = 10000;
    var TRAFFIC_GATE_STATES = {
        disabled: 'DISABLED', booting: 'BOOTING', pending: 'PENDING', passed: 'PASSED',
        error: 'ERROR', timeout: 'TIMEOUT', unavailable: 'UNAVAILABLE',
        blocked: 'BLOCKED'
    };

    function freshTrafficGateRuntime() {
        return {
            status: TRAFFIC_GATE_STATES.booting,
            reason: null,
            started: false,
            startedAt: 0,
            settings: null,
            pageNonce: null,
            iframe: null,
            messageListener: null,
            activityListeners: [],
            activityBaseline: null,
            initialTimer: null,
            maxTimer: null,
            decisionPromise: null,
            decisionResolve: null,
            retryAtDomReady: false,
            resume: null
        };
    }

    function trafficGateRuntimeState() {
        state.trafficGate = state.trafficGate || freshTrafficGateRuntime();
        return state.trafficGate;
    }

    function trafficGateAllowsMonetization() {
        var status = trafficGateRuntimeState().status;
        return status === TRAFFIC_GATE_STATES.disabled
            || status === TRAFFIC_GATE_STATES.passed;
    }

    function trafficGateDebugState() {
        var gate = trafficGateRuntimeState();
        return {
            state: gate.status,
            policy: gate.settings && gate.settings.policy || null,
            reason: gate.reason,
            started: gate.started === true
        };
    }

    function canonicalTrafficGateOrigin(value) {
        try {
            var parsed = new URL(String(value || ''));
            if (parsed.protocol !== 'https:' || parsed.origin !== String(value || '') || parsed.hostname !== 'verify.horusmedia.net') return null;
            if (parsed.username || parsed.password || parsed.port) return null;
            return parsed.origin;
        } catch (error) {
            return null;
        }
    }

    function trafficGateSettings(config) {
        var selected = config && config.trafficGate;
        if (!selected || selected.enabled !== true) {
            return { enabled: false, valid: true, policy: 'BALANCED', activityRecoveryEnabled: false };
        }
        var policy = String(selected.policy || '').toUpperCase();
        var timings = selected.timings || {};
        var initialWaitMs = Number(timings.initialWaitMs);
        var maxWaitMs = Number(timings.maxWaitMs);
        var retryIntervalMs = Number(timings.retryIntervalMs);
        var origin = canonicalTrafficGateOrigin(selected.gateOrigin);
        var siteKey = String(config && config.siteKey || '');
        var publicSiteKey = String(selected.siteKey || '');
        var valid = selected.readiness === 'READY' && selected.provider === TRAFFIC_GATE_PROVIDER
            && ['STRICT', 'BALANCED', 'PERMISSIVE'].indexOf(policy) !== -1
            && origin !== null
            && /^[A-Za-z0-9_-]{3,64}$/.test(siteKey)
            && /^[A-Za-z0-9_-]{3,255}$/.test(publicSiteKey)
            && Number.isInteger(initialWaitMs) && initialWaitMs >= 500 && initialWaitMs <= 5000
            && Number.isInteger(maxWaitMs) && maxWaitMs >= 2000 && maxWaitMs <= 15000
            && Number.isInteger(retryIntervalMs) && retryIntervalMs >= 500 && retryIntervalMs <= 10000
            && maxWaitMs >= initialWaitMs;
        return {
            enabled: true,
            valid: valid,
            origin: origin,
            siteKey: siteKey,
            turnstileSiteKey: publicSiteKey,
            policy: policy || 'BALANCED',
            initialWaitMs: initialWaitMs,
            maxWaitMs: maxWaitMs,
            retryIntervalMs: retryIntervalMs,
            activityRecoveryEnabled: selected.activityRecoveryEnabled === true
        };
    }

    function trafficGateSetState(next, reason) {
        var gate = trafficGateRuntimeState();
        gate.status = next;
        gate.reason = reason || null;
    }

    function trafficGateClearTimer(name) {
        var gate = trafficGateRuntimeState();
        if (gate[name] !== null) {
            window.clearTimeout(gate[name]);
            gate[name] = null;
        }
    }

    function trafficGateEnsureMaxTimer() {
        var gate = trafficGateRuntimeState();
        if (gate.maxTimer !== null || !gate.settings) return;
        var delay = Number(gate.settings.maxWaitMs);
        if (!Number.isInteger(delay) || delay < 2000 || delay > 15000) {
            delay = TRAFFIC_GATE_DEFAULT_MAX_WAIT_MS;
        }
        gate.maxTimer = window.setTimeout(trafficGateOnMaxWait, delay);
    }

    function trafficGateRemoveMessageListener() {
        var gate = trafficGateRuntimeState();
        if (gate.messageListener && window.removeEventListener) window.removeEventListener('message', gate.messageListener, false);
        gate.messageListener = null;
    }

    function trafficGateRemoveIframe() {
        var gate = trafficGateRuntimeState();
        var iframe = gate.iframe;
        if (!iframe) return;
        iframe.onload = null;
        iframe.onerror = null;
        if (iframe.parentNode && iframe.parentNode.removeChild) iframe.parentNode.removeChild(iframe);
        gate.iframe = null;
    }

    function trafficGateRemoveActivityListeners() {
        var gate = trafficGateRuntimeState();
        if (window.removeEventListener) {
            gate.activityListeners.forEach(function (entry) {
                window.removeEventListener(entry.type, entry.listener, true);
            });
        }
        gate.activityListeners = [];
        gate.activityBaseline = null;
    }

    function trafficGateCleanup(options) {
        options = options || {};
        trafficGateClearTimer('initialTimer');
        if (!options.preserveMaxTimer) trafficGateClearTimer('maxTimer');
        if (!options.preserveMessage) trafficGateRemoveMessageListener();
        if (!options.preserveIframe) trafficGateRemoveIframe();
        if (!options.preserveActivity) trafficGateRemoveActivityListeners();
    }

    function trafficGateDecisionPromise() {
        var gate = trafficGateRuntimeState();
        if (!gate.decisionPromise) {
            gate.decisionPromise = new Promise(function (resolve) { gate.decisionResolve = resolve; });
        }
        return gate.decisionPromise;
    }

    function settleTrafficGateDecision() {
        var gate = trafficGateRuntimeState();
        if (!gate.decisionResolve) return;
        var resolve = gate.decisionResolve;
        gate.decisionResolve = null;
        resolve({ allowed: trafficGateAllowsMonetization(), state: gate.status });
    }

    function trafficGateNotifyAllowed() {
        var gate = trafficGateRuntimeState();
        var resume = gate.resume;
        gate.resume = null;
        if (typeof resume === 'function') {
            Promise.resolve().then(function () { return resume(); }).catch(function () {});
        }
    }

    function trafficGateAllow(nextState, reason) {
        var gate = trafficGateRuntimeState();
        if (gate.status === TRAFFIC_GATE_STATES.blocked && nextState !== TRAFFIC_GATE_STATES.disabled) return;
        trafficGateSetState(nextState, reason);
        trafficGateCleanup();
        settleTrafficGateDecision();
        trafficGateNotifyAllowed();
    }

    function trafficGateBlock(reason) {
        trafficGateSetState(TRAFFIC_GATE_STATES.blocked, reason || 'DENIED');
        trafficGateCleanup();
        settleTrafficGateDecision();
    }

    function trafficGateTechnicalFailure(stateName, reason, retryAtDomReady) {
        var gate = trafficGateRuntimeState();
        if (trafficGateAllowsMonetization() || gate.status === TRAFFIC_GATE_STATES.blocked) return;
        trafficGateSetState(stateName, reason);
        gate.retryAtDomReady = retryAtDomReady !== false;
        trafficGateCleanup();
        settleTrafficGateDecision();
    }

    function trafficGateOnInitialWait() {
        // A slow challenge is not a verification result. Keep waiting within
        // the original deadline; interaction must never authorize ads.
        trafficGateRuntimeState().initialTimer = null;
    }

    function trafficGateOnMaxWait() {
        var gate = trafficGateRuntimeState();
        gate.maxTimer = null;
        if (trafficGateAllowsMonetization() || gate.status === TRAFFIC_GATE_STATES.blocked) return;
        trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.timeout, 'MAX_WAIT', true);
    }

    function generateTrafficGateNonce() {
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') return null;
        try {
            var bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            var value = '';
            for (var index = 0; index < bytes.length; index += 1) value += bytes[index].toString(16).padStart(2, '0');
            return value;
        } catch (error) {
            return null;
        }
    }

    function trafficGateMessageListener(event) {
        var gate = trafficGateRuntimeState();
        if (!gate.iframe || !gate.settings || !event) return;
        if (event.origin !== gate.settings.origin) return;
        if (event.source !== gate.iframe.contentWindow) return;
        var message = event.data;
        if (!message || typeof message !== 'object') return;
        if (message.protocolVersion !== TRAFFIC_GATE_PROTOCOL_VERSION) return;
        if (message.pageNonce !== gate.pageNonce) return;
        var type = String(message.type || '');
        if (type === 'HORUS_TRAFFIC_GATE_READY') return;
        if (type === 'HORUS_TRAFFIC_GATE_PASS' && message.serverVerified === true) {
            trafficGateAllow(TRAFFIC_GATE_STATES.passed, 'PASS');
            return;
        }
        if (type === 'HORUS_TRAFFIC_GATE_DENIED') {
            trafficGateBlock('DENIED');
            return;
        }
        if (type === 'HORUS_TRAFFIC_GATE_ERROR') {
            // Any failed early preparation can fall back to the ordinary DOM
            // attempt. An explicit server rejection is authoritative and is not
            // a loading failure. Every new attempt still needs fresh verification.
            var preparationFailed = message.category !== 'VERIFICATION_REJECTED';
            trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.error, 'TURNSTILE_ERROR', preparationFailed);
            return;
        }
        if (type === 'HORUS_TRAFFIC_GATE_TIMEOUT') {
            trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.timeout, 'TURNSTILE_TIMEOUT', true);
        }
    }

    function beginTrafficGate(config) {
        var gate = trafficGateRuntimeState();
        var controls = effectiveControls(config);
        var settings = trafficGateSettings(config);
        gate.settings = settings;

        if (controls.adServingDisabled || controls.trafficGateDisabled || !settings.enabled) {
            trafficGateSetState(TRAFFIC_GATE_STATES.disabled, controls.trafficGateDisabled ? 'EMERGENCY_DISABLED' : 'NOT_REQUIRED');
            trafficGateCleanup();
            settleTrafficGateDecision();
            return Promise.resolve({ allowed: true, state: gate.status });
        }
        if (trafficGateAllowsMonetization()) return Promise.resolve({ allowed: true, state: gate.status });
        if (gate.status === TRAFFIC_GATE_STATES.blocked) return Promise.resolve({ allowed: false, state: gate.status });
        if (gate.started) {
            return gate.decisionResolve ? trafficGateDecisionPromise() : Promise.resolve({ allowed: trafficGateAllowsMonetization(), state: gate.status });
        }

        gate.started = true;
        gate.startedAt = Date.now();
        trafficGateSetState(TRAFFIC_GATE_STATES.booting, null);
        var decision = trafficGateDecisionPromise();
        // Establish the bounded availability deadline before any operation
        // that can fail (configuration validation, crypto, iframe creation).
        trafficGateEnsureMaxTimer();
        if (!settings.valid) {
            trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.unavailable, 'INVALID_CONFIGURATION');
            return decision;
        }

        gate.pageNonce = generateTrafficGateNonce();
        if (!gate.pageNonce) {
            trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.unavailable, 'CRYPTO_UNAVAILABLE');
            return decision;
        }

        var iframe;
        try {
            iframe = document.createElement('iframe');
            iframe.src = settings.origin + TRAFFIC_GATE_PATH + '?protocol=2';
            iframe.title = 'Horus client traffic gate';
            iframe.setAttribute('aria-hidden', 'true');
            iframe.setAttribute('tabindex', '-1');
            iframe.setAttribute('data-hm-traffic-gate', '1');
            if (iframe.style && iframe.style.setProperty) {
                iframe.style.setProperty('position', 'fixed');
                iframe.style.setProperty('width', '1px');
                iframe.style.setProperty('height', '1px');
                iframe.style.setProperty('left', '-10000px');
                iframe.style.setProperty('top', '-10000px');
                iframe.style.setProperty('border', '0');
                iframe.style.setProperty('opacity', '0');
                iframe.style.setProperty('pointer-events', 'none');
            }
        } catch (error) {
            trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.unavailable, 'IFRAME_CREATE_FAILED', true);
            return decision;
        }

        gate.iframe = iframe;
        gate.messageListener = trafficGateMessageListener;
        if (window.addEventListener) window.addEventListener('message', gate.messageListener, false);
        iframe.onload = function () {
            var current = trafficGateRuntimeState();
            if (!current.iframe || current.iframe !== iframe || !iframe.contentWindow) return;
            try {
                iframe.contentWindow.postMessage({
                    type: 'HORUS_TRAFFIC_GATE_HELLO',
                    protocolVersion: TRAFFIC_GATE_PROTOCOL_VERSION,
                    pageNonce: current.pageNonce,
                    sitePublicKey: settings.siteKey
                }, settings.origin);
            } catch (error) {
                trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.unavailable, 'HANDSHAKE_FAILED', true);
            }
        };
        iframe.onerror = function () {
            trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.unavailable, 'IFRAME_UNAVAILABLE', true);
        };

        trafficGateSetState(TRAFFIC_GATE_STATES.pending, null);
        gate.initialTimer = window.setTimeout(trafficGateOnInitialWait, settings.initialWaitMs);
        trafficGateEnsureMaxTimer();
        try {
            var parent = document.body || document.documentElement;
            if (!parent || !parent.appendChild) throw new Error('No frame parent');
            parent.appendChild(iframe);
        } catch (error) {
            trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.unavailable, 'IFRAME_APPEND_FAILED', true);
        }
        return decision;
    }

    function setTrafficGateResume(callback) {
        trafficGateRuntimeState().resume = typeof callback === 'function' ? callback : null;
    }

    function resetTrafficGateRuntimeForTests() {
        trafficGateCleanup();
        state.trafficGate = freshTrafficGateRuntime();
    }
`;

const bootReplacement = String.raw`    function startMonetization(config, script, diagnostic) {
        if (!config || state.config !== config || !trafficGateAllowsMonetization()) return Promise.resolve([]);
        if (state.monetizationStartPromise) return state.monetizationStartPromise;
        var monetizationPromise = reportPrivacyDiagnostic(config, diagnostic).then(function () {
            if (config.status !== 'active' || config.immediatePause || servingDisabled(config)) {
                log(config, 'Advertising is disabled; no advertising calls were made');
                return [];
            }
            if (state.privacyDecision && state.privacyDecision.blocked) {
                log(config, 'Privacy gate blocked advertising after the bounded CMP timeout');
                return [];
            }
            initializeClickGuard(config);
            if (!canRequestAds(config)) {
                log(config, 'Advertising remains blocked by a local serving prerequisite');
                return [];
            }
            var delegation = maybeDelegateRelease(config, script);
            if (delegation) {
                // The delegated release uses the same public runtime state. Give
                // it ownership of monetization startup before awaiting its boot;
                // otherwise it would inherit this Promise and wait on itself.
                if (state.monetizationStartPromise === monetizationPromise) state.monetizationStartPromise = null;
                return delegation.then(function () { return []; });
            }
            installSpaSupport();
            return scan(config);
        }).finally(function () {
            if (state.monetizationStartPromise === monetizationPromise) state.monetizationStartPromise = null;
        });
        state.monetizationStartPromise = monetizationPromise;
        return monetizationPromise;
    }

    function boot(options) {
        options = options || {};
        if (window.__HM_RELEASE_HANDOFF_FAILED__ && !options.delegatedHandoff) return Promise.resolve([]);
        if (window.__HM_RELEASE_HANDOFF_PROMISE__ && !options.delegatedHandoff) {
            return window.__HM_RELEASE_HANDOFF_PROMISE__;
        }
        var script = options.script || findScript();
        var diagnostic = capturePrivacyDiagnostic(script);
        var siteKey = options.siteKey || scriptData(script, 'siteKey');
        if (!siteKey || !window.fetch) return Promise.resolve([]);
        if (state.booting && !options.force) return state.booting;

        // Static configuration and global controls are independent preparation.
        // Neither waits for Turnstile or CMP resolution.
        var generation = nextPreparationGeneration();
        var bootPromise = takeBootPreparation(script, siteKey, Boolean(options.force)).then(function (config) {
            if (generation !== state.preparationGeneration) return [];
            reconcileEarlyTrafficGate(config, script, siteKey);
            state.config = config;

            if (!hostAllowed(currentHostname(), config.allowedHostnames)) {
                log(config, 'Hostname rejected', currentHostname());
                return [];
            }
            if (effectiveControls(config).adServingDisabled) {
                beginTrafficGate(config);
                log(config, 'Global advertising kill switch is active');
                return [];
            }
            if (config.status !== 'active' || config.immediatePause || servingDisabled(config)) {
                beginTrafficGate(config);
                log(config, 'Advertising is disabled; no advertising calls were made');
                return [];
            }

            // Once static configuration is known, privacy and the Client Traffic
            // Gate begin in parallel. PASS never waits for configured gate timers;
            // monetization waits only until both independent prerequisites permit it.
            prepareStaticConnections(config, false);
            setTrafficGateResume(function () {
                if (state.config !== config || !state.privacyDecision) return [];
                return startMonetization(config, script, diagnostic);
            });
            var gatePromise = beginTrafficGate(config);
            var privacyPromise = resolvePrivacy(config).then(function (decision) {
                if (!decision.blocked && state.config === config && generation === state.preparationGeneration) {
                    prepareStaticConnections(config, true);
                }
                return decision;
            });
            return Promise.all([gatePromise, privacyPromise]).then(function () {
                if (!trafficGateAllowsMonetization()) return [];
                return startMonetization(config, script, diagnostic);
            });
        }).catch(function (error) {
            if (generation === state.preparationGeneration) reconcileEarlyTrafficGate(null);
            log({ debug: Boolean(scriptData(script, 'debug')) }, 'Loader stopped safely', error);
            return [];
        }).finally(function () {
            // A delegated release can replace the shared boot slot while this
            // Promise is still pending. Never clear the newer owner's Promise.
            if (state.booting === bootPromise) state.booting = null;
        });
        state.booting = bootPromise;
        return bootPromise;
    }

    window.HorusMediaLoader = {`;

export function applyTrafficGateTransform(input) {
    let source = String(input || '');
    if (source.includes(MARKER)) return source;

    source = replaceOnce(
        source,
        "        rewardedListenersInstalled: false,\n        clickGuard: null\n",
        "        rewardedListenersInstalled: false,\n        clickGuard: null,\n        trafficGate: null,\n        monetizationStartPromise: null\n",
        'loader state',
    );

    source = replaceOnce(
        source,
        "    var CLICK_GUARD_MAX_TIMEOUT_MS = 2147483647;\n",
        "    var CLICK_GUARD_MAX_TIMEOUT_MS = 2147483647;\n" + trafficGateRuntime,
        'runtime insertion',
    );

    source = replaceOnce(
        source,
        /    function failClosedControls\(\) \{[\s\S]*?\n    \}\n\n    function controlFlag/,
        `    function failClosedControls() {\n        return {\n            adServingDisabled: true,\n            gamDisabled: true,\n            prebidDisabled: true,\n            directJsDisabled: true,\n            directDemandDisabled: true,\n            nativeDemandDisabled: true,\n            trafficGateDisabled: true\n        };\n    }\n\n    function controlFlag`,
        'fail-closed controls',
    );

    source = replaceOnce(
        source,
        "            directDemandDisabled: direct || legacyDirect,\n            nativeDemandDisabled: direct || legacyNative\n",
        "            directDemandDisabled: direct || legacyDirect,\n            nativeDemandDisabled: direct || legacyNative,\n            trafficGateDisabled: controlFlag(controls, 'trafficGateDisabled')\n",
        'normalize traffic gate control',
    );

    source = replaceOnce(
        source,
        "            directDemandDisabled: site.directDemandDisabled || global.directDemandDisabled,\n            nativeDemandDisabled: site.nativeDemandDisabled || global.nativeDemandDisabled\n",
        "            directDemandDisabled: site.directDemandDisabled || global.directDemandDisabled,\n            nativeDemandDisabled: site.nativeDemandDisabled || global.nativeDemandDisabled,\n            trafficGateDisabled: site.trafficGateDisabled || global.trafficGateDisabled\n",
        'merge traffic gate control',
    );

    source = replaceOnce(
        source,
        "    function canRequestAds(config) {\n        if (!config || config.status !== 'active' || config.immediatePause || servingDisabled(config)) return false;\n        if (window.__HM_RELEASE_HANDOFF_FAILED__) return false;\n        if (state.privacyDecision && state.privacyDecision.blocked) return false;\n        return !clickGuardBlocked(config);\n    }\n",
        "    function canRequestAds(config) {\n        if (!config || config.status !== 'active' || config.immediatePause || servingDisabled(config)) return false;\n        if (window.__HM_RELEASE_HANDOFF_FAILED__) return false;\n        if (!trafficGateAllowsMonetization()) return false;\n        if (!state.privacyDecision || state.privacyDecision.blocked) return false;\n        return !clickGuardBlocked(config);\n    }\n",
        'central request gate',
    );

    source = replaceOnce(
        source,
        "            definedPlacements: defined.map(function (entry) { return entry.placement.code; })\n",
        "            trafficGate: trafficGateDebugState(),\n            definedPlacements: defined.map(function (entry) { return entry.placement.code; })\n",
        'local diagnostics',
    );

    source = replaceOnce(
        source,
        "    function scan(config) {\n        state.adInitializationStarted = true;\n        if (!canRequestAds(config)) return Promise.resolve([]);\n",
        "    function scan(config) {\n        if (!canRequestAds(config)) return Promise.resolve([]);\n        state.adInitializationStarted = true;\n",
        'scan gate',
    );

    source = replaceOnce(
        source,
        /    function boot\(options\) \{[\s\S]*?\n    \}\n\n    window\.HorusMediaLoader = \{/,
        bootReplacement,
        'parallel boot orchestration',
    );

    source = replaceOnce(
        source,
        "        getConfig: function () { return state.config; },\n        _resetForTests: function () {\n",
        "        getConfig: function () { return state.config; },\n        getTrafficGateState: function () { return trafficGateDebugState(); },\n        _resetForTests: function () {\n",
        'public local state accessor',
    );

    source = replaceOnce(
        source,
        "        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { boot(); }, { once: true });",
        "        if (document.readyState === 'loading') {\n            startEarlyBootPreparation();\n            document.addEventListener('DOMContentLoaded', function () { boot(); }, { once: true });\n        }",
        'static preparation and isolated verification before DOM readiness',
    );

    source = replaceOnce(
        source,
        "            state.adInitializationStarted = false;\n            state.servicesEnabled = false;\n",
        "            state.adInitializationStarted = false;\n            earlyBootPreparation = null;\n            state.earlyTrafficGatePreparation = null;\n            state.monetizationStartPromise = null;\n            resetTrafficGateRuntimeForTests();\n            state.servicesEnabled = false;\n",
        'test reset',
    );

    return source;
}
