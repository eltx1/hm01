(function (window, document) {
    'use strict';

    var VERSION = '2.0.0';
    var STATE_KEY = '__HORUS_MEDIA_LOADER_STATE__';
    var state = window[STATE_KEY] = window[STATE_KEY] || {
        config: null,
        gptPromise: null,
        prebidPromise: null,
        servicesEnabled: false,
        initialLoadDisabled: false,
        slots: {},
        refreshTimers: {},
        observer: null,
        navigationPatched: false,
        scanTimer: null,
        script: null,
        booting: null,
        prebidEventsInstalled: false,
        gamFallbackInstalled: false,
        nativeAttempts: {},
        nativeRendered: {},
        privacyPromise: null,
        privacyDecision: null,
        rewardedListenersInstalled: false,
        clickGuard: null
    };

    var CLICK_GUARD_STATE_VERSION = 1;
    var CLICK_GUARD_STORAGE_PREFIX = 'hm:click-guard:v1:';
    var CLICK_GUARD_HOUR_MS = 60 * 60 * 1000;
    var CLICK_GUARD_DEBOUNCE_MS = 400;
    var CLICK_GUARD_MAX_TIMEOUT_MS = 2147483647;

    function freshClickGuardRuntime() {
        return {
            storageKey: null, settings: null, persisted: { v: CLICK_GUARD_STATE_VERSION, clicks: [], blockedUntil: 0 },
            storageAvailable: true, blocked: false, trackedIframes: typeof WeakSet !== 'undefined' ? new WeakSet() : null,
            trackedIframeEntries: [], activeIframe: null, armed: false, lastClickAt: 0, listenersInstalled: false,
            blurListener: null, storageListener: null, blockTimer: null
        };
    }

    state.clickGuard = state.clickGuard || freshClickGuardRuntime();

    function log(config) {
        if (!config || !config.debug || !window.console || !window.console.info) return;
        var args = Array.prototype.slice.call(arguments, 1);
        args.unshift('[Horus Loader ' + VERSION + ']');
        window.console.info.apply(window.console, args);
    }

    function findScript() {
        if (state.script) return state.script;
        var current = document.currentScript;
        if (current && current.getAttribute && current.getAttribute('data-site-key')) {
            state.script = current;
            return current;
        }
        var scripts = document.querySelectorAll ? document.querySelectorAll('script[data-site-key]') : [];
        state.script = scripts.length ? scripts[scripts.length - 1] : null;
        return state.script;
    }

    function scriptData(script, name) {
        if (!script) return null;
        if (script.dataset && script.dataset[name] !== undefined) return script.dataset[name];
        var attribute = 'data-' + name.replace(/[A-Z]/g, function (letter) { return '-' + letter.toLowerCase(); });
        return script.getAttribute ? script.getAttribute(attribute) : null;
    }

    function configBase(script) {
        var explicit = scriptData(script, 'configBase');
        try {
            var scriptOrigin = new URL(script.src, window.location.href).origin;
            if (explicit) {
                var explicitUrl = new URL(explicit, script.src);
                if (explicitUrl.origin === scriptOrigin) return explicitUrl.href.replace(/\/$/, '');
            }
            return scriptOrigin + '/configs';
        } catch (error) {
            return 'https://cdn.horusmedia.net/configs';
        }
    }

    function currentHostname() {
        return String(window.location && window.location.hostname || '').toLowerCase().replace(/\.$/, '');
    }

    function hostAllowed(hostname, allowed) {
        if (!hostname || !Array.isArray(allowed) || !allowed.length) return false;
        return allowed.some(function (candidate) {
            candidate = String(candidate || '').toLowerCase().replace(/^https?:\/\//, '').split('/')[0].split(':')[0].replace(/\.$/, '');
            if (!candidate) return false;
            if (candidate.indexOf('*.') === 0) {
                var suffix = candidate.slice(1);
                return hostname.endsWith(suffix) && hostname !== suffix.slice(1);
            }
            return hostname === candidate;
        });
    }

    function environmentName(script) {
        return String(scriptData(script, 'environment') || 'production').toLowerCase();
    }

    function buildConfigUrl(script, siteKey, force) {
        var environment = environmentName(script);
        var selectedVersion = scriptData(script, 'configVersion');
        var url = configBase(script) + '/' + encodeURIComponent(siteKey) + '/' + environment + '.json';
        var bust = selectedVersion || (force ? Date.now() : null);
        return bust ? url + '?v=' + encodeURIComponent(bust) : url;
    }

    function fetchJson(url, force) {
        return window.fetch(url, { method: 'GET', mode: 'cors', credentials: 'omit', cache: force ? 'reload' : 'default' })
            .then(function (response) {
                if (!response || !response.ok) throw new Error('Static configuration unavailable');
                return response.json();
            });
    }

    function validateConfig(config, siteKey, expectedVersion) {
        if (!config || config.siteKey !== siteKey) throw new Error('Static configuration site key mismatch');
        if (expectedVersion && Number(config.configVersion) !== Number(expectedVersion)) {
            throw new Error('Static configuration version mismatch');
        }
        return config;
    }

    function manifestEntry(manifest, script, siteKey) {
        if (!manifest || manifest.siteKey !== siteKey || !manifest.environments) return null;
        var entry = manifest.environments[environmentName(script)];
        if (!entry || !entry.path || !entry.version || !/^[a-f0-9]{64}$/i.test(String(entry.sha256 || ''))) return null;
        var expectedPrefix = '/configs/' + encodeURIComponent(siteKey) + '/' + environmentName(script) + '.v';
        if (String(entry.path).indexOf(expectedPrefix) !== 0 || String(entry.path).indexOf('..') !== -1) return null;
        return entry;
    }

    function fetchConfig(script, siteKey, force) {
        var aliasUrl = buildConfigUrl(script, siteKey, force);
        if (scriptData(script, 'configVersion')) {
            return fetchJson(aliasUrl, force).then(function (config) { return validateConfig(config, siteKey); });
        }
        var manifestUrl = configBase(script) + '/' + encodeURIComponent(siteKey) + '/manifest.json' + (force ? '?v=' + encodeURIComponent(Date.now()) : '');
        return fetchJson(manifestUrl, force).then(function (manifest) {
            var entry = manifestEntry(manifest, script, siteKey);
            if (!entry) throw new Error('Static configuration manifest is invalid');
            var immutableUrl = new URL(entry.path, configBase(script) + '/').href;
            return fetchJson(immutableUrl, false).then(function (config) {
                return validateConfig(config, siteKey, entry.version);
            });
        }).catch(function () {
            return fetchJson(aliasUrl, force).then(function (config) { return validateConfig(config, siteKey); });
        });
    }

    function fetchGlobalControl(script, force) {
        var url = configBase(script) + '/_global/control.json' + (force ? '?v=' + encodeURIComponent(Date.now()) : '');
        return window.fetch(url, { method: 'GET', mode: 'cors', credentials: 'omit', cache: force ? 'reload' : 'default' })
            .then(function (response) { return response && response.ok ? response.json() : {}; })
            .catch(function () { return {}; })
            .then(function (payload) {
                return payload && payload.controls ? payload.controls : {};
            });
    }


    function boundedInteger(value, fallback, minimum, maximum) {
        var numeric = Number(value);
        if (!Number.isFinite(numeric) || Math.floor(numeric) !== numeric) return fallback;
        return Math.max(minimum, Math.min(maximum, numeric));
    }

    function clickGuardSettings(config) {
        var selected = config && config.clickGuard || {};
        return {
            enabled: selected.enabled === true,
            maxClicks: boundedInteger(selected.maxClicks, 3, 1, 50),
            windowHours: boundedInteger(selected.windowHours, 6, 1, 168),
            blockHours: boundedInteger(selected.blockHours, 12, 1, 720)
        };
    }

    function clickGuardStorageKey(config) {
        return CLICK_GUARD_STORAGE_PREFIX + String(config && config.siteKey || '');
    }

    function emptyClickGuardState() {
        return { v: CLICK_GUARD_STATE_VERSION, clicks: [], blockedUntil: 0 };
    }

    function normalizeClickGuardState(value, settings, now) {
        var clean = emptyClickGuardState();
        if (!value || typeof value !== 'object' || Number(value.v) !== CLICK_GUARD_STATE_VERSION) return clean;
        var windowStart = now - settings.windowHours * CLICK_GUARD_HOUR_MS;
        var latestReasonableTimestamp = now + 5 * 60 * 1000;
        if (Array.isArray(value.clicks)) {
            clean.clicks = value.clicks.map(Number).filter(function (timestamp) {
                return Number.isFinite(timestamp) && timestamp >= 0 && timestamp >= windowStart && timestamp <= latestReasonableTimestamp;
            }).sort(function (left, right) { return left - right; });
        }
        var blockedUntil = Number(value.blockedUntil || 0);
        var maximumBlock = now + 720 * CLICK_GUARD_HOUR_MS + 5 * 60 * 1000;
        if (Number.isFinite(blockedUntil) && blockedUntil > now && blockedUntil <= maximumBlock) {
            clean.blockedUntil = blockedUntil;
            return clean;
        }
        if (Number.isFinite(blockedUntil) && blockedUntil > 0 && blockedUntil <= now) {
            return emptyClickGuardState();
        }
        return clean;
    }

    function storageValue(config, raw, settings, now) {
        try {
            return normalizeClickGuardState(JSON.parse(raw), settings, now);
        } catch (error) {
            return emptyClickGuardState();
        }
    }

    function readClickGuardState(config) {
        var guard = state.clickGuard;
        var settings = clickGuardSettings(config);
        var now = Date.now();
        if (!settings.enabled || !guard.storageAvailable || !window.localStorage) {
            guard.persisted = emptyClickGuardState();
            guard.blocked = false;
            return guard.persisted;
        }
        try {
            var raw = window.localStorage.getItem(guard.storageKey);
            if (!raw) {
                guard.persisted = emptyClickGuardState();
                guard.blocked = false;
                return guard.persisted;
            }
            var normalized = storageValue(config, raw, settings, now);
            guard.persisted = normalized;
            var normalizedJson = JSON.stringify(normalized);
            if (normalizedJson !== raw) window.localStorage.setItem(guard.storageKey, normalizedJson);
            return normalized;
        } catch (error) {
            guard.storageAvailable = false;
            guard.persisted = emptyClickGuardState();
            guard.blocked = false;
            log(config, 'Click Guard storage unavailable; failing open');
            return guard.persisted;
        }
    }

    function writeClickGuardState(config, value) {
        var guard = state.clickGuard;
        if (!guard.storageAvailable || !window.localStorage) return false;
        try {
            window.localStorage.setItem(guard.storageKey, JSON.stringify(value));
            guard.persisted = value;
            return true;
        } catch (error) {
            guard.storageAvailable = false;
            guard.persisted = emptyClickGuardState();
            guard.blocked = false;
            log(config, 'Click Guard storage write failed; failing open');
            return false;
        }
    }

    function clearAllRefreshTimers() {
        Object.keys(state.refreshTimers).forEach(function (key) {
            window.clearInterval(state.refreshTimers[key]);
            delete state.refreshTimers[key];
        });
    }

    function clearClickGuardBlockTimer() {
        if (!state.clickGuard.blockTimer) return;
        window.clearTimeout(state.clickGuard.blockTimer);
        state.clickGuard.blockTimer = null;
    }

    function scheduleClickGuardBlockExpiry(config, blockedUntil) {
        clearClickGuardBlockTimer();
        if (!blockedUntil || blockedUntil <= Date.now()) return;
        var delay = Math.min(CLICK_GUARD_MAX_TIMEOUT_MS, Math.max(1, blockedUntil - Date.now()));
        state.clickGuard.blockTimer = window.setTimeout(function () {
            state.clickGuard.blockTimer = null;
            var persisted = readClickGuardState(config);
            if (persisted.blockedUntil > Date.now()) {
                scheduleClickGuardBlockExpiry(config, persisted.blockedUntil);
                return;
            }
            state.clickGuard.blocked = false;
            if (canRequestAds(config)) {
                installSpaSupport();
                scan(config);
            }
        }, delay);
    }

    function applyClickGuardState(config, persisted) {
        var blocked = Boolean(persisted && persisted.blockedUntil > Date.now());
        if (blocked) {
            if (!state.clickGuard.blocked) clearAllRefreshTimers();
            state.clickGuard.blocked = true;
            scheduleClickGuardBlockExpiry(config, persisted.blockedUntil);
        } else {
            state.clickGuard.blocked = false;
            clearClickGuardBlockTimer();
        }
        return blocked;
    }

    function clickGuardBlocked(config) {
        var settings = clickGuardSettings(config);
        if (!settings.enabled) return false;
        if (!state.clickGuard.storageAvailable) return false;
        return applyClickGuardState(config, readClickGuardState(config));
    }

    function canRequestAds(config) {
        if (!config || config.status !== 'active' || config.immediatePause || servingDisabled(config)) return false;
        if (state.privacyDecision && state.privacyDecision.blocked) return false;
        return !clickGuardBlocked(config);
    }

    function managedPlacementContainers(config) {
        var active = {};
        (config && config.placements || []).forEach(function (placement) {
            if (placement && placement.enabled && placement.status === 'active') active[String(placement.code)] = true;
        });
        var result = [];
        ['.hm-ad[data-placement]', '.hm-native[data-placement]'].forEach(function (selector) {
            Array.prototype.forEach.call(nodeList(selector), function (node) {
                var code = node && node.getAttribute ? node.getAttribute('data-placement') : null;
                if (code && active[String(code)] && result.indexOf(node) === -1) result.push(node);
            });
        });
        return result;
    }

    function isIframe(node) {
        return Boolean(node && String(node.tagName || '').toLowerCase() === 'iframe');
    }

    function containerContains(container, node) {
        if (!container || !node) return false;
        if (typeof container.contains === 'function') return container.contains(node);
        var current = node;
        while (current) {
            if (current === container) return true;
            current = current.parentNode;
        }
        return false;
    }

    function isEligibleClickGuardIframe(config, iframe) {
        if (!isIframe(iframe)) return false;
        return managedPlacementContainers(config).some(function (container) { return containerContains(container, iframe); });
    }

    function disarmClickGuardIframe(iframe) {
        if (!iframe || state.clickGuard.activeIframe === iframe) {
            state.clickGuard.activeIframe = null;
            state.clickGuard.armed = false;
        }
    }

    function untrackClickGuardIframe(iframe) {
        var entries = state.clickGuard.trackedIframeEntries;
        for (var index = entries.length - 1; index >= 0; index -= 1) {
            var entry = entries[index];
            if (entry.iframe !== iframe) continue;
            if (entry.iframe.removeEventListener) {
                entry.iframe.removeEventListener(entry.enterEvent, entry.enter);
                entry.iframe.removeEventListener(entry.leaveEvent, entry.leave);
            }
            entries.splice(index, 1);
        }
        if (state.clickGuard.trackedIframes && state.clickGuard.trackedIframes.delete) state.clickGuard.trackedIframes.delete(iframe);
        disarmClickGuardIframe(iframe);
    }

    function iframeNodes(node) {
        var frames = [];
        if (isIframe(node)) frames.push(node);
        if (node && node.querySelectorAll) {
            Array.prototype.forEach.call(node.querySelectorAll('iframe'), function (iframe) {
                if (frames.indexOf(iframe) === -1) frames.push(iframe);
            });
        }
        return frames;
    }

    function trackClickGuardIframe(config, iframe) {
        if (!state.clickGuard.settings || !state.clickGuard.settings.enabled || !isEligibleClickGuardIframe(config, iframe)) return;
        if (state.clickGuard.trackedIframes && state.clickGuard.trackedIframes.has(iframe)) return;
        var usePointer = typeof window.PointerEvent === 'function';
        var enterEvent = usePointer ? 'pointerenter' : 'mouseenter';
        var leaveEvent = usePointer ? 'pointerleave' : 'mouseleave';
        var enter = function () {
            if (!canRequestAds(config) || !isEligibleClickGuardIframe(config, iframe)) return;
            state.clickGuard.activeIframe = iframe;
            state.clickGuard.armed = true;
        };
        var leave = function () { disarmClickGuardIframe(iframe); };
        if (!iframe.addEventListener) return;
        iframe.addEventListener(enterEvent, enter);
        iframe.addEventListener(leaveEvent, leave);
        if (state.clickGuard.trackedIframes) state.clickGuard.trackedIframes.add(iframe);
        state.clickGuard.trackedIframeEntries.push({ iframe: iframe, enterEvent: enterEvent, leaveEvent: leaveEvent, enter: enter, leave: leave });
    }

    function discoverClickGuardIframes(config) {
        if (!state.clickGuard.settings || !state.clickGuard.settings.enabled) return;
        managedPlacementContainers(config).forEach(function (container) {
            iframeNodes(container).forEach(function (iframe) { trackClickGuardIframe(config, iframe); });
        });
    }

    function inspectClickGuardMutations(config, mutations) {
        if (!state.clickGuard.settings || !state.clickGuard.settings.enabled || !mutations || !mutations.length) return;
        Array.prototype.forEach.call(mutations || [], function (mutation) {
            Array.prototype.forEach.call(mutation.removedNodes || [], function (node) {
                iframeNodes(node).forEach(untrackClickGuardIframe);
            });
            Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
                iframeNodes(node).forEach(function (iframe) { trackClickGuardIframe(config, iframe); });
            });
        });
    }

    function recordProbableAdClick(config) {
        var guard = state.clickGuard;
        var settings = clickGuardSettings(config);
        if (!settings.enabled || !guard.storageAvailable) return false;
        var now = Date.now();
        var persisted = readClickGuardState(config);
        if (!guard.storageAvailable || applyClickGuardState(config, persisted)) return false;
        var windowStart = now - settings.windowHours * CLICK_GUARD_HOUR_MS;
        var clicks = persisted.clicks.filter(function (timestamp) { return timestamp >= windowStart && timestamp <= now + 5 * 60 * 1000; });
        clicks.push(now);
        if (clicks.length >= settings.maxClicks) {
            var blockedState = { v: CLICK_GUARD_STATE_VERSION, clicks: [], blockedUntil: now + settings.blockHours * CLICK_GUARD_HOUR_MS };
            if (!writeClickGuardState(config, blockedState)) return false;
            applyClickGuardState(config, blockedState);
            diagnostics(config, []);
            return true;
        }
        writeClickGuardState(config, { v: CLICK_GUARD_STATE_VERSION, clicks: clicks, blockedUntil: 0 });
        diagnostics(config, []);
        return false;
    }

    function installClickGuardListeners(config) {
        if (state.clickGuard.listenersInstalled || !window.addEventListener) return;
        state.clickGuard.blurListener = function () {
            var guard = state.clickGuard;
            var iframe = guard.activeIframe;
            if (!guard.armed || !iframe || !canRequestAds(state.config)) return;
            if (document.visibilityState && document.visibilityState !== 'visible') {
                disarmClickGuardIframe(iframe);
                return;
            }
            if (!isEligibleClickGuardIframe(state.config, iframe)) {
                disarmClickGuardIframe(iframe);
                return;
            }
            var activeElement = document.activeElement;
            if (activeElement && isIframe(activeElement) && activeElement !== iframe) {
                disarmClickGuardIframe(iframe);
                return;
            }
            var now = Date.now();
            disarmClickGuardIframe(iframe);
            if (now - guard.lastClickAt < CLICK_GUARD_DEBOUNCE_MS) return;
            guard.lastClickAt = now;
            recordProbableAdClick(state.config);
        };
        state.clickGuard.storageListener = function (event) {
            if (!state.config || !state.clickGuard.settings || !state.clickGuard.settings.enabled) return;
            if (!event || event.key !== state.clickGuard.storageKey) return;
            var wasBlocked = state.clickGuard.blocked;
            var settings = clickGuardSettings(state.config);
            var persisted = emptyClickGuardState();
            if (event.newValue) persisted = storageValue(state.config, event.newValue, settings, Date.now());
            state.clickGuard.persisted = persisted;
            var blocked = applyClickGuardState(state.config, persisted);
            if (wasBlocked && !blocked && canRequestAds(state.config)) {
                installSpaSupport();
                scan(state.config);
            }
        };
        window.addEventListener('blur', state.clickGuard.blurListener);
        window.addEventListener('storage', state.clickGuard.storageListener);
        state.clickGuard.listenersInstalled = true;
    }

    function resetClickGuardRuntime() {
        var guard = state.clickGuard || freshClickGuardRuntime();
        clearClickGuardBlockTimer();
        (guard.trackedIframeEntries || []).slice().forEach(function (entry) { untrackClickGuardIframe(entry.iframe); });
        if (guard.listenersInstalled && window.removeEventListener) {
            if (guard.blurListener) window.removeEventListener('blur', guard.blurListener);
            if (guard.storageListener) window.removeEventListener('storage', guard.storageListener);
        }
        state.clickGuard = freshClickGuardRuntime();
    }

    function initializeClickGuard(config) {
        var settings = clickGuardSettings(config);
        var key = clickGuardStorageKey(config);
        if (state.clickGuard.storageKey && state.clickGuard.storageKey !== key) resetClickGuardRuntime();
        if (!settings.enabled) {
            if (state.clickGuard.listenersInstalled || state.clickGuard.trackedIframeEntries.length) resetClickGuardRuntime();
            state.clickGuard.storageKey = key;
            state.clickGuard.settings = settings;
            return false;
        }
        state.clickGuard.storageKey = key;
        state.clickGuard.settings = settings;
        state.clickGuard.storageAvailable = true;
        installClickGuardListeners(config);
        return applyClickGuardState(config, readClickGuardState(config));
    }

    function ensureGoogletagQueue() {
        window.googletag = window.googletag || { cmd: [] };
        window.googletag.cmd = window.googletag.cmd || [];
        return window.googletag;
    }

    function ensurePrebidQueue() {
        window.pbjs = window.pbjs || {};
        window.pbjs.que = window.pbjs.que || [];
        return window.pbjs;
    }

    function loadExternalScript(selector, marker, url) {
        return new Promise(function (resolve, reject) {
            try {
                if (new URL(url, window.location.href).hostname === 'app.horusmedia.net') {
                    reject(new Error('Control-plane URLs are forbidden in publisher runtime'));
                    return;
                }
            } catch (error) {
                reject(new Error('External script URL is invalid'));
                return;
            }
            var existing = document.querySelector ? document.querySelector(selector) : null;
            if (existing) {
                if (existing.getAttribute && existing.getAttribute('data-hm-loaded') === '1') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', function () { resolve(); }, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            var tag = document.createElement('script');
            tag.async = true;
            tag.src = url;
            tag.setAttribute(marker, '1');
            tag.onload = function () {
                tag.setAttribute('data-hm-loaded', '1');
                resolve();
            };
            tag.onerror = function () { reject(new Error(url + ' failed to load')); };
            (document.head || document.documentElement).appendChild(tag);
        });
    }

    function loadGpt(config) {
        if (!canRequestAds(config)) return Promise.resolve(null);
        var googletag = ensureGoogletagQueue();
        if (googletag.apiReady || googletag.pubadsReady) return Promise.resolve(googletag);
        if (state.gptPromise) return state.gptPromise;

        state.gptPromise = loadExternalScript(
            'script[data-hm-gpt="1"]',
            'data-hm-gpt',
            config.gpt && config.gpt.url || 'https://securepubads.g.doubleclick.net/tag/js/gpt.js'
        ).then(function () { return ensureGoogletagQueue(); });
        return state.gptPromise;
    }

    function resolvePrivacy(config) {
        if (state.privacyPromise) return state.privacyPromise;
        var privacy = config.privacy || {};
        var cmp = privacy.cmp || {};
        var timeout = Math.max(100, Number(cmp.timeoutMs || 1200));
        state.privacyPromise = new Promise(function (resolve) {
            var pending = 0;
            var settled = false;
            var tcfDone = false;
            var gppDone = false;
            var decision = { tcf: null, gpp: null, gpc: Boolean(window.navigator && window.navigator.globalPrivacyControl), limitedAds: false, blocked: false };
            function finish() {
                if (settled || pending > 0) return;
                settled = true;
                var tcfDenied = decision.tcf && decision.tcf.gdprApplies === true
                    && (!decision.tcf.purpose || !decision.tcf.purpose.consents || decision.tcf.purpose.consents[1] !== true);
                decision.limitedAds = decision.gpc || Boolean(tcfDenied) || decision.limitedAds;
                state.privacyDecision = decision;
                resolve(decision);
            }
            if (typeof window.__tcfapi === 'function') {
                pending += 1;
                try {
                    window.__tcfapi('addEventListener', 2, function (tcData, success) {
                        if (tcfDone || settled) return;
                        if (!success || !tcData || ['tcloaded', 'useractioncomplete'].indexOf(tcData.eventStatus) === -1) return;
                        tcfDone = true; decision.tcf = tcData; pending -= 1; finish();
                    });
                } catch (error) { tcfDone = true; pending -= 1; }
            }
            if (typeof window.__gpp === 'function') {
                pending += 1;
                try {
                    window.__gpp('ping', function (data, success) {
                        if (gppDone || settled) return;
                        gppDone = true; if (success !== false) decision.gpp = data || {}; pending -= 1; finish();
                    });
                } catch (error) { gppDone = true; pending -= 1; }
            }
            if (pending === 0 && String(privacy.mode || 'AUTO').toUpperCase() === 'STRICT' && privacy.requireConsentBeforeAds !== false) pending = 1;
            window.setTimeout(function () {
                if (settled) return;
                pending = 0;
                var timeoutAction = String(cmp.actionOnTimeout || 'LIMITED_ADS').toUpperCase();
                if (timeoutAction === 'LIMITED_ADS') decision.limitedAds = true;
                if (timeoutAction === 'BLOCK_ADS') decision.blocked = true;
                finish();
            }, timeout);
            finish();
        });
        return state.privacyPromise;
    }

    function loadPrebid(config) {
        if (!canRequestAds(config)) return Promise.resolve(null);
        var selected = config.prebid || {};
        if (!selected.enabled || !selected.build || !selected.build.url) return Promise.resolve(null);
        var pbjs = ensurePrebidQueue();
        if (typeof pbjs.requestBids === 'function') return Promise.resolve(pbjs);
        if (state.prebidPromise) return state.prebidPromise;

        state.prebidPromise = loadExternalScript(
            'script[data-hm-prebid="1"]',
            'data-hm-prebid',
            selected.build.url
        ).then(function () {
            var loaded = ensurePrebidQueue();
            if (typeof loaded.requestBids !== 'function') throw new Error('Prebid build did not initialize');
            return loaded;
        });
        return state.prebidPromise;
    }

    function normalizeSizes(sizes) {
        if (!Array.isArray(sizes)) return [];
        return sizes.filter(function (size) {
            return size === 'fluid' || (Array.isArray(size) && size.length === 2 && Number(size[0]) > 0 && Number(size[1]) > 0);
        });
    }

    function applyTargeting(target, values) {
        if (!target || !values) return;
        Object.keys(values).sort().forEach(function (key) {
            var normalized = Array.isArray(values[key]) ? values[key].map(String) : [String(values[key])];
            if (normalized.length && target.setTargeting) target.setTargeting(key, normalized);
        });
    }

    function buildSizeMapping(googletag, mappings) {
        if (!googletag.sizeMapping || !Array.isArray(mappings) || !mappings.length) return null;
        var builder = googletag.sizeMapping();
        var width = Number(window.innerWidth || document.documentElement && document.documentElement.clientWidth || 0);
        var height = Number(window.innerHeight || document.documentElement && document.documentElement.clientHeight || 0);
        mappings.forEach(function (mapping) {
            var viewport = Array.isArray(mapping.viewport) ? mapping.viewport : [0, 0];
            var maximum = Array.isArray(mapping.maxViewport) ? mapping.maxViewport : [];
            if ((maximum[0] && width > Number(maximum[0])) || (maximum[1] && height > Number(maximum[1]))) return;
            var sizes = normalizeSizes(mapping.sizes);
            if (sizes.length) builder.addSize(viewport, sizes);
        });
        return builder.build();
    }

    function ensureElementId(element, config, placement) {
        if (element.id) return element.id;
        var safeSite = String(config.siteKey).replace(/[^A-Za-z0-9_-]/g, '');
        var safePlacement = String(placement.code).replace(/[^A-Za-z0-9_-]/g, '');
        element.id = 'hm-' + safeSite + '-' + safePlacement + '-' + Object.keys(state.slots).length;
        return element.id;
    }

    function nativeDefinition(config, code) {
        var native = config.nativeDemand || {};
        return native.enabled && native.placements ? native.placements[code] || null : null;
    }

    function nodeList(selector) {
        return document.querySelectorAll ? document.querySelectorAll(selector) : [];
    }

    function eligibleElements(config) {
        var nodes = [];
        Array.prototype.forEach.call(nodeList('.hm-ad[data-placement]'), function (node) { nodes.push(node); });
        Array.prototype.forEach.call(nodeList('.hm-native[data-placement]'), function (node) {
            if (nodes.indexOf(node) === -1) nodes.push(node);
        });
        var placements = {};
        (config.placements || []).forEach(function (placement) { placements[placement.code] = placement; });
        var result = [];
        nodes.forEach(function (element) {
            var code = element.getAttribute('data-placement');
            var placement = placements[code];
            var native = nativeDefinition(config, code);
            var hasNative = native && native.enabled && ((native.candidates || []).length || native.house);
            if (!placement || !placement.enabled || placement.status !== 'active') return;
            if (!placement.adUnitPath && !hasNative) return;
            if (element.getAttribute('data-hm-defined') === '1') return;
            result.push({ element: element, placement: placement, native: native });
        });
        return result;
    }

    function lazyOptions(items, config) {
        var globalSetting = config.prebid && config.prebid.delivery && config.prebid.delivery.lazyLoading;
        if (globalSetting && globalSetting.enabled === false) return null;
        var enabled = items.map(function (item) { return item.placement.lazyLoad || {}; }).filter(function (item) { return item.enabled; });
        if (!enabled.length) return null;
        return {
            fetchMarginPercent: Math.max.apply(Math, enabled.map(function (item) { return Number(item.fetchMarginPercent || 0); })),
            renderMarginPercent: Math.max.apply(Math, enabled.map(function (item) { return Number(item.renderMarginPercent || 0); })),
            mobileScaling: Math.max.apply(Math, enabled.map(function (item) { return Number(item.mobileScaling || 1); }))
        };
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function prebidAdUnits(config, entries) {
        var templates = {};
        ((config.prebid && config.prebid.adUnits) || []).forEach(function (adUnit) { templates[adUnit.code] = adUnit; });
        return entries.map(function (entry) {
            var template = templates[entry.placement.code];
            if (!template) return null;
            var adUnit = clone(template);
            adUnit.code = entry.element.id;
            return adUnit;
        }).filter(Boolean);
    }

    function installPrebidEvents(config, pbjs) {
        if (state.prebidEventsInstalled || !pbjs || !pbjs.onEvent) return;
        state.prebidEventsInstalled = true;
        if (config.prebid && config.prebid.delivery && config.prebid.delivery.bidderTimeoutReporting) {
            pbjs.onEvent('bidTimeout', function (bidders) {
                var diagnostics = window.__HM_DIAGNOSTICS__ = window.__HM_DIAGNOSTICS__ || {};
                diagnostics.bidTimeouts = (diagnostics.bidTimeouts || 0) + (Array.isArray(bidders) ? bidders.length : 1);
                log(config, 'Prebid bidder timeout', bidders);
            });
        }
    }

    function configurePrebid(config, pbjs) {
        var auction = config.prebid && config.prebid.auction || {};
        var prebidConfig = {
            bidderSequence: auction.bidderSequence || 'fixed',
            priceGranularity: auction.priceGranularity || 'medium',
            storageControl: { enforce: true },
            allowActivities: auction.allowActivities || {}
        };
        if (auction.currency) prebidConfig.currency = { adServerCurrency: auction.currency };
        if (auction.consent && Object.keys(auction.consent).length) prebidConfig.consentManagement = auction.consent;
        if (auction.ortb2) prebidConfig.ortb2 = auction.ortb2;
        if (config.supplyChain && config.supplyChain.schain && config.supplyChain.schain.nodes && config.supplyChain.schain.nodes.length) {
            prebidConfig.ortb2 = prebidConfig.ortb2 || {};
            prebidConfig.ortb2.source = prebidConfig.ortb2.source || {};
            prebidConfig.ortb2.source.ext = prebidConfig.ortb2.source.ext || {};
            prebidConfig.ortb2.source.ext.schain = config.supplyChain.schain;
        }
        if (pbjs.setConfig) pbjs.setConfig(prebidConfig);
        installPrebidEvents(config, pbjs);
    }

    function hasBid(pbjs, codes) {
        if (!pbjs || !pbjs.getBidResponsesForAdUnitCode) return false;
        return codes.some(function (code) {
            var response = pbjs.getBidResponsesForAdUnitCode(code);
            return response && Array.isArray(response.bids) && response.bids.length > 0;
        });
    }

    function requestGam(config, pubads, entries) {
        if (!canRequestAds(config) || !entries.length || !pubads || !pubads.refresh) return;
        pubads.refresh(entries.map(function (entry) { return entry.slot; }));
        entries.forEach(function (entry) { entry.element.setAttribute('data-hm-status', 'requested'); });
    }

    function requestEntries(config, googletag, pubads, entries) {
        if (!canRequestAds(config) || !entries.length) return Promise.resolve([]);
        var prebid = config.prebid || {};
        if (!prebid.enabled) {
            requestGam(config, pubads, entries);
            return Promise.resolve(entries);
        }

        var fallback = !prebid.delivery || prebid.delivery.gamFallback !== false;
        var adUnits = prebidAdUnits(config, entries);
        if (!adUnits.length) {
            if (fallback) requestGam(config, pubads, entries);
            return Promise.resolve(entries);
        }

        return loadPrebid(config).then(function (pbjs) {
            if (!pbjs || !canRequestAds(config)) return [];
            return new Promise(function (resolve) {
                var finished = false;
                var timeout = Math.max(100, Number(prebid.auction && prebid.auction.timeoutMs || 1200));
                function complete(timedOut) {
                    if (finished) return;
                    finished = true;
                    if (!canRequestAds(config)) { resolve([]); return; }
                    try {
                        var codes = adUnits.map(function (adUnit) { return adUnit.code; });
                        if (pbjs.setTargetingForGPTAsync) pbjs.setTargetingForGPTAsync(codes);
                        if (fallback || hasBid(pbjs, codes)) requestGam(config, pubads, entries);
                        entries.forEach(function (entry) {
                            entry.element.setAttribute('data-hm-prebid', timedOut ? 'timeout' : 'complete');
                        });
                    } catch (error) {
                        log(config, 'Prebid targeting failed', error);
                        if (fallback) requestGam(config, pubads, entries);
                    }
                    resolve(entries);
                }

                window.setTimeout(function () { complete(true); }, timeout + 100);
                pbjs.que.push(function () {
                    if (!canRequestAds(config)) { resolve([]); return; }
                    try {
                        configurePrebid(config, pbjs);
                        var codes = adUnits.map(function (adUnit) { return adUnit.code; });
                        if (pbjs.removeAdUnit) pbjs.removeAdUnit(codes);
                        if (pbjs.addAdUnits) pbjs.addAdUnits(adUnits);
                        pbjs.requestBids({
                            adUnitCodes: codes,
                            timeout: timeout,
                            bidsBackHandler: function () { complete(false); }
                        });
                    } catch (error) {
                        log(config, 'Prebid auction failed', error);
                        complete(false);
                    }
                });
            });
        }).catch(function (error) {
            log(config, 'Prebid unavailable; continuing with GAM', error);
            entries.forEach(function (entry) { entry.element.setAttribute('data-hm-prebid', 'failed'); });
            if (fallback && canRequestAds(config)) requestGam(config, pubads, entries);
            return entries;
        });
    }

    function candidateRank(config, candidate) {
        var fallback = config.nativeDemand && config.nativeDemand.fallbackOrder || [];
        var index = fallback.indexOf(candidate.network);
        return Number(candidate.priority || 1000) * 100 + (index === -1 ? 99 : index);
    }

    function directCandidates(config, entry) {
        var candidates = entry.native && Array.isArray(entry.native.candidates) ? entry.native.candidates.slice() : [];
        return candidates.filter(function (candidate) {
            return candidate && !candidate.gamManaged && candidate.tag && candidate.tag.scriptUrl;
        }).sort(function (left, right) {
            return candidateRank(config, left) - candidateRank(config, right);
        });
    }

    function setCandidateAttributes(script, attributes) {
        if (!attributes) return;
        Object.keys(attributes).forEach(function (key) {
            if (/^[A-Za-z_:][-A-Za-z0-9_:.]*$/.test(key)) script.setAttribute(key, String(attributes[key]));
        });
    }

    function nativeContainer(entry, candidate) {
        var tag = candidate.tag || {};
        var container = document.createElement('div');
        container.id = String(tag.containerId || (entry.element.id + '-native')).replace(/[^A-Za-z0-9_:-]/g, '-');
        container.className = String(tag.containerClass || 'hm-native-container');
        container.setAttribute('data-hm-native-network', String(candidate.network || 'CUSTOM'));
        if (entry.element.appendChild) entry.element.appendChild(container);
        return container;
    }

    function nativeRendered(container, tag) {
        if (tag.successSelector && document.querySelector) {
            try {
                if (document.querySelector(tag.successSelector)) return true;
            } catch (error) {
                return false;
            }
        }
        if (tag.assumeLoadedIsSuccess) return true;
        if (container && container.childNodes && container.childNodes.length) return true;
        return Boolean(container && typeof container.innerHTML === 'string' && container.innerHTML.replace(/\s/g, '') !== '');
    }

    function renderHouse(config, entry) {
        var house = entry.native && entry.native.house;
        if (!house || !house.html) {
            entry.element.setAttribute('data-hm-native', 'exhausted');
            entry.element.setAttribute('data-hm-status', 'empty');
            return false;
        }
        if (typeof entry.element.innerHTML === 'string') entry.element.innerHTML = house.html;
        entry.element.setAttribute('data-hm-native', 'HOUSE');
        entry.element.setAttribute('data-hm-status', 'rendered');
        state.nativeRendered[entry.element.id] = 'HOUSE';
        log(config, 'Native fallback rendered house content', entry.placement.code);
        return true;
    }

    function runNativeFallback(config, entry) {
        if (!canRequestAds(config) || !entry || !entry.native || !entry.native.enabled) return Promise.resolve(false);
        var key = entry.element.id || ensureElementId(entry.element, config, entry.placement);
        if (state.nativeRendered[key]) return Promise.resolve(true);
        if (state.nativeAttempts[key]) return state.nativeAttempts[key];

        var candidates = directCandidates(config, entry);
        state.nativeAttempts[key] = new Promise(function (resolve) {
            function tryCandidate(index) {
                if (!canRequestAds(config)) { resolve(false); return; }
                if (index >= candidates.length) {
                    resolve(renderHouse(config, entry));
                    return;
                }

                var candidate = candidates[index];
                var tag = candidate.tag || {};
                var container = nativeContainer(entry, candidate);
                var script = document.createElement('script');
                script.async = true;
                script.src = tag.scriptUrl;
                script.setAttribute('data-hm-native-script', String(candidate.network || 'CUSTOM'));
                setCandidateAttributes(script, tag.attributes || {});
                var settled = false;

                function failed(reason) {
                    if (settled) return;
                    settled = true;
                    entry.element.setAttribute('data-hm-native-last-error', String(reason || 'no-fill'));
                    log(config, 'Native candidate failed', candidate.network, reason);
                    tryCandidate(index + 1);
                }

                script.onerror = function () { failed('script-error'); };
                script.onload = function () {
                    var timeout = Math.max(0, Number(tag.renderTimeoutMs || 1500));
                    window.setTimeout(function () {
                        if (settled) return;
                        if (!nativeRendered(container, tag)) {
                            failed('no-render');
                            return;
                        }
                        settled = true;
                        state.nativeRendered[key] = candidate.network;
                        entry.element.setAttribute('data-hm-native', String(candidate.network));
                        entry.element.setAttribute('data-hm-status', 'rendered');
                        log(config, 'Native candidate rendered', candidate.network, entry.placement.code);
                        resolve(true);
                    }, timeout);
                };

                try {
                    (document.head || document.documentElement).appendChild(script);
                } catch (error) {
                    failed(error && error.message || 'injection-error');
                }
            }

            tryCandidate(0);
        }).finally(function () {
            delete state.nativeAttempts[key];
        });

        return state.nativeAttempts[key];
    }

    function installGamEmptyFallback(config, pubads) {
        if (state.gamFallbackInstalled || !pubads || !pubads.addEventListener) return;
        state.gamFallbackInstalled = true;
        pubads.addEventListener('slotRenderEnded', function (event) {
            if (!canRequestAds(config)) return;
            var entry = null;
            Object.keys(state.slots).some(function (key) {
                if (state.slots[key].slot === event.slot) {
                    entry = state.slots[key];
                    return true;
                }
                return false;
            });
            if (!entry) return;
            if (event && event.isEmpty) {
                entry.element.setAttribute('data-hm-gam', 'empty');
                runNativeFallback(config, entry);
            } else {
                entry.element.setAttribute('data-hm-gam', 'rendered');
                entry.element.setAttribute('data-hm-status', 'rendered');
            }
        });
    }

    function defineItems(config, items) {
        if (!canRequestAds(config) || !items.length) return Promise.resolve([]);
        var nativeOnly = items.filter(function (item) { return !item.placement.adUnitPath; });
        var gamItems = items.filter(function (item) { return Boolean(item.placement.adUnitPath); });

        nativeOnly.forEach(function (item) {
            ensureElementId(item.element, config, item.placement);
            item.element.setAttribute('data-hm-defined', '1');
            item.element.setAttribute('data-hm-status', 'fallback');
        });
        var nativePromise = Promise.all(nativeOnly.map(function (item) { return runNativeFallback(config, item); }));
        if (!gamItems.length) return nativePromise.then(function () { diagnostics(config, []); return nativeOnly; });

        return loadGpt(config).then(function (googletag) {
            if (!googletag || !canRequestAds(config)) return [];
            return new Promise(function (resolve) {
                googletag.cmd.push(function () {
                    if (!canRequestAds(config)) { resolve(nativeOnly); return; }
                    try {
                        var pubads = googletag.pubads();
                        var privacy = state.privacyDecision || {};
                        var privacySignals = config.privacy && config.privacy.signals || {};
                        var unifiedConfig = typeof googletag.setConfig === 'function';
                        if (googletag.setConfig) {
                            var pageConfig = Object.assign({}, config.gpt && config.gpt.config || {});
                            if (privacy.gpc || privacy.limitedAds) pageConfig.privacyTreatments = { treatments: ['disablePersonalization'] };
                            pageConfig.disableInitialLoad = true;
                            pageConfig.singleRequest = Boolean(config.gpt && config.gpt.singleRequest);
                            googletag.setConfig(pageConfig);
                            state.initialLoadDisabled = true;
                        }
                        if (pubads.setPrivacySettings) {
                            var privacySettings = {
                                childDirectedTreatment: Boolean(privacySignals.coppa),
                                underAgeOfConsent: Boolean(privacySignals.underAgeOfConsent),
                                restrictDataProcessing: Boolean(privacy.gpc),
                                nonPersonalizedAds: Boolean(privacy.limitedAds)
                            };
                            var age = String(privacySignals.ageTreatment || '').toUpperCase();
                            if (age && googletag.enums && googletag.enums.TagForAgeTreatment && googletag.enums.TagForAgeTreatment[age]) {
                                privacySettings.tagForAgeTreatment = googletag.enums.TagForAgeTreatment[age];
                            }
                            pubads.setPrivacySettings(privacySettings);
                        } else {
                            if (pubads.setTagForChildDirectedTreatment) pubads.setTagForChildDirectedTreatment(privacySignals.coppa ? 1 : 0);
                            if (pubads.setTagForUnderAgeOfConsent) pubads.setTagForUnderAgeOfConsent(privacySignals.underAgeOfConsent ? 1 : 0);
                        }
                        applyTargeting(pubads, config.pageTargeting || {});
                        if (privacy.gpc) pubads.setTargeting('hm_gpc', ['1']);
                        if (privacy.limitedAds) pubads.setTargeting('hm_limited_ads', ['1']);
                        var lazy = lazyOptions(gamItems, config);
                        if (lazy && pubads.enableLazyLoad) pubads.enableLazyLoad(lazy);
                        if (!unifiedConfig && config.gpt && config.gpt.singleRequest && pubads.enableSingleRequest && !state.servicesEnabled) pubads.enableSingleRequest();
                        if (!unifiedConfig && !state.initialLoadDisabled && pubads.disableInitialLoad) {
                            pubads.disableInitialLoad();
                            state.initialLoadDisabled = true;
                        }
                        installGamEmptyFallback(config, pubads);

                        var defined = [];
                        gamItems.forEach(function (item) {
                            var placement = item.placement;
                            var element = item.element;
                            var elementId = ensureElementId(element, config, placement);
                            var formatSettings = placement.format && placement.format.settings || {};
                            if (element.style && formatSettings.reserveSpace !== false) {
                                var firstSize = normalizeSizes(placement.sizes).filter(Array.isArray)[0];
                                if (firstSize) { element.style.minWidth = firstSize[0] + 'px'; element.style.minHeight = firstSize[1] + 'px'; }
                            }
                            if (placement.format && placement.format.code) element.setAttribute('data-hm-format', placement.format.code);
                            if (formatSettings.position && element.style && placement.type === 'STICKY') {
                                element.style.position = 'fixed'; element.style.zIndex = '2147483000'; element.style.left = '50%'; element.style.transform = 'translateX(-50%)';
                                element.style[formatSettings.position === 'top' ? 'top' : 'bottom'] = '0';
                            }
                            var slot = null;
                            if (placement.outOfPageFormat && googletag.defineOutOfPageSlot && googletag.enums && googletag.enums.OutOfPageFormat) {
                                var format = googletag.enums.OutOfPageFormat[placement.outOfPageFormat];
                                if (format) slot = googletag.defineOutOfPageSlot(placement.adUnitPath, format);
                            } else if (googletag.defineSlot) {
                                slot = googletag.defineSlot(placement.adUnitPath, normalizeSizes(placement.sizes), elementId);
                            }
                            if (!slot) return;
                            if (slot.setConfig && placement.type === 'INTERSTITIAL') {
                                var configuredTriggers = (formatSettings.triggers || []).reduce(function (values, trigger) {
                                    if (trigger !== 'backward') values[trigger] = true;
                                    return values;
                                }, {});
                                slot.setConfig({ interstitial: { triggers: configuredTriggers, requireStorageAccess: Boolean(formatSettings.requireStorageAccess) } });
                            }
                            var mapping = buildSizeMapping(googletag, placement.responsiveMappings);
                            if (mapping && slot.defineSizeMapping) slot.defineSizeMapping(mapping);
                            applyTargeting(slot, placement.targeting || {});
                            if (slot.setForceSafeFrame) slot.setForceSafeFrame(Boolean(placement.safeFrame));
                            if (slot.setCollapseEmptyDiv) slot.setCollapseEmptyDiv(Boolean(placement.collapseEmptyDiv), Boolean(placement.collapseEmptyDiv));
                            if (slot.addService) slot.addService(pubads);
                            element.setAttribute('data-hm-defined', '1');
                            element.setAttribute('data-hm-status', 'defined');
                            state.slots[elementId] = { slot: slot, placement: placement, element: element, native: item.native, refreshCount: 0 };
                            defined.push(state.slots[elementId]);
                        });

                        if (!state.servicesEnabled && googletag.enableServices) {
                            googletag.enableServices();
                            state.servicesEnabled = true;
                        }
                        defined.forEach(function (entry) {
                            if (entry.placement.outOfPageFormat) googletag.display(entry.slot);
                            else googletag.display(entry.element.id);
                        });

                        installRewardedLifecycle(config, pubads);

                        requestEntries(config, googletag, pubads, defined).then(function () {
                            defined.forEach(function (entry) { scheduleRefresh(config, googletag, pubads, entry); });
                            nativePromise.then(function () {
                                diagnostics(config, defined);
                                resolve(defined.concat(nativeOnly));
                            });
                        });
                    } catch (error) {
                        log(config, 'Slot definition failed', error);
                        nativePromise.then(function () { resolve(nativeOnly); });
                    }
                });
            });
        }).catch(function (error) {
            log(config, 'GPT unavailable; trying native demand', error);
            return Promise.all(items.map(function (item) {
                ensureElementId(item.element, config, item.placement);
                item.element.setAttribute('data-hm-defined', '1');
                return runNativeFallback(config, item);
            })).then(function () { return items; });
        });
    }

    function installRewardedLifecycle(config, pubads) {
        if (state.rewardedListenersInstalled || !pubads || !pubads.addEventListener) return;
        state.rewardedListenersInstalled = true;
        pubads.addEventListener('rewardedSlotReady', function (event) {
            window.dispatchEvent(new CustomEvent('horus:rewarded-ready', { detail: { makeRewardedVisible: event.makeRewardedVisible } }));
        });
        pubads.addEventListener('rewardedSlotGranted', function (event) {
            window.dispatchEvent(new CustomEvent('horus:rewarded-granted', { detail: event.payload || {} }));
        });
        pubads.addEventListener('rewardedSlotClosed', function () { window.dispatchEvent(new Event('horus:rewarded-closed')); });
    }

    function scheduleRefresh(config, googletag, pubads, entry) {
        if (!canRequestAds(config)) return;
        var refresh = entry.placement.refresh || {};
        var prebidRefresh = config.prebid && config.prebid.delivery && config.prebid.delivery.refreshBehavior || {};
        if (prebidRefresh.enabled === false) return;
        var minimum = Math.max(30, Number(prebidRefresh.minimumIntervalSeconds || 30));
        var interval = Number(refresh.intervalSeconds || 0);
        if (!refresh.enabled || interval < minimum) return;
        var limit = Number(refresh.limit || 0);
        var key = entry.element.id;
        if (state.refreshTimers[key]) window.clearInterval(state.refreshTimers[key]);
        state.refreshTimers[key] = window.setInterval(function () {
            if (!canRequestAds(config)) {
                window.clearInterval(state.refreshTimers[key]);
                delete state.refreshTimers[key];
                return;
            }
            if (document.visibilityState && document.visibilityState !== 'visible') return;
            if (limit > 0 && entry.refreshCount >= limit) {
                window.clearInterval(state.refreshTimers[key]);
                delete state.refreshTimers[key];
                return;
            }
            entry.refreshCount += 1;
            googletag.cmd.push(function () {
                if (canRequestAds(config)) requestEntries(config, googletag, pubads, [entry]);
            });
        }, interval * 1000);
    }

    function diagnostics(config, defined) {
        if (!config.debug) return;
        window.__HM_DIAGNOSTICS__ = Object.assign(window.__HM_DIAGNOSTICS__ || {}, {
            loaderVersion: VERSION,
            configVersion: config.configVersion,
            siteKey: config.siteKey,
            hostname: currentHostname(),
            servingMode: config.servingMode,
            gamNetworkCode: config.gamNetworkCode,
            prebidEnabled: Boolean(config.prebid && config.prebid.enabled),
            prebidBuild: config.prebid && config.prebid.build && config.prebid.build.version,
            nativeDemandEnabled: Boolean(config.nativeDemand && config.nativeDemand.enabled),
            nativeRendered: Object.assign({}, state.nativeRendered),
            clickGuard: {
                enabled: Boolean(state.clickGuard.settings && state.clickGuard.settings.enabled),
                blocked: Boolean(state.clickGuard.blocked),
                clicksInWindow: state.clickGuard.persisted && state.clickGuard.persisted.clicks ? state.clickGuard.persisted.clicks.length : 0,
                blockedUntil: state.clickGuard.blocked ? state.clickGuard.persisted.blockedUntil : null
            },
            definedPlacements: defined.map(function (entry) { return entry.placement.code; })
        });
        log(config, 'Diagnostics', window.__HM_DIAGNOSTICS__);
    }

    function servingDisabled(config) {
        return Boolean(config && config.controls && config.controls.adServingDisabled);
    }

    function scan(config) {
        if (!canRequestAds(config)) return Promise.resolve([]);
        discoverClickGuardIframes(config);
        return defineItems(config, eligibleElements(config)).then(function (defined) {
            discoverClickGuardIframes(config);
            return defined;
        });
    }

    function installSpaSupport() {
        if (!state.observer && window.MutationObserver && document.documentElement) {
            state.observer = new window.MutationObserver(function (mutations) {
                inspectClickGuardMutations(state.config, mutations || []);
                window.clearTimeout(state.scanTimer);
                state.scanTimer = window.setTimeout(function () { scan(state.config); }, 25);
            });
            state.observer.observe(document.documentElement, { childList: true, subtree: true });
            discoverClickGuardIframes(state.config);
        }
        if (state.navigationPatched || !window.history) return;
        state.navigationPatched = true;
        ['pushState', 'replaceState'].forEach(function (method) {
            var original = window.history[method];
            if (typeof original !== 'function') return;
            window.history[method] = function () {
                var value = original.apply(this, arguments);
                window.dispatchEvent(new Event('horus:navigation'));
                return value;
            };
        });
        window.addEventListener('popstate', function () { window.dispatchEvent(new Event('horus:navigation')); });
        window.addEventListener('horus:navigation', function () { window.setTimeout(function () { scan(state.config); }, 0); });
    }

    function maybeDelegateRelease(config, script) {
        var selected = config.loader || {};
        if (!selected.assetUrl || !selected.version || selected.version === VERSION || window.__HM_RELEASE_DELEGATED__) return false;
        try {
            if (new URL(selected.assetUrl, window.location.href).hostname === 'app.horusmedia.net') return false;
        } catch (error) {
            return false;
        }
        window.__HM_RELEASE_DELEGATED__ = true;
        var replacement = document.createElement('script');
        replacement.async = true;
        replacement.src = selected.assetUrl + (selected.assetUrl.indexOf('?') === -1 ? '?' : '&') + 'v=' + encodeURIComponent(selected.version);
        replacement.setAttribute('data-site-key', config.siteKey);
        replacement.setAttribute('data-config-base', configBase(script));
        replacement.setAttribute('data-environment', String(scriptData(script, 'environment') || 'production'));
        (document.head || document.documentElement).appendChild(replacement);
        return true;
    }

    function boot(options) {
        options = options || {};
        var script = options.script || findScript();
        var siteKey = options.siteKey || scriptData(script, 'siteKey');
        if (!siteKey || !window.fetch) return Promise.resolve([]);
        if (state.booting && !options.force) return state.booting;

        state.booting = fetchGlobalControl(script, Boolean(options.force)).then(function (globalControls) {
            if (globalControls.adServingDisabled) {
                state.config = { siteKey: siteKey, status: 'paused', controls: globalControls };
                log(state.config, 'Global advertising kill switch is active');
                return null;
            }
            return fetchConfig(script, siteKey, Boolean(options.force)).then(function (config) {
                config.controls = Object.assign({}, config.controls || {}, globalControls || {});
                return resolvePrivacy(config).then(function () { return config; });
            });
        }).then(function (config) {
            if (!config) return [];
            state.config = config;
            if (!hostAllowed(currentHostname(), config.allowedHostnames)) {
                log(config, 'Hostname rejected', currentHostname());
                return [];
            }
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
                log(config, 'Click Guard blocked future advertising requests in this browser');
                return [];
            }
            if (maybeDelegateRelease(config, script)) return [];
            installSpaSupport();
            return scan(config);
        }).catch(function (error) {
            log({ debug: Boolean(scriptData(script, 'debug')) }, 'Loader stopped safely', error);
            return [];
        }).finally(function () {
            state.booting = null;
        });
        return state.booting;
    }

    window.HorusMediaLoader = {
        version: VERSION,
        boot: boot,
        refresh: function () { return boot({ force: true }); },
        scan: function () { return scan(state.config); },
        getConfig: function () { return state.config; },
        _resetForTests: function () {
            clearAllRefreshTimers();
            resetClickGuardRuntime();
            state.config = null;
            state.gptPromise = null;
            state.prebidPromise = null;
            state.servicesEnabled = false;
            state.initialLoadDisabled = false;
            state.slots = {};
            state.refreshTimers = {};
            state.booting = null;
            state.script = null;
            state.prebidEventsInstalled = false;
            state.gamFallbackInstalled = false;
            state.nativeAttempts = {};
            state.nativeRendered = {};
        }
    };

    if (!window.__HM_DISABLE_AUTOBOOT__) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { boot(); }, { once: true });
        else boot();
    }
}(window, document));
