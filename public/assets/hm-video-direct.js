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

    function rewardedMode(container) {
        return container && container.getAttribute('data-hm-video-rewarded') === '1';
    }

    function rewardEvent(container, name, detail) {
        if (!window.dispatchEvent || typeof window.CustomEvent !== 'function') return;
        var payload = Object.assign({
            placementId: String(container && container.id || ''),
            provider: 'HORUS_VAST',
        }, detail || {});
        window.dispatchEvent(new window.CustomEvent(name, { detail: payload }));
    }

    function rewardCooldownSeconds(container) {
        var seconds = Number(container.getAttribute('data-hm-reward-cooldown-seconds') || 60);
        return Number.isFinite(seconds) ? Math.max(0, Math.min(86400, Math.floor(seconds))) : 0;
    }

    function rewardStorageKey(container) {
        return 'hm:rewarded:v1:' + String(container && container.id || 'placement');
    }

    function rewardCooldownRemaining(container) {
        var seconds = rewardCooldownSeconds(container);
        if (!seconds || !window.localStorage) return 0;
        try {
            var grantedAt = Number(window.localStorage.getItem(rewardStorageKey(container)) || 0);
            if (!Number.isFinite(grantedAt) || grantedAt <= 0) return 0;
            return Math.max(0, seconds - Math.floor((Date.now() - grantedAt) / 1000));
        } catch (error) {
            return 0;
        }
    }

    function rememberRewardGrant(container) {
        if (!window.localStorage || rewardCooldownSeconds(container) <= 0) return;
        try { window.localStorage.setItem(rewardStorageKey(container), String(Date.now())); } catch (error) {}
    }

    function clearContainer(container) {
        if (!container) return;
        if (container.removeChild && container.firstChild) {
            while (container.firstChild) container.removeChild(container.firstChild);
            return;
        }
        if (container.childNodes && container.childNodes.length && container.removeChild) {
            while (container.childNodes.length) container.removeChild(container.childNodes[0]);
            return;
        }
        if (typeof container.innerHTML === 'string') container.innerHTML = '';
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
        var rewarded = rewardedMode(container);
        clearContainer(container);
        var video = document.createElement('video');
        var adLayer = document.createElement('div');
        var closeButton = rewarded ? document.createElement('button') : null;
        video.muted = container.getAttribute('data-hm-video-muted') !== '0';
        video.autoplay = container.getAttribute('data-hm-video-autoplay') !== '0';
        video.playsInline = true;
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');
        video.setAttribute('aria-label', 'Advertisement video');
        video.style.cssText = 'display:block;width:100%;height:100%;object-fit:contain;background:#000;';
        adLayer.setAttribute('data-hm-video-ad-layer', '1');
        adLayer.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;overflow:hidden;';
        container.style.position = rewarded ? 'fixed' : 'relative';
        container.style.display = 'block';
        container.style.width = rewarded ? '100vw' : '100%';
        container.style.height = rewarded ? '100vh' : 'auto';
        container.style.maxWidth = rewarded ? 'none' : String(size[0]) + 'px';
        container.style.aspectRatio = rewarded ? 'auto' : String(size[0]) + ' / ' + String(size[1]);
        container.style.background = '#000';
        container.style.overflow = 'hidden';
        if (rewarded) {
            container.style.padding = '0';
            container.style.margin = '0';
            container.style.inset = '0';
            container.style.zIndex = '2147483646';
        }
        container.appendChild(video);
        container.appendChild(adLayer);
        if (closeButton) {
            closeButton.type = 'button';
            closeButton.textContent = '×';
            closeButton.setAttribute('aria-label', 'Close rewarded video');
            closeButton.setAttribute('data-hm-reward-close', '1');
            closeButton.style.cssText = 'position:fixed;top:calc(12px + env(safe-area-inset-top,0px));right:12px;z-index:2147483647;width:38px;height:38px;padding:0;border:0;border-radius:999px;background:rgba(0,0,0,.72);color:#fff;font:26px/38px system-ui,sans-serif;cursor:pointer;';
            container.appendChild(closeButton);
            if (closeButton.focus) closeButton.focus({ preventScroll: true });
        }

        var player = container.__hmVideoPlayer = {
            container: container,
            size: size,
            video: video,
            adLayer: adLayer,
            closeButton: closeButton,
            adsLoader: null,
            adsManager: null,
            displayContainer: null,
            intersectionObserver: null,
            resizeHandler: null,
            started: false,
            destroyed: false,
            rewarded: rewarded,
            completedAds: 0,
            disqualified: false,
            granted: false,
            closedDispatched: false,
        };
        if (closeButton && closeButton.addEventListener) closeButton.addEventListener('click', function () {
            destroyPlayer(player, 'dismissed');
        });
        container.__hmDestroy = function (reason) { destroyPlayer(player, reason || 'dismissed'); };
        return player;
    }

    function destroyPlayer(player, reason) {
        if (!player || player.destroyed) return;
        player.destroyed = true;
        if (player.intersectionObserver && player.intersectionObserver.disconnect) player.intersectionObserver.disconnect();
        if (player.resizeHandler && window.removeEventListener) window.removeEventListener('resize', player.resizeHandler);
        try { if (player.adsManager && player.adsManager.destroy) player.adsManager.destroy(); } catch (error) {}
        try { if (player.adsLoader && player.adsLoader.destroy) player.adsLoader.destroy(); } catch (error) {}
        try { if (player.displayContainer && player.displayContainer.destroy) player.displayContainer.destroy(); } catch (error) {}
        try { if (player.video && player.video.pause) player.video.pause(); } catch (error) {}
        if (reason) setStatus(player.container, reason);
        if (player.rewarded && !player.closedDispatched) {
            player.closedDispatched = true;
            rewardEvent(player.container, 'horus:rewarded-closed', {
                granted: player.granted === true,
                reason: reason || 'closed',
            });
            if (player.container.style) player.container.style.display = 'none';
            finishReading(player.container);
        }
        if (!player.rewarded && reason) hideFloatingSurface(player);
        if (state.active === player) state.active = null;
    }

    function grantReward(player) {
        if (!player || !player.rewarded || player.granted || player.disqualified || player.completedAds < 1) return false;
        player.granted = true;
        rememberRewardGrant(player.container);
        player.container.setAttribute('data-hm-reward-granted', '1');
        rewardEvent(player.container, 'horus:rewarded-granted', {
            reward: { type: 'horus_video_completion', amount: 1 },
        });
        return true;
    }

    function hideFloatingSurface(source) {
        var surface = source && source.container ? source.container : source;
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
            hideFloatingSurface(player);
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
                try {
                var settings = new ima.AdsRenderingSettings();
                settings.restoreCustomPlaybackStateOnAdBreakComplete = true;
                player.adsManager = event.getAdsManager(player.video, settings);
                var adTypes = ima.AdEvent.Type;
                player.adsManager.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, function (errorEvent) {
                    var error = errorEvent && errorEvent.getError ? errorEvent.getError() : null;
                    failVideo(player, error, 'playback');
                });
                player.adsManager.addEventListener(adTypes.LOADED, function () { setStatus(player.container, 'loaded'); });
                player.adsManager.addEventListener(adTypes.STARTED, function () { setStatus(player.container, 'started'); });
                if (player.rewarded && adTypes.COMPLETE) player.adsManager.addEventListener(adTypes.COMPLETE, function () {
                    player.completedAds += 1;
                });
                if (player.rewarded && adTypes.SKIPPED) player.adsManager.addEventListener(adTypes.SKIPPED, function () {
                    player.disqualified = true;
                    player.container.setAttribute('data-hm-reward-outcome', 'skipped');
                });
                // COMPLETE/SKIPPED are per-ad events. Keep the IMA manager alive
                // for VAST pods and later VMAP breaks; only the terminal pod
                // event owns teardown of the Horus surface.
                if (adTypes.ALL_ADS_COMPLETED) player.adsManager.addEventListener(adTypes.ALL_ADS_COMPLETED, function () {
                    if (player.destroyed) return;
                    if (player.rewarded) {
                        grantReward(player);
                        destroyPlayer(player, player.granted ? 'completed' : 'closed');
                    } else {
                        destroyPlayer(player, 'completed');
                    }
                });
                var dimensions = playerDimensions(player.container, player.size);
                player.adsManager.init(dimensions[0], dimensions[1], ima.ViewMode.NORMAL);
                if (player.adsManager.setVolume) player.adsManager.setVolume(player.rewarded ? 1 : 0);
                player.adsManager.start();
                player.resizeHandler = function () {
                    if (!player.adsManager || player.destroyed) return;
                    var resized = playerDimensions(player.container, player.size);
                    player.adsManager.resize(resized[0], resized[1], ima.ViewMode.NORMAL);
                };
                if (window.addEventListener) window.addEventListener('resize', player.resizeHandler);
                } catch (error) { failVideo(player, error, 'manager'); }
            }, false);
            player.adsLoader.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, function (errorEvent) {
                var error = errorEvent && errorEvent.getError ? errorEvent.getError() : null;
                failVideo(player, error, 'request');
            }, false);

            var request = new ima.AdsRequest();
            var dimensions = playerDimensions(player.container, player.size);
            request.adTagUrl = resolvedVastUrl(vastUrl, player, dimensions);
            request.linearAdSlotWidth = dimensions[0];
            request.linearAdSlotHeight = dimensions[1];
            request.nonLinearAdSlotWidth = dimensions[0];
            request.nonLinearAdSlotHeight = Math.max(1, Math.round(dimensions[1] / 3));
            if (request.setAdWillAutoPlay) request.setAdWillAutoPlay(player.video.autoplay === true);
            if (request.setAdWillPlayMuted) request.setAdWillPlayMuted(player.video.muted === true);
            player.adsLoader.requestAds(request);
            if (player.rewarded) rewardEvent(player.container, 'horus:rewarded-opened', {});
        } catch (error) {
            failVideo(player, error, 'initialization');
        }
    }

    function failVideo(player, error, stage) {
        if (!player || player.destroyed) return;
        var message = String(error && error.message || error || 'Unknown IMA error').slice(0, 240);
        var code = error && typeof error.getErrorCode === 'function' ? error.getErrorCode() : '';
        var vastCode = error && typeof error.getVastErrorCode === 'function' ? error.getVastErrorCode() : '';
        // Keep the diagnosis on the permanent placement before Loader removes
        // the failed candidate. No ad requests or telemetry are sent here.
        var surface = player.container;
        while (surface && surface.getAttribute) {
            surface.setAttribute('data-hm-video-error', message);
            surface.setAttribute('data-hm-video-error-code', String(code));
            surface.setAttribute('data-hm-video-vast-error-code', String(vastCode));
            surface.setAttribute('data-hm-video-error-stage', stage);
            if (surface.getAttribute('data-placement')) break;
            surface = surface.parentNode;
        }
        destroyPlayer(player, 'error');
    }

    function resolvedVastUrl(value, player, dimensions) {
        // GAM's tag generator emits placeholders, not a ready-to-request URL.
        // Expand known placeholders at request time without changing third-party tags.
        var tag = new URL(value);
        if (!/^(?:pubads|securepubads)\.g\.doubleclick\.net$/i.test(tag.hostname) || tag.pathname !== '/gampad/ads') return value;
        var page = new URL(window.location.href);
        page.hash = '';
        ['url', 'description_url'].forEach(function (name) {
            var current = tag.searchParams.get(name);
            if (!current || /^\[(?:referrer_url|description_url)\]$/i.test(current)) tag.searchParams.set(name, page.href);
        });
        var correlator = tag.searchParams.get('correlator');
        if (!correlator || /^\[timestamp\]$/i.test(correlator)) tag.searchParams.set('correlator', String(Date.now()));
        tag.searchParams.set('vpmute', player.video.muted ? '1' : '0');
        tag.searchParams.set('vpa', player.video.autoplay ? 'auto' : 'click');
        // sz identifies eligible inventory; it is not the CSS player size.
        // IMA receives actual dimensions separately in linearAdSlotWidth/Height.
        if (!tag.searchParams.get('sz')) tag.searchParams.set('sz', dimensions[0] + 'x' + dimensions[1]);
        if (!player.rewarded) tag.searchParams.set('plcmt', '4');
        return tag.href;
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

    function rewardText(container, attribute, fallback) {
        var value = String(container.getAttribute(attribute) || '').trim();
        return value ? value.slice(0, 160) : fallback;
    }

    function finishReading(container) {
        if (container.__hmReadingCleanup) {
            container.__hmReadingCleanup();
            container.__hmReadingCleanup = null;
        }
        if (state.readingPrompt === container) state.readingPrompt = null;
    }

    function prepareRewarded(container, ima, vastUrl) {
        var remaining = rewardCooldownRemaining(container);
        var activated = false;
        var prompt = document.createElement('div');
        var title = document.createElement('strong');
        var copy = document.createElement('span');
        var button = document.createElement('button');
        var decline = document.createElement('button');
        var reading = container.getAttribute('data-hm-reward-experience') === 'continue-reading';
        var arabic = /^ar\b/i.test(String(document.documentElement.lang || ''));
        var dismissed = false;
        if (reading && state.readingPrompt) {
            setStatus(container, 'duplicate');
            container.style.display = 'none';
            return;
        }

        container.style.position = 'relative';
        container.style.display = 'block';
        container.style.width = '100%';
        container.style.maxWidth = '640px';
        container.style.minHeight = '180px';
        container.style.margin = '16px auto';
        container.style.background = '#071a36';
        container.style.color = '#fff';
        container.style.overflow = 'hidden';
        container.style.borderRadius = '14px';
        container.style.boxSizing = 'border-box';
        if (reading && !remaining) {
            container.style.cssText = 'position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;width:100%;height:100%;max-width:none;margin:0;padding:20px;box-sizing:border-box;background:rgba(5,8,22,.78);overflow:auto;';
        }

        prompt.setAttribute('data-hm-reward-prompt', '1');
        prompt.style.cssText = 'display:flex;min-height:180px;padding:24px;box-sizing:border-box;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-align:center;background:linear-gradient(135deg,#071a36,#0f3970);';
        if (reading) {
            prompt.style.cssText += 'width:100%;max-width:480px;border:1px solid rgba(241,183,51,.22);border-radius:26px;box-shadow:0 28px 90px rgba(0,0,0,.38);background:linear-gradient(135deg,#050b1e,#0a2153);';
            prompt.setAttribute('dir', arabic ? 'rtl' : 'ltr');
            prompt.setAttribute('role', 'dialog');
            prompt.setAttribute('aria-modal', 'true');
            prompt.setAttribute('aria-label', arabic ? 'استكمال القراءة' : 'Continue reading');
        }
        title.textContent = remaining ? 'Reward already completed' : rewardText(container, 'data-hm-reward-title', 'Watch to continue');
        title.style.cssText = 'display:block;font:700 22px/1.25 system-ui,sans-serif;color:#fff;';
        copy.textContent = remaining
            ? 'You can watch another rewarded video in about ' + Math.max(1, Math.ceil(remaining / 60)) + ' minute(s).'
            : rewardText(container, 'data-hm-reward-copy', 'Watch this short sponsored video to unlock the reward.');
        copy.style.cssText = 'display:block;max-width:480px;font:400 15px/1.5 system-ui,sans-serif;color:#dbe8ff;';
        button.type = 'button';
        button.textContent = remaining ? 'Available later' : rewardText(container, 'data-hm-reward-button', 'Watch video');
        button.disabled = remaining > 0;
        if (reading) {
            title.textContent = arabic ? 'أكمل قراءتك' : 'Continue your reading';
            copy.textContent = remaining
                ? (arabic ? 'يمكنك متابعة قراءة المحتوى الآن.' : 'You can continue reading now.')
                : (arabic ? 'شاهد الإعلان حتى نهايته، ثم تابع قراءة المحتوى من حيث توقفت. يمكنك الإغلاق في أي وقت.' : 'Watch the ad to completion, then continue reading where you left off. You can close at any time.');
            button.textContent = arabic ? 'شاهد الإعلان واستكمل القراءة' : 'Watch ad and continue reading';
            if (remaining) button.style.display = 'none';
        }
        button.setAttribute('aria-label', button.textContent);
        button.style.cssText = 'min-height:44px;max-width:100%;white-space:normal;padding:10px 22px;border:0;border-radius:999px;background:linear-gradient(115deg,#ffe495,#f1b733 56%,#cf8b13);color:#071127;font:700 15px/1.5 system-ui,sans-serif;cursor:pointer;';
        if (reading && remaining) button.style.display = 'none';
        if (remaining) button.style.opacity = '0.65';
        prompt.appendChild(title);
        prompt.appendChild(copy);
        prompt.appendChild(button);
        if (reading) {
            decline.type = 'button';
            decline.textContent = arabic ? 'متابعة القراءة الآن' : 'Continue reading now';
            decline.setAttribute('data-hm-reward-decline', '1');
            decline.style.cssText = 'min-height:44px;padding:10px 20px;border:1px solid #9da9c2;border-radius:999px;background:transparent;color:#f6f8ff;font:500 15px/1.5 system-ui,sans-serif;cursor:pointer;';
            decline.addEventListener('click', function () { container.__hmDestroy('dismissed'); });
            prompt.appendChild(decline);
        }
        container.appendChild(prompt);

        function activate(event) {
            if (remaining || activated || container.getAttribute('data-hm-video-runtime-state') === 'dismissed') return false;
            var userActivation = window.navigator && window.navigator.userActivation;
            var trustedEvent = event && event.isTrusted === true;
            if (userActivation && userActivation.isActive !== true && !trustedEvent) {
                setStatus(container, 'activation-required');
                return false;
            }
            if (state.active && !state.active.destroyed) {
                setStatus(container, 'duplicate', 'another-video-is-active');
                return false;
            }
            activated = true;
            var player = createPlayer(container);
            startAds(player, ima, vastUrl);
            return true;
        }

        if (button.addEventListener) button.addEventListener('click', activate);
        container.__hmDestroy = function (reason) {
            if (dismissed) return;
            dismissed = true;
            if (container.__hmVideoPlayer) {
                destroyPlayer(container.__hmVideoPlayer, reason || 'dismissed');
                return;
            }
            setStatus(container, reason || 'dismissed');
            clearContainer(container);
            if (container.style) container.style.display = 'none';
            finishReading(container);
            rewardEvent(container, 'horus:rewarded-closed', { granted: false, reason: reason || 'dismissed' });
        };

        if (reading && !remaining) {
            state.readingPrompt = container;
            var previousFocus = document.activeElement;
            var keyHandler = function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    container.__hmDestroy('dismissed');
                } else if (event.key === 'Tab' && !container.__hmVideoPlayer) {
                    event.preventDefault();
                    var next = document.activeElement === button ? decline : button;
                    if (next.focus) next.focus();
                }
            };
            window.addEventListener('keydown', keyHandler);
            container.__hmReadingCleanup = function () {
                window.removeEventListener('keydown', keyHandler);
                if (previousFocus && previousFocus.isConnected && previousFocus.focus) previousFocus.focus({ preventScroll: true });
            };
            if (button.focus) button.focus({ preventScroll: true });
        }
        if (reading && remaining) container.style.display = 'none';

        setStatus(container, remaining ? 'reward-capped' : 'reward-ready');
        rewardEvent(container, 'horus:rewarded-ready', {
            capped: remaining > 0,
            cooldownRemainingSeconds: remaining,
            makeRewardedVisible: activate,
        });
    }

    function render(container) {
        if (!container || container.getAttribute('data-hm-video-runtime-state')) return;
        var vastUrl = decodeBase64(container.getAttribute('data-hm-vast-url'));
        if (!vastUrl || !/^https:\/\//i.test(vastUrl)) {
            setStatus(container, 'invalid');
            if (!rewardedMode(container)) hideFloatingSurface(container);
            return;
        }
        var rewarded = rewardedMode(container);
        var player = rewarded ? null : createPlayer(container);
        setStatus(container, 'loading-sdk');
        loadSdk().then(function (ima) {
            if (rewarded) {
                if (container.getAttribute('data-hm-video-runtime-state') !== 'dismissed') prepareRewarded(container, ima, vastUrl);
            } else if (!player.destroyed) {
                waitUntilViewable(player, ima, vastUrl);
            }
        }).catch(function (error) {
            var detail = error && error.message || 'sdk-error';
            if (player) {
                failVideo(player, error, 'sdk');
            } else {
                setStatus(container, 'error', detail);
            }
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
