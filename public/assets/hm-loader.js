(function (window, document) {
    'use strict';

    var VERSION = '1.1.0';
    var STATE_KEY = '__HORUS_MEDIA_LOADER_STATE__';
    var state = window[STATE_KEY] = window[STATE_KEY] || {
        config: null,
        gptPromise: null,
        prebidPromise: null,
        prebidAssetUrl: null,
        servicesEnabled: false,
        initialLoadDisabled: false,
        slots: {},
        refreshTimers: {},
        observer: null,
        navigationPatched: false,
        scanTimer: null,
        script: null,
        booting: null,
        diagnostics: {
            auctions: 0,
            auctionTimeouts: 0,
            auctionFailures: 0,
            fallbacks: 0
        }
    };

    function log(config) {
        if (!config || !(config.debug || (config.prebid && config.prebid.debug)) || !window.console || !window.console.info) return;
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
        return window.fetch(buildConfigUrl(script, siteKey, force), {
            method: 'GET', mode: 'cors', credentials: 'omit', cache: force ? 'reload' : 'default'
        }).then(function (response) {
            if (!response || !response.ok) throw new Error('Static configuration unavailable');
            return response.json();
        }).then(function (config) {
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
        window.pbjs = window.pbjs || { que: [] };
        window.pbjs.que = window.pbjs.que || [];
        return window.pbjs;
    }

    function loadExternalScript(selector, attributes, source) {
        return new Promise(function (resolve, reject) {
            var existing = document.querySelector ? document.querySelector(selector) : null;
            if (existing) {
                if (existing.getAttribute('data-hm-loaded') === '1') {
                    resolve(existing);
                    return;
                }
                existing.addEventListener('load', function () { resolve(existing); }, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            var tag = document.createElement('script');
            tag.async = true;
            tag.src = source;
            Object.keys(attributes).forEach(function (key) { tag.setAttribute(key, attributes[key]); });
            tag.onload = function () { tag.setAttribute('data-hm-loaded', '1'); resolve(tag); };
            tag.onerror = function () { reject(new Error('Script failed to load: ' + source)); };
            (document.head || document.documentElement).appendChild(tag);
        });
    }

    function loadGpt(config) {
        var googletag = ensureGoogletagQueue();
        if (googletag.apiReady || googletag.pubadsReady) return Promise.resolve(googletag);
        if (state.gptPromise) return state.gptPromise;
        state.gptPromise = loadExternalScript(
            'script[data-hm-gpt="1"]',
            { 'data-hm-gpt': '1' },
            config.gpt && config.gpt.url || 'https://securepubads.g.doubleclick.net/tag/js/gpt.js'
        ).then(function () { return ensureGoogletagQueue(); });
        return state.gptPromise;
    }

    function loadPrebid(config) {
        var prebid = config.prebid || {};
        var build = prebid.build || {};
        if (!prebid.enabled || !build.assetUrl) return Promise.reject(new Error('Prebid is not enabled or has no compiled build'));
        var pbjs = ensurePrebidQueue();
        if (pbjs.libLoaded) return Promise.resolve(pbjs);
        if (state.prebidPromise) return state.prebidPromise;
        state.prebidAssetUrl = build.assetUrl;
        state.prebidPromise = loadExternalScript(
            'script[data-hm-prebid="1"]',
            {
                'data-hm-prebid': '1',
                'data-hm-prebid-version': String(build.version || '')
            },
            build.assetUrl + (build.assetUrl.indexOf('?') === -1 ? '?' : '&') + 'v=' + encodeURIComponent(build.version || config.configVersion || VERSION)
        ).then(function () { return ensurePrebidQueue(); }).catch(function (error) {
            state.prebidPromise = null;
            throw error;
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

    function lazyOptions(items) {
        var enabled = items.map(function (item) { return item.placement.lazyLoad || {}; }).filter(function (item) { return item.enabled; });
        if (!enabled.length) return null;
        return {
            fetchMarginPercent: Math.max.apply(Math, enabled.map(function (item) { return Number(item.fetchMarginPercent || 0); })),
            renderMarginPercent: Math.max.apply(Math, enabled.map(function (item) { return Number(item.renderMarginPercent || 0); })),
            mobileScaling: Math.max.apply(Math, enabled.map(function (item) { return Number(item.mobileScaling || 1); }))
        };
    }

    function prebidEnabled(config) {
        return Boolean(config && config.prebidEnabled && config.prebid && config.prebid.enabled);
    }

    function configurePrebid(pbjs, config) {
        var prebid = config.prebid || {};
        var base = {
            priceGranularity: prebid.priceGranularity || 'dense',
            bidderSequence: prebid.bidderSequence || 'random',
            enableSendAllBids: Boolean(prebid.sendAllBids),
            debug: Boolean(prebid.debug),
            currency: { adServerCurrency: prebid.currency || 'USD' }
        };
        if (prebid.consentManagement && Object.keys(prebid.consentManagement).length) base.consentManagement = prebid.consentManagement;
        if (prebid.userSync && Object.keys(prebid.userSync).length) base.userSync = prebid.userSync;
        var advanced = prebid.advancedConfig && typeof prebid.advancedConfig === 'object' ? prebid.advancedConfig : {};
        var merged = Object.assign({}, base, advanced);
        if (pbjs.setConfig) pbjs.setConfig(merged);
    }

    function makePrebidAdUnits(config, entries) {
        var configured = config.prebid && config.prebid.adUnits || {};
        return entries.map(function (entry) {
            var template = configured[entry.placement.code];
            if (!template || !Array.isArray(template.bids) || !template.bids.length) return null;
            return {
                code: entry.element.id,
                mediaTypes: template.mediaTypes,
                bids: template.bids
            };
        }).filter(Boolean);
    }

    function refreshGpt(googletag, entries) {
        return new Promise(function (resolve) {
            googletag.cmd.push(function () {
                try {
                    var slots = entries.map(function (entry) { return entry.slot; }).filter(Boolean);
                    if (slots.length && googletag.pubads().refresh) googletag.pubads().refresh(slots);
                } catch (error) {
                    // Ad delivery must fail silently on publisher pages.
                }
                resolve(entries);
            });
        });
    }

    function runPrebidAuction(config, googletag, entries) {
        var prebid = config.prebid || {};
        var adUnits = makePrebidAdUnits(config, entries);
        if (!adUnits.length) return Promise.reject(new Error('No eligible Prebid ad units'));

        return loadPrebid(config).then(function (pbjs) {
            return new Promise(function (resolve, reject) {
                var settled = false;
                var timeout = Math.max(100, Number(prebid.auctionTimeoutMs || 1200));
                var safetyTimer = window.setTimeout(function () {
                    if (settled) return;
                    settled = true;
                    state.diagnostics.auctionTimeouts += 1;
                    reject(new Error('Prebid auction safety timeout'));
                }, timeout + 300);

                pbjs.que.push(function () {
                    try {
                        configurePrebid(pbjs, config);
                        var codes = adUnits.map(function (unit) { return unit.code; });
                        if (pbjs.removeAdUnit) codes.forEach(function (code) { pbjs.removeAdUnit(code); });
                        if (pbjs.addAdUnits) pbjs.addAdUnits(adUnits);
                        if (prebid.timeoutReportingEnabled && pbjs.onEvent && !pbjs.__hmTimeoutListener) {
                            pbjs.__hmTimeoutListener = true;
                            pbjs.onEvent('bidTimeout', function (timedOutBids) {
                                state.diagnostics.auctionTimeouts += Array.isArray(timedOutBids) ? timedOutBids.length : 1;
                                updateDiagnostics(config, entries);
                            });
                        }
                        state.diagnostics.auctions += 1;
                        pbjs.requestBids({
                            adUnitCodes: codes,
                            timeout: timeout,
                            bidsBackHandler: function () {
                                if (settled) return;
                                settled = true;
                                window.clearTimeout(safetyTimer);
                                try {
                                    if (pbjs.setTargetingForGPTAsync) pbjs.setTargetingForGPTAsync(codes);
                                } catch (targetingError) {
                                    log(config, 'Prebid targeting failed; continuing to GAM', targetingError);
                                }
                                resolve(entries);
                            }
                        });
                    } catch (error) {
                        if (settled) return;
                        settled = true;
                        window.clearTimeout(safetyTimer);
                        reject(error);
                    }
                });
            });
        }).then(function () {
            return refreshGpt(googletag, entries);
        });
    }

    function requestWithFallback(config, googletag, entries) {
        if (!prebidEnabled(config)) {
            return state.initialLoadDisabled ? refreshGpt(googletag, entries) : Promise.resolve(entries);
        }
        return runPrebidAuction(config, googletag, entries).catch(function (error) {
            state.diagnostics.auctionFailures += 1;
            log(config, 'Prebid failed safely', error);
            if (config.prebid && config.prebid.gamFallbackEnabled) {
                state.diagnostics.fallbacks += 1;
                return refreshGpt(googletag, entries);
            }
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
                        var lazy = lazyOptions(items);
                        if (lazy && pubads.enableLazyLoad) pubads.enableLazyLoad(lazy);
                        if (config.gpt && config.gpt.singleRequest && pubads.enableSingleRequest && !state.servicesEnabled) pubads.enableSingleRequest();
                        if (prebidEnabled(config) && pubads.disableInitialLoad && !state.servicesEnabled) {
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
                            entry.element.setAttribute('data-hm-status', 'registered');
                        });
                        resolve(defined);
                    } catch (error) {
                        log(config, 'Slot definition failed', error);
                        resolve([]);
                    }
                });
            }).then(function (defined) {
                if (!defined.length) return defined;
                return requestWithFallback(config, googletag, defined).then(function () {
                    defined.forEach(function (entry) {
                        entry.element.setAttribute('data-hm-status', 'requested');
                        scheduleRefresh(config, googletag, entry);
                    });
                    updateDiagnostics(config, defined);
                    return defined;
                });
            });
        }).catch(function (error) {
            log(config, 'GPT unavailable', error);
            return [];
        });
    }

    function scheduleRefresh(config, googletag, entry) {
        var placementRefresh = entry.placement.refresh || {};
        var prebidRefresh = config.prebid && config.prebid.refresh || {};
        var enabled = placementRefresh.enabled || (prebidEnabled(config) && prebidRefresh.enabled);
        var interval = Number(placementRefresh.intervalSeconds || prebidRefresh.intervalSeconds || 0);
        if (!enabled || interval < 30) return;
        var limit = Number(placementRefresh.limit || 0);
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
            requestWithFallback(config, googletag, [entry]);
        }, interval * 1000);
    }

    function updateDiagnostics(config, defined) {
        if (!(config.debug || (config.prebid && config.prebid.debug))) return;
        window.__HM_DIAGNOSTICS__ = {
            loaderVersion: VERSION,
            configVersion: config.configVersion,
            siteKey: config.siteKey,
            hostname: currentHostname(),
            servingMode: config.servingMode,
            gamNetworkCode: config.gamNetworkCode,
            prebidEnabled: prebidEnabled(config),
            prebidBuild: config.prebid && config.prebid.build && config.prebid.build.version,
            gamSetupKey: config.prebid && config.prebid.gamSetup && config.prebid.gamSetup.key,
            definedPlacements: defined.map(function (entry) { return entry.placement.code; }),
            auctions: state.diagnostics.auctions,
            auctionTimeouts: state.diagnostics.auctionTimeouts,
            auctionFailures: state.diagnostics.auctionFailures,
            gamFallbacks: state.diagnostics.fallbacks
        };
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
            state.prebidAssetUrl = null;
            state.servicesEnabled = false;
            state.initialLoadDisabled = false;
            state.slots = {};
            state.refreshTimers = {};
            state.booting = null;
            state.script = null;
            state.diagnostics = { auctions: 0, auctionTimeouts: 0, auctionFailures: 0, fallbacks: 0 };
        }
    };

    if (!window.__HM_DISABLE_AUTOBOOT__) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { boot(); }, { once: true });
        else boot();
    }
}(window, document));
