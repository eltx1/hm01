(function (window, document) {
    'use strict';

    // BEGIN SHARED REWARDED PROMPT
    // Embedded at build time in the independent GPT and VAST runtimes. No extra request.
    function rewardedPromptUi(options) {
        var translations = {
            en: ['Continue your reading', 'Watch an ad, then return to where you left off.', 'Watching is entirely optional. Closing this message will not block the content.', 'Watch ad', 'Continue without an ad', 'Close', 'Optional rewarded ad', 'Watch this video to receive the reward.', 'Available later', 'You can try another rewarded ad later.'],
            ar: ['أكمل قراءتك', 'شاهد إعلانًا، ثم تابع القراءة من حيث توقفت.', 'مشاهدة الإعلان اختيارية تمامًا. إغلاق هذه الرسالة لا يحجب المحتوى.', 'شاهد الإعلان', 'المتابعة بدون إعلان', 'إغلاق', 'إعلان مكافأة اختياري', 'شاهد هذا الفيديو للحصول على المكافأة.', 'متاح لاحقًا', 'يمكنك مشاهدة إعلان مكافأة آخر لاحقًا.'],
            fr: ['Poursuivez votre lecture', 'Regardez une annonce, puis reprenez votre lecture.', 'Le visionnage est entièrement facultatif. Fermer ce message ne bloque pas le contenu.', 'Voir l’annonce', 'Continuer sans annonce', 'Fermer', 'Annonce avec récompense facultative', 'Regardez cette vidéo pour obtenir la récompense.', 'Disponible plus tard', 'Vous pourrez réessayer plus tard.'],
            es: ['Continúa leyendo', 'Mira un anuncio y retoma la lectura donde la dejaste.', 'Ver el anuncio es totalmente opcional. Cerrar este mensaje no bloquea el contenido.', 'Ver anuncio', 'Continuar sin anuncio', 'Cerrar', 'Anuncio con recompensa opcional', 'Mira este vídeo para recibir la recompensa.', 'Disponible más tarde', 'Podrás volver a intentarlo más tarde.'],
            de: ['Weiterlesen', 'Sieh dir eine Anzeige an und lies anschließend weiter.', 'Das Ansehen ist völlig freiwillig. Das Schließen dieser Nachricht sperrt keine Inhalte.', 'Anzeige ansehen', 'Ohne Anzeige fortfahren', 'Schließen', 'Freiwillige Anzeige mit Belohnung', 'Sieh dir dieses Video an, um die Belohnung zu erhalten.', 'Später verfügbar', 'Du kannst es später erneut versuchen.'],
            pt: ['Continue a leitura', 'Veja um anúncio e retome a leitura de onde parou.', 'Assistir é totalmente opcional. Fechar esta mensagem não bloqueia o conteúdo.', 'Ver anúncio', 'Continuar sem anúncio', 'Fechar', 'Anúncio com recompensa opcional', 'Veja este vídeo para receber a recompensa.', 'Disponível mais tarde', 'Você poderá tentar novamente mais tarde.'],
            tr: ['Okumaya devam edin', 'Bir reklam izleyin, ardından kaldığınız yerden okumaya devam edin.', 'Reklam izlemek tamamen isteğe bağlıdır. Bu mesajı kapatmak içeriği engellemez.', 'Reklamı izle', 'Reklamsız devam et', 'Kapat', 'İsteğe bağlı ödüllü reklam', 'Ödülü almak için bu videoyu izleyin.', 'Daha sonra kullanılabilir', 'Daha sonra tekrar deneyebilirsiniz.'],
            id: ['Lanjutkan membaca', 'Tonton iklan, lalu lanjutkan membaca.', 'Menonton sepenuhnya opsional. Menutup pesan ini tidak memblokir konten.', 'Tonton iklan', 'Lanjutkan tanpa iklan', 'Tutup', 'Iklan berhadiah opsional', 'Tonton video ini untuk mendapatkan hadiah.', 'Tersedia nanti', 'Anda dapat mencoba lagi nanti.'],
        };
        var candidates = [];
        try {
            var navigator = window.navigator || {};
            if (Array.isArray(navigator.languages)) candidates = navigator.languages.slice();
            if (navigator.language) candidates.push(navigator.language);
        } catch (error) { /* Fall back to the page language when browser preferences are unavailable. */ }
        candidates.push(document.documentElement.lang || '', 'en');
        var language = 'en';
        for (var index = 0; index < candidates.length; index++) {
            var base = String(candidates[index]).toLowerCase().split(/[-_]/)[0];
            if (Object.prototype.hasOwnProperty.call(translations, base)) { language = base; break; }
        }
        var text = translations[language];
        // Inline, element-scoped priority prevents publisher button styles from hiding
        // or disabling these controls, without styling any other ad or publisher node.
        function style(node, css) {
            node.style.cssText = 'all:initial;' + css;
            if (node.style.setProperty) {
                for (var i = 0; i < node.style.length; i++) {
                    var property = node.style[i];
                    node.style.setProperty(property, node.style.getPropertyValue(property), 'important');
                }
            }
        }
        var prompt = document.createElement('div');
        var title = document.createElement('strong');
        var copy = document.createElement('p');
        var disclosure = document.createElement('p');
        var watch = document.createElement('button');
        var decline = document.createElement('button');
        var close = document.createElement('button');
        prompt.setAttribute('data-hm-reward-prompt', '1');
        prompt.setAttribute('lang', language);
        prompt.setAttribute('dir', language === 'ar' ? 'rtl' : 'ltr');
        prompt.setAttribute('role', options.modal ? 'dialog' : 'group');
        if (options.modal) prompt.setAttribute('aria-modal', 'true');
        title.textContent = options.title || (options.reading ? text[0] : text[6]);
        copy.textContent = options.capped ? text[9] : (options.copy || (options.reading ? text[1] : text[7]));
        disclosure.textContent = text[2];
        disclosure.setAttribute('data-hm-reward-disclosure', '1');
        prompt.setAttribute('aria-label', title.textContent);
        prompt.setAttribute('aria-description', disclosure.textContent);
        style(prompt, 'position:relative;display:block;box-sizing:border-box;width:100%;max-width:440px;margin:auto;padding:60px 24px 24px;border:1px solid #d1d5db;border-radius:20px;background:#ffffff;color:#111827;color-scheme:light;text-align:center;font:400 16px/1.6 system-ui,sans-serif;box-shadow:0 20px 64px rgba(0,0,0,.2);overflow-wrap:anywhere;direction:' + (language === 'ar' ? 'rtl' : 'ltr') + ';');
        style(title, 'display:block;margin:0;color:#111827;font:700 24px/1.4 system-ui,sans-serif;text-align:center;');
        style(copy, 'display:block;margin:12px 0;color:#374151;font:400 16px/1.6 system-ui,sans-serif;text-align:center;');
        style(disclosure, 'display:block;margin:12px 0 20px;padding:12px;border-radius:10px;background:#f3f4f6;color:#374151;font:400 14px/1.6 system-ui,sans-serif;text-align:center;');
        var buttonCss = 'display:block;box-sizing:border-box;width:100%;min-height:46px;margin:10px 0 0;padding:12px 16px;border-radius:10px;font:600 15px/1.5 system-ui,sans-serif;white-space:normal;text-align:center;cursor:pointer;pointer-events:auto;touch-action:manipulation;';
        watch.type = decline.type = close.type = 'button';
        watch.textContent = options.capped ? text[8] : (options.button || text[3]);
        watch.disabled = !!options.capped;
        style(watch, buttonCss + 'border:1px solid #111827;background:#111827;color:#ffffff;' + (options.capped ? 'opacity:.65;' : ''));
        decline.textContent = text[4];
        decline.setAttribute('data-hm-reward-decline', '1');
        style(decline, buttonCss + 'border:1px solid #9ca3af;background:#ffffff;color:#111827;');
        close.textContent = '×';
        close.setAttribute('aria-label', text[5]);
        close.setAttribute('title', text[5]);
        close.setAttribute('data-hm-reward-prompt-close', '1');
        style(close, 'display:block;box-sizing:border-box;position:absolute;top:10px;inset-inline-end:10px;width:44px;height:44px;min-width:44px;min-height:44px;margin:0;padding:0;border:1px solid #9ca3af;border-radius:10px;background:#ffffff;color:#111827;font:400 30px/40px system-ui,sans-serif;text-align:center;cursor:pointer;pointer-events:auto;touch-action:manipulation;');
        [close, title, copy, disclosure, watch, decline].forEach(function (node) { prompt.appendChild(node); });
        [close, decline].forEach(function (node) {
            node.addEventListener('click', function (event) { event.stopPropagation(); options.dismiss(); });
        });
        [close, watch, decline].forEach(function (node) {
            node.addEventListener('focus', function () { if (node.style.setProperty) node.style.setProperty('outline', '3px solid #2563eb', 'important'); });
            node.addEventListener('blur', function () { if (node.style.removeProperty) node.style.removeProperty('outline'); });
        });
        return {
            prompt: prompt, watch: watch, decline: decline, close: close, language: language,
            cycleFocus: function (event) {
                var buttons = watch.disabled ? [close, decline] : [close, watch, decline];
                var current = buttons.indexOf(document.activeElement);
                var next = (current + (event.shiftKey ? -1 : 1) + buttons.length) % buttons.length;
                event.preventDefault();
                try { if (buttons[next].focus) buttons[next].focus(); } catch (error) {}
            },
        };
    }
    // END SHARED REWARDED PROMPT

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
        if (!seconds) return 0;
        try {
            if (!window.localStorage) return 0;
            var grantedAt = Number(window.localStorage.getItem(rewardStorageKey(container)) || 0);
            if (!Number.isFinite(grantedAt) || grantedAt <= 0) return 0;
            return Math.max(0, seconds - Math.floor((Date.now() - grantedAt) / 1000));
        } catch (error) {
            return 0;
        }
    }

    function rememberRewardGrant(container) {
        if (rewardCooldownSeconds(container) <= 0) return;
        try { if (window.localStorage) window.localStorage.setItem(rewardStorageKey(container), String(Date.now())); } catch (error) {}
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
            container.style.pointerEvents = 'auto';
        }
        container.appendChild(video);
        container.appendChild(adLayer);
        if (closeButton) {
            closeButton.type = 'button';
            closeButton.textContent = '×';
            closeButton.setAttribute('aria-label', 'Close rewarded video');
            closeButton.setAttribute('data-hm-reward-close', '1');
            closeButton.style.cssText = 'position:fixed;top:calc(12px + env(safe-area-inset-top,0px));right:12px;z-index:2147483647;width:38px;height:38px;padding:0;border:0;border-radius:999px;background:rgba(0,0,0,.72);color:#fff;font:26px/38px system-ui,sans-serif;cursor:pointer;pointer-events:auto;touch-action:manipulation;';
            container.appendChild(closeButton);
            try { if (closeButton.focus) closeButton.focus({ preventScroll: true }); } catch (error) {}
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
        window.clearTimeout(player.startupTimer);
        // Dismissal must not depend on IMA or publisher focus handlers succeeding.
        if (player.rewarded && player.container.style) player.container.style.display = 'none';
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

        if (player.rewarded) player.startupTimer = window.setTimeout(function () {
            failVideo(player, new Error('Rewarded video did not start in time'), 'startup-timeout');
        }, 15000);
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
                player.adsManager.addEventListener(adTypes.STARTED, function () {
                    if (player.destroyed) return;
                    window.clearTimeout(player.startupTimer);
                    setStatus(player.container, 'started');
                });
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
            var cleanup = container.__hmReadingCleanup;
            container.__hmReadingCleanup = null;
            try { cleanup(); } catch (error) {}
        }
        if (state.readingPrompt === container) state.readingPrompt = null;
    }

    function prepareRewarded(container, ima, vastUrl) {
        var remaining = rewardCooldownRemaining(container);
        var activated = false;
        var reading = container.getAttribute('data-hm-reward-experience') === 'continue-reading';
        var dismissed = false;
        if (reading && state.readingPrompt) {
            setStatus(container, 'duplicate');
            container.style.display = 'none';
            return;
        }
        var ui = rewardedPromptUi({
            reading: reading, modal: reading && !remaining, capped: remaining > 0,
            title: reading ? '' : rewardText(container, 'data-hm-reward-title', ''),
            copy: reading ? '' : rewardText(container, 'data-hm-reward-copy', ''),
            button: reading ? '' : rewardText(container, 'data-hm-reward-button', ''),
            dismiss: function () { if (container.__hmDestroy) container.__hmDestroy('dismissed'); },
        });
        var prompt = ui.prompt, button = ui.watch;
        container.style.cssText = 'position:relative;display:block;width:100%;max-width:640px;margin:16px auto;box-sizing:border-box;background:transparent;';
        if (reading && !remaining) {
            container.style.cssText = 'position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;width:100%;height:100%;max-width:none;margin:0;padding:16px;box-sizing:border-box;background:rgba(15,23,42,.35);overflow:auto;pointer-events:auto;';
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
                container.__hmDestroy('duplicate');
                return false;
            }
            if (event && event.stopPropagation) event.stopPropagation();
            activated = true;
            try {
                var player = createPlayer(container);
                startAds(player, ima, vastUrl);
                return !player.destroyed;
            } catch (error) {
                container.__hmDestroy('error');
                return false;
            }
        }

        if (button.addEventListener) button.addEventListener('click', activate);
        container.__hmDestroy = function (reason) {
            if (dismissed) return;
            dismissed = true;
            if (container.__hmVideoPlayer) {
                destroyPlayer(container.__hmVideoPlayer, reason || 'dismissed');
                return;
            }
            if (container.style) container.style.display = 'none';
            setStatus(container, reason || 'dismissed');
            clearContainer(container);
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
                    ui.cycleFocus(event);
                }
            };
            window.addEventListener('keydown', keyHandler);
            container.__hmReadingCleanup = function () {
                window.removeEventListener('keydown', keyHandler);
                if (previousFocus && previousFocus.isConnected && previousFocus.focus) previousFocus.focus({ preventScroll: true });
            };
            try { if (button.focus) button.focus({ preventScroll: true }); } catch (error) {}
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
