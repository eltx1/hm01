(function (window, document) {
    'use strict';

    var STATE_KEY = '__HORUS_VIDEO_DIRECT_RUNTIME_V1__';
    var SDK_URL = 'https://imasdk.googleapis.com/js/sdkloader/ima3.js';
    var SELECTOR = '[data-hm-video-direct="1"]';
    var state = window[STATE_KEY] = window[STATE_KEY] || {
        active: null,
        observer: null,
        sdkPromise: null,
        scan: scan,
    };

    function positiveInteger(value, fallback) {
        var number = Number(value);
        return Number.isInteger(number) && number > 0 && number <= 10000 ? number : fallback;
    }

    function decodeBase64(value) {
        try {
            var binary = window.atob(String(value || ''));
            var escaped = '';
            for (var index = 0; index < binary.length; index += 1) {
                escaped += '%' + binary.charCodeAt(index).toString(16).padStart(2, '0');
            }
            return decodeURIComponent(escaped);
        } catch (error) {
            return null;
        }
    }

    function jsonAttribute(container, name, fallback) {
        var raw = container.getAttribute(name);
        if (!raw) return fallback;
        try { return JSON.parse(raw); } catch (error) { return fallback; }
    }

    function normalizedSize(value) {
        if (!Array.isArray(value) || value.length !== 2) return null;
        var width = positiveInteger(value[0], 0);
        var height = positiveInteger(value[1], 0);
        return width && height ? [width, height] : null;
    }

    function allowedSizes(container) {
        var parsed = jsonAttribute(container, 'data-hm-video-sizes', []);
        if (!Array.isArray(parsed)) return [];
        return parsed.map(normalizedSize).filter(Boolean).slice(0, 20);
    }

    function viewportSize() {
        var root = document.documentElement || {};
        return [
            Number(window.innerWidth || root.clientWidth || 0),
            Number(window.innerHeight || root.clientHeight || 0),
        ];
    }

    function selectedSize(container) {
        var sizes = allowedSizes(container);
        var mappings = jsonAttribute(container, 'data-hm-video-size-map', []);
        var viewport = viewportSize();
        if (Array.isArray(mappings)) {
            for (var index = 0; index < mappings.length && index < 100; index += 1) {
                var mapping = mappings[index] || {};
                var minWidth = Math.max(0, Number(mapping.minWidth || 0));
                var minHeight = Math.max(0, Number(mapping.minHeight || 0));
                var maxWidth = mapping.maxWidth == null ? 0 : Math.max(0, Number(mapping.maxWidth || 0));
                var maxHeight = mapping.maxHeight == null ? 0 : Math.max(0, Number(mapping.maxHeight || 0));
                if (viewport[0] && viewport[0] < minWidth) continue;
                if (viewport[1] && viewport[1] < minHeight) continue;
                if (maxWidth && viewport[0] && viewport[0] > maxWidth) continue;
                if (maxHeight && viewport[1] && viewport[1] > maxHeight) continue;
                var mapped = normalizedSize([mapping.width, mapping.height]);
                if (mapped) return mapped;
            }
        }
        if (sizes.length) return sizes[0];
        return [
            positiveInteger(container.getAttribute('data-hm-video-width'), 400),
            positiveInteger(container.getAttribute('data-hm-video-height'), 225),
        ];
    }

    function setStatus(container, value, detail) {
        container.setAttribute('data-hm-video-status', value);
        container.setAttribute('data-hm-video-runtime-state', value);
        if (detail) container.setAttribute('data-hm-video-detail', String(detail).slice(0, 160));
    }

    function loadSdk() {
        if (window.google && window.google.ima) return Promise.resolve(window.google.ima);
        if (state.sdkPromise) return state.sdkPromise;

        state.sdkPromise = new Promise(function (resolve, reject) {
            var existing = document.querySelector && document.querySelector('script[data-hm-ima-sdk="1"]');
            var script = existing || document.createElement('script');
            var settled = false;
            var timer = window.setTimeout(function () {
                if (settled) return;
                settled = true;
                reject(new Error('ima-sdk-timeout'));
            }, 10000);
            function loaded() {
                if (settled) return;
                if (!window.google || !window.google.ima) {
                    settled = true;
                    window.clearTimeout(timer);
                    reject(new Error('ima-sdk-unavailable'));
                    return;
                }
                settled = true;
                window.clearTimeout(timer);
                resolve(window.google.ima);
            }
            function failed() {
                if (settled) return;
                settled = true;
                window.clearTimeout(timer);
                reject(new Error('ima-sdk-error'));
            }
            script.addEventListener ? script.addEventListener('load', loaded, { once: true }) : script.onload = loaded;
            script.addEventListener ? script.addEventListener('error', failed, { once: true }) : script.onerror = failed;
            if (!existing) {
                script.async = true;
                script.src = SDK_URL;
                script.setAttribute('data-hm-ima-sdk', '1');
                (document.head || document.documentElement).appendChild(script);
            }
        }).catch(function (error) {
            state.sdkPromise = null;
            throw error;
        });
        return state.sdkPromise;
    }

    function playerDimensions(container, fallback) {
        var width = Math.round(Number(container.clientWidth || fallback[0]));
        width = Math.max(1, Math.min(fallback[0], width || fallback[0]));
        var ratio = fallback[0] / fallback[1];
        return [width, Math.max(1, Math.round(width / ratio))];
    }

    function createPlayer(container) {
        if (container.__hmVideoPlayer) return container.__hmVideoPlayer;
        var size = selectedSize(container);
        var video = document.createElement('video');
        var adLayer = document.createElement('div');
        video.muted = container.getAttribute('data-hm-video-muted') !== '0';
        video.autoplay = container.getAttribute('data-hm-video-autoplay') !== '0';
        video.playsInline = true;
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');
        video.setAttribute('aria-label', 'Advertisement video');
        video.style.cssText = 'display:block;width:100%;height:100%;object-fit:contain;background:#000;';
        adLayer.setAttribute('data-hm-video-ad-layer', '1');
        adLayer.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;overflow:hidden;';
        container.style.position = 'relative';
        container.style.display = 'block';
        container.style.width = '100%';
        container.style.maxWidth = String(size[0]) + 'px';
        container.style.aspectRatio = String(size[0]) + ' / ' + String(size[1]);
        container.style.background = '#000';
        container.style.overflow = 'hidden';
        container.appendChild(video);
        container.appendChild(adLayer);

        var player = container.__hmVideoPlayer = {
            container: container,
            size: size,
            video: video,
            adLayer: adLayer,
            adsLoader: null,
            adsManager: null,
            displayContainer: null,
            intersectionObserver: null,
            resizeHandler: null,
            started: false,
            destroyed: false,
        };
        container.__hmDestroy = function (reason) { destroyPlayer(player, reason || 'dismissed'); };
        return player;
    }

    function destroyPlayer(player, reason) {
        if (!player || player.destroyed) return;
        player.destroyed = true;
        if (player.intersectionObserver && player.intersectionObserver.disconnect) player.intersectionObserver.disconnect();
        if (player.resizeHandler && window.removeEventListener) window.removeEventListener('resize', player.resizeHandler);
        try { if (player.adsManager && player.adsManager.destroy) player.adsManager.destroy(); } catch (error) {}
        try { if (player.video && player.video.pause) player.video.pause(); } catch (error) {}
        if (reason) setStatus(player.container, reason);
        if (state.active === player) state.active = null;
    }

    function hideCompletedFloatingSurface(player) {
        var surface = player && player.container;
        while (surface && surface.getAttribute) {
            if (surface.getAttribute('data-hm-floating-video-active') === '1') {
                surface.setAttribute('data-hm-placement-dismissed', '1');
                if (surface.style) surface.style.display = 'none';
                return;
            }
            surface = surface.parentNode;
        }
    }

    function startAds(player, ima, vastUrl) {
        if (player.destroyed || player.started) return;
        if (state.active && state.active !== player && !state.active.destroyed) {
            setStatus(player.container, 'duplicate');
            return;
        }
        state.active = player;
        player.started = true;
        setStatus(player.container, 'requesting');

        try {
            player.displayContainer = new ima.AdDisplayContainer(player.adLayer, player.video);
            player.displayContainer.initialize();
            player.adsLoader = new ima.AdsLoader(player.displayContainer);
            player.adsLoader.addEventListener(ima.AdsManagerLoadedEvent.Type.ADS_MANAGER_LOADED, function (event) {
                if (player.destroyed) return;
                var settings = new ima.AdsRenderingSettings();
                settings.restoreCustomPlaybackStateOnAdBreakComplete = true;
                player.adsManager = event.getAdsManager(player.video, settings);
                var adTypes = ima.AdEvent.Type;
                player.adsManager.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, function (errorEvent) {
                    var error = errorEvent && errorEvent.getError ? errorEvent.getError() : null;
                    destroyPlayer(player, 'error');
                    if (error && player.container.setAttribute) player.container.setAttribute('data-hm-video-error', String(error).slice(0, 160));
                });
                player.adsManager.addEventListener(adTypes.LOADED, function () { setStatus(player.container, 'loaded'); });
                player.adsManager.addEventListener(adTypes.STARTED, function () { setStatus(player.container, 'started'); });
                [adTypes.COMPLETE, adTypes.SKIPPED, adTypes.ALL_ADS_COMPLETED].filter(Boolean).forEach(function (type) {
                    player.adsManager.addEventListener(type, function () {
                        if (player.destroyed) return;
                        destroyPlayer(player, 'completed');
                        hideCompletedFloatingSurface(player);
                    });
                });
                var dimensions = playerDimensions(player.container, player.size);
                player.adsManager.init(dimensions[0], dimensions[1], ima.ViewMode.NORMAL);
                if (player.adsManager.setVolume) player.adsManager.setVolume(0);
                player.adsManager.start();
                player.resizeHandler = function () {
                    if (!player.adsManager || player.destroyed) return;
                    var resized = playerDimensions(player.container, player.size);
                    player.adsManager.resize(resized[0], resized[1], ima.ViewMode.NORMAL);
                };
                if (window.addEventListener) window.addEventListener('resize', player.resizeHandler);
            }, false);
            player.adsLoader.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, function (errorEvent) {
                var error = errorEvent && errorEvent.getError ? errorEvent.getError() : null;
                destroyPlayer(player, 'error');
                if (error && player.container.setAttribute) player.container.setAttribute('data-hm-video-error', String(error).slice(0, 160));
            }, false);

            var request = new ima.AdsRequest();
            var dimensions = playerDimensions(player.container, player.size);
            request.adTagUrl = vastUrl;
            request.linearAdSlotWidth = dimensions[0];
            request.linearAdSlotHeight = dimensions[1];
            request.nonLinearAdSlotWidth = dimensions[0];
            request.nonLinearAdSlotHeight = Math.max(1, Math.round(dimensions[1] / 3));
            if (request.setAdWillAutoPlay) request.setAdWillAutoPlay(true);
            if (request.setAdWillPlayMuted) request.setAdWillPlayMuted(true);
            player.adsLoader.requestAds(request);
        } catch (error) {
            destroyPlayer(player, 'error');
            player.container.setAttribute('data-hm-video-error', String(error && error.message || error).slice(0, 160));
        }
    }

    function waitUntilViewable(player, ima, vastUrl) {
        if (typeof window.IntersectionObserver !== 'function') {
            startAds(player, ima, vastUrl);
            return;
        }
        player.intersectionObserver = new window.IntersectionObserver(function (entries) {
            var visible = entries.some(function (entry) { return entry.isIntersecting && Number(entry.intersectionRatio || 0) >= 0.5; });
            if (!visible) return;
            player.intersectionObserver.disconnect();
            player.intersectionObserver = null;
            startAds(player, ima, vastUrl);
        }, { threshold: [0.5] });
        setStatus(player.container, 'waiting-viewability');
        player.intersectionObserver.observe(player.container);
    }

    function render(container) {
        if (!container || container.getAttribute('data-hm-video-runtime-state')) return;
        var vastUrl = decodeBase64(container.getAttribute('data-hm-vast-url'));
        if (!vastUrl || !/^https:\/\//i.test(vastUrl)) {
            setStatus(container, 'invalid');
            return;
        }
        var player = createPlayer(container);
        setStatus(container, 'loading-sdk');
        loadSdk().then(function (ima) {
            if (!player.destroyed) waitUntilViewable(player, ima, vastUrl);
        }).catch(function (error) {
            setStatus(container, 'error', error && error.message || 'sdk-error');
        });
    }

    function scan(root) {
        root = root || document;
        if (root.matches && root.matches(SELECTOR)) render(root);
        if (!root.querySelectorAll) return;
        Array.prototype.forEach.call(root.querySelectorAll(SELECTOR), render);
    }

    scan(document);
    if (typeof window.MutationObserver === 'function' && document.documentElement && !state.observer) {
        state.observer = new window.MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes || [], function (node) { scan(node); });
            });
        });
        state.observer.observe(document.documentElement, { childList: true, subtree: true });
    }
})(window, document);
