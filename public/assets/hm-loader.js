(function (window, document) {
    'use strict';

    var VERSION = '1.1.0';
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
        prebidEventsInstalled: false
    };

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
        if (explicit) return explicit.replace(/\/$/, '');
        try {
            return new URL(script.src, window.location.href).origin + '/configs';
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

    function buildConfigUrl(script, siteKey, force) {
        var environment = String(scriptData(script, 'environment') || 'production').toLowerCase();
        var selectedVersion = scriptData(script, 'configVersion');
        var url = configBase(script) + '/' + encodeURIComponent(siteKey) + '/' + environment + '.json';
        var bust = selectedVersion || (force ? Date.now() : null);
        return bust ? url + '?v=' + encodeURIComponent(bust) : url;
    }

    function fetchConfig(script, siteKey, force) {
        var url = buildConfigUrl(script, siteKey, force);
        return window.fetch(url, { method: 'GET', mode: 'cors', credentials: 'omit', cache: force ? 'reload' : 'default' })
            .then(function (response) {
                if (!response || !response.ok) throw new Error('Static configuration unavailable');
                return response.json();
            })
            .then(function (config) {
                if (!config || config.siteKey !== siteKey) throw new Error('Static configuration site key mismatch');
                return config;
            });
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
            var existing = document.querySelector ? document.querySelector(selector) : null;
            if (existing) {
                existing.addEventListener('load', function () { resolve(); }, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            var tag = document.createElement('script');
            tag.async = true;
            tag.src = url;
            tag.setAttribute(marker, '1');
            tag.onload = resolve;
            tag.onerror = function () { reject(new Error(url + ' failed to load')); };
            (document.head || document.documentElement).appendChild(tag);
        });
    }

    function loadGpt(config) {
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

    function loadPrebid(config) {
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
        mappings.forEach(function (mapping) {
            var viewport = Array.isArray(mapping.viewport) ? mapping.viewport : [0, 0];
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

    function eligibleElements(config) {
        var nodes = document.querySelectorAll ? document.querySelectorAll('.hm-ad[data-placement]') : [];
        var placements = {};
        (config.placements || []).forEach(function (placement) { placements[placement.code] = placement; });
        var result = [];
        Array.prototype.forEach.call(nodes, function (element) {
            var code = element.getAttribute('data-placement');
            var placement = placements[code];
            if (!placement || !placement.enabled || placement.status !== 'active' || !placement.adUnitPath) return;
            if (element.getAttribute('data-hm-defined') === '1') return;
            result.push({ element: element, placement: placement });
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
            priceGranularity: auction.priceGranularity || 'medium'
        };
        if (auction.currency) prebidConfig.currency = { adServerCurrency: auction.currency };
        if (auction.consent && Object.keys(auction.consent).length) prebidConfig.consentManagement = auction.consent;
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

    function requestGam(pubads, entries) {
        if (!entries.length || !pubads || !pubads.refresh) return;
        pubads.refresh(entries.map(function (entry) { return entry.slot; }));
        entries.forEach(function (entry) { entry.element.setAttribute('data-hm-status', 'requested'); });
    }

    function requestEntries(config, googletag, pubads, entries) {
        if (!entries.length) return Promise.resolve([]);
        var prebid = config.prebid || {};
        if (!prebid.enabled) {
            requestGam(pubads, entries);
            return Promise.resolve(entries);
        }

        var fallback = !prebid.delivery || prebid.delivery.gamFallback !== false;
        var adUnits = prebidAdUnits(config, entries);
        if (!adUnits.length) {
            if (fallback) requestGam(pubads, entries);
            return Promise.resolve(entries);
        }

        return loadPrebid(config).then(function (pbjs) {
            return new Promise(function (resolve) {
                var finished = false;
                var timeout = Math.max(100, Number(prebid.auction && prebid.auction.timeoutMs || 1200));
                function complete(timedOut) {
                    if (finished) return;
                    finished = true;
                    try {
                        var codes = adUnits.map(function (adUnit) { return adUnit.code; });
                        if (pbjs.setTargetingForGPTAsync) pbjs.setTargetingForGPTAsync(codes);
                        if (fallback || hasBid(pbjs, codes)) requestGam(pubads, entries);
                        entries.forEach(function (entry) {
                            entry.element.setAttribute('data-hm-prebid', timedOut ? 'timeout' : 'complete');
                        });
                    } catch (error) {
                        log(config, 'Prebid targeting failed', error);
                        if (fallback) requestGam(pubads, entries);
                    }
                    resolve(entries);
                }

                window.setTimeout(function () { complete(true); }, timeout + 100);
                pbjs.que.push(function () {
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
            if (fallback) requestGam(pubads, entries);
            return entries;
        });
    }

    function defineItems(config, items) {
        if (!items.length) return Promise.resolve([]);
        return loadGpt(config).then(function (googletag) {
            return new Promise(function (resolve) {
                googletag.cmd.push(function () {
                    try {
                        var pubads = googletag.pubads();
                        applyTargeting(pubads, config.pageTargeting || {});
                        var lazy = lazyOptions(items, config);
                        if (lazy && pubads.enableLazyLoad) pubads.enableLazyLoad(lazy);
                        if (config.gpt && config.gpt.singleRequest && pubads.enableSingleRequest && !state.servicesEnabled) pubads.enableSingleRequest();
                        if (!state.initialLoadDisabled && pubads.disableInitialLoad) {
                            pubads.disableInitialLoad();
                            state.initialLoadDisabled = true;
                        }

                        var defined = [];
                        items.forEach(function (item) {
                            var placement = item.placement;
                            var element = item.element;
                            var elementId = ensureElementId(element, config, placement);
                            var slot = null;
                            if (placement.outOfPageFormat && googletag.defineOutOfPageSlot && googletag.enums && googletag.enums.OutOfPageFormat) {
                                var format = googletag.enums.OutOfPageFormat[placement.outOfPageFormat];
                                if (format) slot = googletag.defineOutOfPageSlot(placement.adUnitPath, format);
                            } else if (googletag.defineSlot) {
                                slot = googletag.defineSlot(placement.adUnitPath, normalizeSizes(placement.sizes), elementId);
                            }
                            if (!slot) return;
                            var mapping = buildSizeMapping(googletag, placement.responsiveMappings);
                            if (mapping && slot.defineSizeMapping) slot.defineSizeMapping(mapping);
                            applyTargeting(slot, placement.targeting || {});
                            if (slot.setForceSafeFrame) slot.setForceSafeFrame(Boolean(placement.safeFrame));
                            if (slot.setCollapseEmptyDiv) slot.setCollapseEmptyDiv(Boolean(placement.collapseEmptyDiv), Boolean(placement.collapseEmptyDiv));
                            if (slot.addService) slot.addService(pubads);
                            element.setAttribute('data-hm-defined', '1');
                            element.setAttribute('data-hm-status', 'defined');
                            state.slots[elementId] = { slot: slot, placement: placement, element: element, refreshCount: 0 };
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

                        requestEntries(config, googletag, pubads, defined).then(function () {
                            defined.forEach(function (entry) { scheduleRefresh(config, googletag, pubads, entry); });
                            diagnostics(config, defined);
                            resolve(defined);
                        });
                    } catch (error) {
                        log(config, 'Slot definition failed', error);
                        resolve([]);
                    }
                });
            });
        }).catch(function (error) {
            log(config, 'GPT unavailable', error);
            return [];
        });
    }

    function scheduleRefresh(config, googletag, pubads, entry) {
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
            if (document.visibilityState && document.visibilityState !== 'visible') return;
            if (limit > 0 && entry.refreshCount >= limit) {
                window.clearInterval(state.refreshTimers[key]);
                delete state.refreshTimers[key];
                return;
            }
            entry.refreshCount += 1;
            googletag.cmd.push(function () { requestEntries(config, googletag, pubads, [entry]); });
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
            definedPlacements: defined.map(function (entry) { return entry.placement.code; })
        });
        log(config, 'Diagnostics', window.__HM_DIAGNOSTICS__);
    }

    function scan(config) {
        if (!config || config.status !== 'active' || config.immediatePause) return Promise.resolve([]);
        return defineItems(config, eligibleElements(config));
    }

    function installSpaSupport() {
        if (!state.observer && window.MutationObserver && document.documentElement) {
            state.observer = new window.MutationObserver(function () {
                window.clearTimeout(state.scanTimer);
                state.scanTimer = window.setTimeout(function () { scan(state.config); }, 25);
            });
            state.observer.observe(document.documentElement, { childList: true, subtree: true });
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

        state.booting = fetchConfig(script, siteKey, Boolean(options.force)).then(function (config) {
            state.config = config;
            if (!hostAllowed(currentHostname(), config.allowedHostnames)) {
                log(config, 'Hostname rejected', currentHostname());
                return [];
            }
            if (config.status !== 'active' || config.immediatePause) {
                log(config, 'Site is paused; no advertising calls were made');
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
            Object.keys(state.refreshTimers).forEach(function (key) { window.clearInterval(state.refreshTimers[key]); });
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
        }
    };

    if (!window.__HM_DISABLE_AUTOBOOT__) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { boot(); }, { once: true });
        else boot();
    }
}(window, document));
