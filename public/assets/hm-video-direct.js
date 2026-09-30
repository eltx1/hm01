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
            // Apply priorities atomically. Upgrading `all` on a live declaration in
            // Chromium resets its later non-important values before they can be read.
            node.style.cssText = ('all:initial;' + css).replace(/;/g, ' !important;');
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

    // Optional local diagnostic hook. Never transmit data or alter authorization.
    function tracePhase(phase, container, detail) {
        try {
            var loader = window.HorusMediaLoader;
            if (!loader || typeof loader.trace !== 'function') return;
            var fields = Object.assign({}, detail || {});
            if (container && typeof loader.traceSlot === 'function') fields.slot = loader.traceSlot(container);
            loader.trace(phase, fields);
        } catch (error) { /* Diagnostics must not change playback or requests. */ }
    }

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

    function vastUrlAttribute(container) {
        var single = container.getAttribute('data-hm-vast-url');
        var count = container.getAttribute('data-hm-vast-url-parts');
        var encoded = single || '';
        if (container.attributes) {
            for (var attribute = 0; attribute < container.attributes.length; attribute += 1) {
                var name = container.attributes[attribute].name;
                if (name.indexOf('data-hm-vast-url-') === 0 && name !== 'data-hm-vast-url-parts'
                    && !/^data-hm-vast-url-[0-7]$/.test(name)) return null;
            }
        }
        if (count !== null) {
            if (single !== null || !/^[2-8]$/.test(count)) return null;
            encoded = '';
            for (var index = 0; index < 8; index += 1) {
                var part = container.getAttribute('data-hm-vast-url-' + index);
                if (index >= Number(count)) { if (part !== null) return null; continue; }
                if (!part || part.length > 1800 || (index < Number(count) - 1 && part.length !== 1800)) return null;
                encoded += part;
            }
        } else {
            for (var orphan = 0; orphan < 8; orphan += 1) {
                if (container.getAttribute('data-hm-vast-url-' + orphan) !== null) return null;
            }
        }
        // Validate before decoding: a truncated base64 prefix may otherwise
        // remain a syntactically valid URL pointing at the wrong auction.
        if (!encoded || encoded.length > 13336 || encoded.length % 4 !== 0
            || !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(encoded)) return null;
        try {
            var binary = window.atob(encoded);
            if (binary.length > 10000 || (window.btoa && window.btoa(binary) !== encoded)) return null;
        } catch (error) { return null; }
        return decodeBase64(encoded);
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
        // CSS fitting, the floating clearance and rewarded overlays can all
        // change the real box. Report its measured dimensions, not its preset.
        if (container.getBoundingClientRect) {
            var box = container.getBoundingClientRect();
            if (box.width > 0 && box.height > 0) return [Math.max(1, Math.round(box.width)), Math.max(1, Math.round(box.height))];
        }
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
            contentMode: false,
            contentStarted: false,
            contentEnded: false,
            contentFailed: false,
            contentPlayGeneration: 0,
            adRuntimeGeneration: 0,
            adMediaActive: false,
            preRollRequested: false,
            midRollRequested: false,
            postRollRequested: false,
            adBreakPending: false,
            currentBreak: null,
            adRules: false,
            adRuleCuePoints: [],
            adRulesHasPostroll: false,
            floating: false,
            wasInlineVisible: false,
            wasInlineAnchorVisible: false,
            visibleRatio: 0,
            pendingAdStart: null,
            requestCorrelator: String(Date.now()),
            contentListeners: [],
            contentEndedHandler: null,
            contentEndedAttached: false,
        };
        if (closeButton && closeButton.addEventListener) closeButton.addEventListener('click', function () {
            destroyPlayer(player, 'dismissed');
        });
        container.__hmDestroy = function (reason) { destroyPlayer(player, reason || 'dismissed'); };
        installVideoViewport(player);
        return player;
    }

    function destroyPlayer(player, reason) {
        if (!player || player.destroyed) return;
        player.destroyed = true;
        player.contentPlayGeneration += 1;
        player.adRuntimeGeneration += 1;
        window.clearTimeout(player.startupTimer);
        // Dismissal must not depend on IMA or publisher focus handlers succeeding.
        if (player.rewarded && player.container.style) player.container.style.display = 'none';
        if (player.intersectionObserver && player.intersectionObserver.disconnect) player.intersectionObserver.disconnect();
        if (player.resizeHandler && window.removeEventListener) window.removeEventListener('resize', player.resizeHandler);
        (player.contentListeners || []).forEach(function (entry) {
            try { if (entry[0] && entry[0].removeEventListener) entry[0].removeEventListener(entry[1], entry[2]); } catch (error) {}
        });
        player.contentListeners = [];
        player.pendingAdStart = null;
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
        if (player.viewport) player.viewport.stop();
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


    function placementSurface(source) {
        var surface = source && source.container ? source.container : source;
        var fallback = surface;
        while (surface && surface.getAttribute) {
            if (surface.getAttribute('data-placement')) return surface;
            fallback = surface;
            surface = surface.parentNode;
        }
        return fallback;
    }

    function importantStyle(style, name, value) {
        if (!style) return;
        if (style.setProperty) style.setProperty(name, value, 'important');
        else style[name.replace(/-([a-z])/g, function (_, letter) { return letter.toUpperCase(); })] = value;
    }

    function validContentUrl(value) {
        try {
            var url = new URL(String(value || ''));
            return url.protocol === 'https:' ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function releasePendingAdStart(player) {
        if (!player || player.destroyed || typeof player.pendingAdStart !== 'function') return false;
        if (!player.rewarded && !player.floating && Number(player.visibleRatio || 0) < 0.5) return false;
        var start = player.pendingAdStart;
        player.pendingAdStart = null;
        try { start(); return true; } catch (error) { return false; }
    }

    function startAdManagerWhenViewable(player, start) {
        if (!player || player.destroyed || typeof start !== 'function') return;
        if (player.rewarded || player.floating || Number(player.visibleRatio || 0) >= 0.5 || typeof window.IntersectionObserver !== 'function') {
            start();
            return;
        }
        player.pendingAdStart = start;
        setStatus(player.container, 'waiting-ad-viewability');
    }

    // Layout is independent of content/VAST availability. Observe the inline
    // position before loading IMA so a slow SDK cannot lose the scroll history.
    function installVideoViewport(player) {
        if (!player || player.rewarded || player.viewport) return;
        var surface = placementSurface(player);
        var floatingValue = player.container.getAttribute('data-hm-video-inline-to-floating');
        player.inlineToFloating = floatingValue === '1' || (floatingValue !== '0' && surface && surface.getAttribute('data-hm-video-inline-to-floating') === '1');
        var layout = player.viewport = { surface: surface, anchor: null, portal: false, frame: null, stopped: false, onVisible: null, scrolled: false };
        var initialScrollY = Number(window.scrollY || window.pageYOffset || 0);
        var initialScrollX = Number(window.scrollX || window.pageXOffset || 0);

        // Consume geometry measured by the Loader while verification/runtime
        // bytes were pending. It grants layout eligibility, never ad permission.
        var history = surface && surface.__hmInlineVideoHistory;
        if (player.inlineToFloating && history && history.node === surface && typeof history.take === 'function') {
            try {
                var previous = history.take();
                if (previous) {
                    player.wasInlineVisible = previous.wasInlineVisible === true;
                    player.wasInlineAnchorVisible = previous.anchorWasVisible === true;
                    layout.scrolled = previous.scrolled === true;
                }
            } catch (error) { /* Optional layout history must never block ads. */ }
        }

        function write(style, property, value) {
            if (!style || (style.getPropertyValue && style.getPropertyValue(property) === value)) return;
            importantStyle(style, property, value);
        }
        function viewportBox() {
            var visual = window.visualViewport;
            var top = Number(visual && visual.offsetTop || 0), left = Number(visual && visual.offsetLeft || 0);
            return { top: top, left: left,
                bottom: top + Number(visual && visual.height || window.innerHeight || document.documentElement.clientHeight || 0),
                right: left + Number(visual && visual.width || window.innerWidth || document.documentElement.clientWidth || 0) };
        }
        function geometry(node) {
            if (!node || !node.getBoundingClientRect) return null;
            var rect = node.getBoundingClientRect(), view = viewportBox();
            var clip = { top: Math.max(view.top, rect.top), bottom: Math.min(view.bottom, rect.bottom), left: Math.max(view.left, rect.left), right: Math.min(view.right, rect.right) };
            var hidden = node.isConnected === false || !(rect.width > 0 && rect.height > 0);
            // getBoundingClientRect alone ignores clipping by scroll/overflow
            // ancestors. Keep the fallback as conservative as IntersectionObserver.
            if (window.getComputedStyle) {
                for (var parent = node, depth = 0; parent && parent.nodeType === 1 && depth < 64; parent = parent.parentElement, depth++) {
                    var css = window.getComputedStyle(parent);
                    if (css.display === 'none' || css.visibility === 'hidden' || Number(css.opacity) === 0) hidden = true;
                    if (parent !== node && parent !== document.body && parent !== document.documentElement && parent.getBoundingClientRect) {
                        var bounds = parent.getBoundingClientRect();
                        if (/(auto|scroll|hidden|clip)/.test(css.overflowX)) { clip.left = Math.max(clip.left, bounds.left); clip.right = Math.min(clip.right, bounds.right); }
                        if (/(auto|scroll|hidden|clip)/.test(css.overflowY)) { clip.top = Math.max(clip.top, bounds.top); clip.bottom = Math.min(clip.bottom, bounds.bottom); }
                    }
                }
            }
            var ratio = hidden ? 0 : Math.max(0, clip.right - clip.left) * Math.max(0, clip.bottom - clip.top) / (rect.width * rect.height);
            return { rect: rect, clip: clip, view: view, hidden: hidden, ratio: Math.min(1, ratio) };
        }
        layout.reserve = function () {
            if (layout.anchor || !surface || !surface.parentNode || !surface.parentNode.insertBefore || !surface.getBoundingClientRect) return;
            var bounds = surface.getBoundingClientRect();
            var anchor = document.createElement('div');
            anchor.setAttribute('data-hm-video-placeholder', '1');
            anchor.setAttribute('aria-hidden', 'true');
            anchor.style.cssText = 'display:block;box-sizing:border-box;padding:0;border:0;pointer-events:none;';
            write(anchor.style, 'width', Math.max(1, bounds.width) + 'px');
            write(anchor.style, 'max-width', '100%');
            write(anchor.style, 'height', Math.max(1, bounds.height) + 'px');
            if (window.getComputedStyle) {
                var css = window.getComputedStyle(surface);
                ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'].forEach(function (name) { write(anchor.style, name, css.getPropertyValue(name)); });
            }
            surface.parentNode.insertBefore(anchor, surface);
            layout.anchor = anchor;
        };
        // A transform/contain on a publisher ancestor changes the containing
        // block of position:fixed. Escape it BEFORE IMA creates its iframe, never
        // by reparenting a playing ad. The placeholder retains the inline slot.
        if (player.inlineToFloating && surface && document.body && surface.parentNode && window.getComputedStyle) {
            var needsPortal = false;
            for (var parent = surface.parentElement; parent && parent !== document.body && parent !== document.documentElement; parent = parent.parentElement) {
                var css = window.getComputedStyle(parent);
                if (['transform', 'perspective', 'filter', 'backdropFilter', 'translate', 'rotate', 'scale'].some(function (name) { return css[name] && css[name] !== 'none'; })
                    || /(layout|paint|strict|content)/.test(css.contain || '') || /(transform|filter|perspective)/.test(css.willChange || '') || css.contentVisibility === 'auto') {
                    needsPortal = true; break;
                }
            }
            if (needsPortal) {
                layout.reserve();
                if (layout.anchor) {
                    document.body.appendChild(surface);
                    layout.portal = true;
                    surface.setAttribute('data-hm-video-portal', '1');
                }
            }
        }
        function notify() {
            if (player.destroyed || layout.stopped || document.visibilityState === 'hidden' || player.visibleRatio < 0.5) return;
            if (layout.onVisible) { var ready = layout.onVisible; layout.onVisible = null; ready(); }
            releasePendingAdStart(player);
        }
        function accept(ratio, data) {
            player.visibleRatio = Math.max(0, Math.min(1, ratio));
            if (!player.floating && player.visibleRatio >= 0.5) player.wasInlineVisible = true;
            var outside = data ? !data.hidden && (data.rect.bottom <= data.view.top + 1 || data.rect.top >= data.view.bottom - 1) : ratio <= 0.01;
            if (!player.floating && player.inlineToFloating && (player.wasInlineVisible || player.wasInlineAnchorVisible) && outside && (layout.scrolled || !data)) {
                layout.reserve();
                floatContentPlayer(player);
                var floated = geometry(surface);
                player.visibleRatio = floated ? floated.ratio : 1;
            }
            notify();
        }
        layout.update = function () {
            layout.frame = null;
            if (layout.stopped || player.destroyed) return;
            if (surface && (surface.isConnected === false || surface.getAttribute('data-hm-placement-dismissed') === '1') || layout.anchor && layout.anchor.isConnected === false) {
                destroyPlayer(player, 'dismissed'); return;
            }
            layout.scrolled = layout.scrolled || Number(window.scrollY || window.pageYOffset || 0) !== initialScrollY || Number(window.scrollX || window.pageXOffset || 0) !== initialScrollX;
            var data = geometry(player.floating ? surface : layout.anchor || player.container);
            if (!data) { notify(); return; }
            if (layout.portal && !player.floating) {
                var rect = data.rect, clip = data.clip;
                write(surface.style, 'position', 'fixed'); write(surface.style, 'margin', '0');
                write(surface.style, 'top', rect.top + 'px'); write(surface.style, 'left', rect.left + 'px');
                write(surface.style, 'right', 'auto'); write(surface.style, 'bottom', 'auto');
                write(surface.style, 'width', rect.width + 'px'); write(surface.style, 'height', rect.height + 'px');
                write(surface.style, 'visibility', data.hidden || data.ratio <= 0 ? 'hidden' : 'visible');
                write(surface.style, 'clip-path', data.ratio >= 0.999 ? 'none' : 'inset(' + Math.max(0, clip.top - rect.top) + 'px ' + Math.max(0, rect.right - clip.right) + 'px ' + Math.max(0, rect.bottom - clip.bottom) + 'px ' + Math.max(0, clip.left - rect.left) + 'px)');
            }
            accept(data.ratio, data);
            if (player.adsManager && player.adsManager.resize) {
                var size = playerDimensions(player.container, player.size), signature = size.join('x');
                if (layout.size !== signature) {
                    layout.size = signature;
                    try { player.adsManager.resize(size[0], size[1], window.google && window.google.ima ? window.google.ima.ViewMode.NORMAL : 'normal'); } catch (error) {}
                }
            }
        };
        layout.schedule = function (event) {
            if (layout.stopped || player.destroyed) return;
            if (event && event.type === 'scroll') layout.scrolled = true;
            if (layout.frame !== null) return;
            layout.frame = window.requestAnimationFrame ? window.requestAnimationFrame(layout.update) : window.setTimeout(layout.update, 16);
        };
        layout.ready = function (callback) { layout.onVisible = callback; layout.update(); };
        layout.stop = function () {
            layout.stopped = true; layout.onVisible = null;
            if (layout.frame !== null) {
                if (window.cancelAnimationFrame) window.cancelAnimationFrame(layout.frame);
                else window.clearTimeout(layout.frame);
            }
            if (layout.resize) layout.resize.disconnect();
            if (window.removeEventListener) {
                window.removeEventListener('scroll', layout.schedule, true);
                window.removeEventListener('resize', layout.schedule);
                window.removeEventListener('orientationchange', layout.schedule);
            }
            if (document.removeEventListener) document.removeEventListener('visibilitychange', layout.schedule);
            if (window.visualViewport && window.visualViewport.removeEventListener) {
                window.visualViewport.removeEventListener('resize', layout.schedule);
                window.visualViewport.removeEventListener('scroll', layout.schedule);
            }
            if (layout.anchor && layout.anchor.parentNode) layout.anchor.parentNode.removeChild(layout.anchor);
            if (layout.portal && surface && surface.parentNode) surface.parentNode.removeChild(surface);
        };
        if (window.addEventListener) {
            window.addEventListener('scroll', layout.schedule, { passive: true, capture: true });
            window.addEventListener('resize', layout.schedule, { passive: true });
            window.addEventListener('orientationchange', layout.schedule, { passive: true });
        }
        if (document.addEventListener) document.addEventListener('visibilitychange', layout.schedule);
        if (window.visualViewport && window.visualViewport.addEventListener) {
            window.visualViewport.addEventListener('resize', layout.schedule, { passive: true });
            window.visualViewport.addEventListener('scroll', layout.schedule, { passive: true });
        }
        if (typeof window.ResizeObserver === 'function') {
            layout.resize = new window.ResizeObserver(layout.schedule);
            layout.resize.observe(player.container);
            if (layout.anchor) layout.resize.observe(layout.anchor);
        }
        if (typeof window.IntersectionObserver === 'function') {
            player.intersectionObserver = new window.IntersectionObserver(function (entries) {
                if (layout.stopped || player.destroyed) return;
                // Real layout is remeasured on scroll as well as observer events.
                // Ratio-only delivery also supports non-layout SDK test adapters.
                if ((layout.anchor || player.container).getBoundingClientRect) { layout.update(); return; }
                entries.forEach(function (entry) { accept(entry.isIntersecting ? Number(entry.intersectionRatio || 0) : 0, null); });
            }, { threshold: [0, 0.01, 0.5, 1] });
            player.intersectionObserver.observe(layout.anchor || player.container);
        }
        layout.update();
    }

    function floatContentPlayer(player) {
        if (!player || player.destroyed || !player.inlineToFloating || player.floating) return;
        var surface = placementSurface(player);
        if (!surface || !surface.style) return;
        player.floating = true;
        surface.setAttribute('data-hm-floating-video-active', '1');
        surface.setAttribute('data-hm-video-floating-state', 'floating');
        importantStyle(surface.style, 'position', 'fixed');
        importantStyle(surface.style, 'height', 'auto');
        importantStyle(surface.style, 'min-height', '0');
        importantStyle(surface.style, 'visibility', 'visible');
        importantStyle(surface.style, 'clip-path', 'none');
        importantStyle(surface.style, 'z-index', '2147483000');
        importantStyle(surface.style, 'right', '16px');
        importantStyle(surface.style, 'left', 'auto');
        importantStyle(surface.style, 'top', 'auto');
        importantStyle(surface.style, 'transform', 'none');
        importantStyle(surface.style, 'margin', '0');
        importantStyle(surface.style, 'width', 'min(' + player.size[0] + 'px, calc(100vw - 32px))');
        importantStyle(surface.style, 'max-width', 'calc(100vw - 32px)');
        importantStyle(surface.style, 'aspect-ratio', player.size[0] + ' / ' + player.size[1]);
        surface.setAttribute('data-hm-video-master-width', String(player.size[0]));
        surface.setAttribute('data-hm-video-master-height', String(player.size[1]));
        importantStyle(surface.style, 'box-sizing', 'border-box');
        importantStyle(surface.style, 'background', '#000');
        importantStyle(surface.style, 'box-shadow', '0 12px 36px rgba(0,0,0,.38)');
        importantStyle(surface.style, 'bottom', 'calc(16px + env(safe-area-inset-bottom, 0px))');
        releasePendingAdStart(player);
        try {
            if (player.adsManager && player.adsManager.resize) {
                var dimensions = playerDimensions(player.container, player.size);
                player.adsManager.resize(dimensions[0], dimensions[1], window.google && window.google.ima ? window.google.ima.ViewMode.NORMAL : 'normal');
            }
        } catch (error) {}
        try {
            if (typeof window.CustomEvent === 'function' && window.dispatchEvent) {
                window.dispatchEvent(new window.CustomEvent('horus:video-floated', {
                    detail: { placementId: String(surface.getAttribute('data-placement') || player.container.id || '') },
                }));
            }
        } catch (error) {}
    }

    function recordVideoError(player, error, stage) {
        var message = String(error && error.message || error || 'Unknown IMA error').slice(0, 240);
        var code = error && typeof error.getErrorCode === 'function' ? error.getErrorCode() : '';
        var vastCode = error && typeof error.getVastErrorCode === 'function' ? error.getVastErrorCode() : '';
        tracePhase(Number(code) === 303 || Number(code) === 1009 ? 'VAST no-fill' : 'VAST error', player.container, { code: Number(code), position: player.currentBreak });
        var surface = player.container;
        while (surface && surface.getAttribute) {
            surface.setAttribute('data-hm-video-error', message);
            surface.setAttribute('data-hm-video-error-code', String(code));
            surface.setAttribute('data-hm-video-vast-error-code', String(vastCode));
            surface.setAttribute('data-hm-video-error-stage', stage);
            if (surface.getAttribute('data-placement')) break;
            surface = surface.parentNode;
        }
    }

    function setContentMediaOwnership(player, adActive) {
        player.adMediaActive = adActive;
        player.contentPlayGeneration += 1;
        // A transparent IMA layer must not intercept native content controls
        // between breaks or when the browser requires a playback gesture.
        if (player.adLayer && player.adLayer.style) player.adLayer.style.pointerEvents = adActive ? 'auto' : 'none';
        if (!player.video || !player.contentEndedHandler) return;
        if (adActive && player.contentEndedAttached && player.video.removeEventListener) {
            try { player.video.removeEventListener('ended', player.contentEndedHandler); } catch (error) {}
            player.contentEndedAttached = false;
        } else if (!adActive && !player.contentEndedAttached && player.video.addEventListener) {
            try {
                player.video.addEventListener('ended', player.contentEndedHandler);
                player.contentEndedAttached = true;
            } catch (error) {}
        }
    }

    function cleanupContentAdRuntime(player) {
        // Retire callbacks BEFORE SDK teardown, which can itself dispatch events
        // and reject an older content play() promise on a shared video element.
        player.adRuntimeGeneration += 1;
        player.contentPlayGeneration += 1;
        player.pendingAdStart = null;
        window.clearTimeout(player.startupTimer);
        if (player.resizeHandler && window.removeEventListener) {
            try { window.removeEventListener('resize', player.resizeHandler); } catch (error) {}
            player.resizeHandler = null;
        }
        try { if (player.adsManager && player.adsManager.destroy) player.adsManager.destroy(); } catch (error) {}
        // IMA requires contentComplete() before reusing the same ad tag for a
        // later legitimate manual break. Ad-rules/VMAP keep their AdsLoader
        // alive and therefore never enter this cleanup path between breaks.
        try { if (player.adsLoader && player.adsLoader.contentComplete) player.adsLoader.contentComplete(); } catch (error) {}
        try { if (player.adsLoader && player.adsLoader.destroy) player.adsLoader.destroy(); } catch (error) {}
        try { if (player.displayContainer && player.displayContainer.destroy) player.displayContainer.destroy(); } catch (error) {}
        player.adsManager = null;
        player.adsLoader = null;
        player.displayContainer = null;
        player.adBreakPending = false;
        player.currentBreak = null;
        setContentMediaOwnership(player, false);
    }

    function finishContentPlayer(player, reason) {
        if (!player || player.destroyed) return;
        var surface = placementSurface(player);
        if (surface && surface.style) surface.style.display = 'none';
        destroyPlayer(player, reason || 'completed');
    }

    function markContentPlaying(player) {
        if (!player || player.destroyed || player.contentFailed || player.adMediaActive) return;
        tracePhase('Video content', player.container);
        player.contentStarted = true;
        player.container.setAttribute('data-hm-video-detail', '');
        setStatus(player.container, 'content-playing');
    }

    function resumeContent(player) {
        if (!player || player.destroyed || player.contentEnded || player.adMediaActive) return;
        if (player.contentFailed) {
            finishContentPlayer(player, 'content-error');
            return;
        }
        var generation = ++player.contentPlayGeneration;
        function currentAttempt() {
            return !player.destroyed && !player.adMediaActive && generation === player.contentPlayGeneration;
        }
        function failed(error) {
            if (!currentAttempt()) return;
            var name = String(error && error.name || '');
            if (name === 'NotAllowedError' || name === 'AbortError') {
                // Policy denial or an interrupted play is not broken media.
                // Keep the native play control usable; never loop ad requests or
                // pretend playback/impressions happened before actual playback.
                player.video.controls = true;
                if (player.adLayer && player.adLayer.style) player.adLayer.style.pointerEvents = 'none';
                setStatus(player.container, 'content-ready', name === 'NotAllowedError' ? 'user-activation-required' : 'playback-interrupted');
                return;
            }
            player.contentFailed = true;
            player.container.setAttribute('data-hm-video-content-error', 'playback');
            recordVideoError(player, error, 'content-playback');
            finishContentPlayer(player, 'content-error');
        }
        try {
            var playResult = player.video && player.video.play ? player.video.play() : null;
            if (playResult && typeof playResult.then === 'function') {
                playResult.then(function () { if (currentAttempt()) markContentPlaying(player); }).catch(failed);
            } else if (currentAttempt()) {
                markContentPlaying(player);
            }
        } catch (error) {
            failed(error);
        }
    }

    function finishContentAdBreak(player, position) {
        if (!player || player.destroyed) return;
        cleanupContentAdRuntime(player);
        if (position === 'postroll' || player.contentEnded) {
            finishContentPlayer(player, 'completed');
            return;
        }
        resumeContent(player);
    }

    function failContentAdBreak(player, error, stage, position) {
        if (!player || player.destroyed) return;
        recordVideoError(player, error, stage);
        cleanupContentAdRuntime(player);
        if (position === 'postroll' || player.contentEnded) {
            finishContentPlayer(player, 'completed');
            return;
        }
        // A missing/failed ad must never prevent Horus-owned content from playing.
        // Conversely, if the content source itself failed, the already-attempted
        // VAST preroll remains valid from the runtime perspective and the surface
        // can close cleanly after that attempt.
        resumeContent(player);
    }

    function requestContentAdBreak(player, ima, vastUrl, position) {
        if (!player || player.destroyed || player.adBreakPending) return;
        if (player.adRules && position !== 'preroll') return;
        player.adBreakPending = true;
        player.currentBreak = position;
        player.contentPlayGeneration += 1;
        if (player.contentStarted && player.video && player.video.pause) {
            try { player.video.pause(); } catch (error) {}
        }
        cleanupContentAdRuntime(player);
        player.adBreakPending = true;
        player.currentBreak = position;
        setStatus(player.container, 'requesting-' + position);
        if (player.adLayer && player.adLayer.style) player.adLayer.style.pointerEvents = 'auto';
        var generation = player.adRuntimeGeneration;
        function currentRequest() { return !player.destroyed && generation === player.adRuntimeGeneration; }

        function armStartupPhase(phase) {
            window.clearTimeout(player.startupTimer);
            player.startupTimer = window.setTimeout(function () {
                if (currentRequest()) failContentAdBreak(player, new Error('Video ad ' + phase + ' timed out'), phase + '-timeout', position);
            }, 15000);
        }
        // Separate VAST resolution from media loading. A slow VAST response
        // must not consume IMA's entire 12-second media load allowance.
        armStartupPhase('request');

        try {
            player.displayContainer = new ima.AdDisplayContainer(player.adLayer, player.video);
            player.displayContainer.initialize();
            player.adsLoader = new ima.AdsLoader(player.displayContainer);
            player.adsLoader.addEventListener(ima.AdsManagerLoadedEvent.Type.ADS_MANAGER_LOADED, function (event) {
                if (!currentRequest() || player.currentBreak !== position || player.adsManager) return;
                armStartupPhase('viewability');
                try {
                    var settings = new ima.AdsRenderingSettings();
                    settings.restoreCustomPlaybackStateOnAdBreakComplete = true;
                    // Preload the selected media and tolerate slower creative CDNs.
                    // Google IMA defaults media loading to 8s; 12s reduces avoidable
                    // VAST 402 timeouts without changing auction eligibility.
                    settings.enablePreloading = true;
                    settings.loadVideoTimeout = 12000;
                    settings.prerollLoadVideoTimeout = 12000;
                    player.adsManager = event.getAdsManager(player.video, settings);
                    var adTypes = ima.AdEvent.Type;
                    if (position === 'preroll' && player.adsManager && typeof player.adsManager.getCuePoints === 'function') {
                        try {
                            var cuePoints = player.adsManager.getCuePoints() || [];
                            if (cuePoints.length > 0) {
                                player.adRules = true;
                                player.adRuleCuePoints = cuePoints.slice ? cuePoints.slice() : cuePoints;
                                player.adRulesHasPostroll = cuePoints.some ? cuePoints.some(function (point) { return Number(point) === -1; }) : false;
                                player.container.setAttribute('data-hm-video-ad-rules', '1');
                            }
                        } catch (error) {}
                    }
                    player.adsManager.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, function (errorEvent) {
                        var error = errorEvent && errorEvent.getError ? errorEvent.getError() : null;
                        if (currentRequest()) failContentAdBreak(player, error, 'playback-' + position, position);
                    });
                    player.adsManager.addEventListener(adTypes.LOADED, function () {
                        if (currentRequest()) setStatus(player.container, 'loaded-' + position);
                    });
                    player.adsManager.addEventListener(adTypes.STARTED, function () {
                        if (!currentRequest()) return;
                        window.clearTimeout(player.startupTimer);
                        tracePhase('Video start', player.container, { position: player.currentBreak });
                    setStatus(player.container, 'started');
                    });
                    if (adTypes.CONTENT_PAUSE_REQUESTED) player.adsManager.addEventListener(adTypes.CONTENT_PAUSE_REQUESTED, function () {
                        if (!currentRequest()) return;
                        if (player.adRules) player.adBreakPending = true;
                        // IMA may temporarily own this exact video element. Its
                        // media errors and ended events are not content failures.
                        setContentMediaOwnership(player, true);
                        try { if (player.video && player.video.pause) player.video.pause(); } catch (error) {}
                    });
                    if (adTypes.CONTENT_RESUME_REQUESTED) player.adsManager.addEventListener(adTypes.CONTENT_RESUME_REQUESTED, function () {
                        if (!currentRequest() || player.contentEnded) return;
                        // A VMAP may legitimately start with content and a
                        // later cue point. It has finished startup without an ad.
                        window.clearTimeout(player.startupTimer);
                        if (player.adRules) {
                            player.adBreakPending = false;
                            player.currentBreak = null;
                        }
                        setContentMediaOwnership(player, false);
                        resumeContent(player);
                    });
                    if (adTypes.ALL_ADS_COMPLETED) player.adsManager.addEventListener(adTypes.ALL_ADS_COMPLETED, function () {
                        if (!currentRequest()) return;
                        if (player.adRules) {
                            window.clearTimeout(player.startupTimer);
                            player.adBreakPending = false;
                            player.currentBreak = null;
                            setContentMediaOwnership(player, false);
                            if (player.contentEnded) finishContentPlayer(player, 'completed');
                            else resumeContent(player);
                            return;
                        }
                        finishContentAdBreak(player, position);
                    });
                    var dimensions = playerDimensions(player.container, player.size);
                    player.adsManager.init(dimensions[0], dimensions[1], ima.ViewMode.NORMAL);
                    if (player.adsManager.setVolume) player.adsManager.setVolume(0);
                    startAdManagerWhenViewable(player, function () {
                        if (currentRequest() && player.adsManager) {
                            armStartupPhase('media-start');
                            player.adsManager.start();
                        }
                    });
                    player.resizeHandler = function () {
                        if (!currentRequest() || !player.adsManager) return;
                        var resized = playerDimensions(player.container, player.size);
                        player.adsManager.resize(resized[0], resized[1], ima.ViewMode.NORMAL);
                    };
                    if (window.addEventListener) window.addEventListener('resize', player.resizeHandler);
                } catch (error) {
                    if (currentRequest()) failContentAdBreak(player, error, 'manager-' + position, position);
                }
            }, false);
            player.adsLoader.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, function (errorEvent) {
                var error = errorEvent && errorEvent.getError ? errorEvent.getError() : null;
                if (currentRequest()) failContentAdBreak(player, error, 'request-' + position, position);
            }, false);

            var request = new ima.AdsRequest();
            var dimensions = playerDimensions(player.container, player.size);
            request.adTagUrl = resolvedVastUrl(vastUrl, player, dimensions, position);
            request.linearAdSlotWidth = dimensions[0];
            request.linearAdSlotHeight = dimensions[1];
            request.nonLinearAdSlotWidth = dimensions[0];
            request.nonLinearAdSlotHeight = Math.max(1, Math.round(dimensions[1] / 3));
            var contentDuration = Number(player.video && player.video.duration || 0);
            if (Number.isFinite(contentDuration) && contentDuration > 0) request.contentDuration = contentDuration;
            if (request.setAdWillAutoPlay) request.setAdWillAutoPlay(true);
            if (request.setAdWillPlayMuted) request.setAdWillPlayMuted(true);
            if (request.setContinuousPlayback) request.setContinuousPlayback(false);
            tracePhase('VAST call', player.container, { position: player.currentBreak });
            player.adsLoader.requestAds(request);
        } catch (error) {
            if (currentRequest()) failContentAdBreak(player, error, 'initialization-' + position, position);
        }
    }

    function listenContent(player, target, name, callback) {
        if (!target || !target.addEventListener) return;
        target.addEventListener(name, callback);
        player.contentListeners.push([target, name, callback]);
    }

    function prepareContentPlayer(player, ima, vastUrl, contentUrl) {
        if (!player || player.destroyed) return;
        if (state.active && state.active !== player && !state.active.destroyed) {
            setStatus(player.container, 'duplicate');
            finishContentPlayer(player, 'duplicate');
            return;
        }
        state.active = player;
        player.contentMode = true;
        player.contentUrl = contentUrl;
        player.video.autoplay = false;
        player.video.muted = true;
        player.video.controls = true;
        player.video.preload = 'metadata';
        player.video.setAttribute('muted', '');
        player.video.setAttribute('controls', '');
        player.video.setAttribute('preload', 'metadata');
        player.video.setAttribute('aria-label', 'Accompanying video content');
        var surface = placementSurface(player);
        if (surface && surface.style && !player.floating && !(player.viewport && player.viewport.portal)) surface.style.position = 'relative';

        listenContent(player, player.video, 'playing', function () { markContentPlaying(player); });
        listenContent(player, player.video, 'error', function () {
            if (player.destroyed || player.adMediaActive) return;
            player.contentFailed = true;
            player.container.setAttribute('data-hm-video-content-error', player.contentStarted ? 'playback' : 'load');
            // Before preroll, keep the surface alive so the independent VAST
            // request can still run when the placement becomes viewable. After
            // an ad break has completed, a later content failure must not leave
            // a dead player pinned on the publisher page.
            if (player.preRollRequested && !player.adBreakPending) {
                finishContentPlayer(player, 'content-error');
            }
        });
        listenContent(player, player.video, 'timeupdate', function () {
            if (player.destroyed || player.adRules || player.midRollRequested || player.adBreakPending || !player.contentStarted || player.contentEnded) return;
            var duration = Number(player.video.duration || 0);
            var current = Number(player.video.currentTime || 0);
            var ratio = Number(player.container.getAttribute('data-hm-video-mid-roll-ratio') || 0.5);
            ratio = Number.isFinite(ratio) ? Math.max(0.1, Math.min(0.9, ratio)) : 0.5;
            if (duration > 1 && current / duration >= ratio) {
                player.midRollRequested = true;
                requestContentAdBreak(player, ima, vastUrl, 'midroll');
            }
        });
        player.contentEndedHandler = function () {
            if (player.destroyed || player.contentEnded || player.adBreakPending) return;
            player.contentEnded = true;
            if (player.adRules) {
                try {
                    if (player.adsLoader && player.adsLoader.contentComplete) player.adsLoader.contentComplete();
                    else {
                        finishContentPlayer(player, 'completed');
                        return;
                    }
                } catch (error) {
                    finishContentPlayer(player, 'completed');
                    return;
                }
                // contentComplete() triggers a scheduled post-roll when one exists.
                // If the ad-rules response has no post-roll cue, there is no later
                // terminal event to own teardown, so close the finished surface now.
                if (!player.adRulesHasPostroll) finishContentPlayer(player, 'completed');
                return;
            }
            if (!player.postRollRequested) {
                player.postRollRequested = true;
                requestContentAdBreak(player, ima, vastUrl, 'postroll');
            }
        };
        listenContent(player, player.video, 'ended', player.contentEndedHandler);
        player.contentEndedAttached = true;

        // Set the content source only after listeners exist. A broken CDN/video
        // must not suppress the preroll request; its error is recorded while the
        // VAST path remains independently eligible to run.
        player.video.src = contentUrl;
        player.container.setAttribute('data-hm-video-content-mode', 'accompanying');
        setStatus(player.container, 'content-ready');

        function beginPreroll() {
            if (player.destroyed || player.preRollRequested) return;
            player.preRollRequested = true;
            requestContentAdBreak(player, ima, vastUrl, 'preroll');
        }

        player.viewport.ready(beginPreroll);
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

        function armStartupPhase(phase) {
            window.clearTimeout(player.startupTimer);
            player.startupTimer = window.setTimeout(function () {
                failVideo(player, new Error('Video ad ' + phase + ' timed out'), phase + '-timeout');
            }, 15000);
        }
        armStartupPhase('request');
        try {
            player.displayContainer = new ima.AdDisplayContainer(player.adLayer, player.video);
            player.displayContainer.initialize();
            player.adsLoader = new ima.AdsLoader(player.displayContainer);
            player.adsLoader.addEventListener(ima.AdsManagerLoadedEvent.Type.ADS_MANAGER_LOADED, function (event) {
                if (player.destroyed || player.adsManager) return;
                armStartupPhase('viewability');
                try {
                var settings = new ima.AdsRenderingSettings();
                settings.restoreCustomPlaybackStateOnAdBreakComplete = true;
                // Preload the selected media and tolerate slower creative CDNs.
                // Google IMA defaults media loading to 8s; 12s reduces avoidable
                // VAST 402 timeouts without changing auction eligibility.
                settings.enablePreloading = true;
                settings.loadVideoTimeout = 12000;
                settings.prerollLoadVideoTimeout = 12000;
                player.adsManager = event.getAdsManager(player.video, settings);
                var adTypes = ima.AdEvent.Type;
                player.adsManager.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, function (errorEvent) {
                    var error = errorEvent && errorEvent.getError ? errorEvent.getError() : null;
                    failVideo(player, error, 'playback');
                });
                player.adsManager.addEventListener(adTypes.LOADED, function () { if (!player.destroyed) setStatus(player.container, 'loaded'); });
                player.adsManager.addEventListener(adTypes.STARTED, function () {
                    if (player.destroyed) return;
                    window.clearTimeout(player.startupTimer);
                    tracePhase('Video start', player.container, { position: player.currentBreak });
                    setStatus(player.container, 'started');
                    // Keep viewport observation alive for the ad-only fallback.
                    // A loaded ad still has to transition when the reader scrolls.
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
                startAdManagerWhenViewable(player, function () {
                    if (!player.destroyed && player.adsManager) {
                        armStartupPhase('media-start');
                        player.adsManager.start();
                    }
                });
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
            tracePhase('VAST call', player.container, { position: player.currentBreak });
            player.adsLoader.requestAds(request);
            if (player.rewarded) rewardEvent(player.container, 'horus:rewarded-opened', {});
        } catch (error) {
            failVideo(player, error, 'initialization');
        }
    }

    function failVideo(player, error, stage) {
        if (!player || player.destroyed) return;
        recordVideoError(player, error, stage);
        destroyPlayer(player, 'error');
    }

    function resolvedVastUrl(value, player, dimensions, breakPosition) {
        // GAM's tag generator emits placeholders, not a ready-to-request URL.
        // Expand known placeholders at request time without changing third-party tags.
        var tag = new URL(value);
        if (!/^(?:pubads|securepubads)\.g\.doubleclick\.net$/i.test(tag.hostname) || tag.pathname !== '/gampad/ads') return value;
        var page = new URL(window.location.href);
        page.hash = '';
        ['url', 'description_url'].forEach(function (name) {
            var current = tag.searchParams.get(name);
            if (player.contentMode || !current || /^\[(?:referrer_url|description_url)\]$/i.test(current)) tag.searchParams.set(name, page.href);
        });
        var correlator = tag.searchParams.get('correlator');
        if (player.contentMode || !correlator || /^\[timestamp\]$/i.test(correlator)) {
            tag.searchParams.set('correlator', String(player.requestCorrelator || Date.now()));
        }
        var accompanyingAvailable = player.contentMode && !player.contentFailed;
        tag.searchParams.set('vpmute', player.video.muted ? '1' : '0');
        tag.searchParams.set('vpa', player.contentMode ? 'auto' : (player.video.autoplay ? 'auto' : 'click'));
        // The GAM sz parameter describes the primary video ad slot. A
        // saved tag can outlive a responsive player-size change, so always
        // align Google VAST requests with this request's actual player size.
        tag.searchParams.set('sz', dimensions[0] + 'x' + dimensions[1]);
        // This runtime implements linear IMA video only, not overlay ads.
        tag.searchParams.set('vad_type', 'linear');
        if (!player.rewarded && accompanyingAvailable) {
            tag.searchParams.set('plcmt', '2');
            if (breakPosition) {
                var adRulesRequest = tag.searchParams.get('ad_rule') === '1';
                if (!adRulesRequest) tag.searchParams.set('vpos', breakPosition);
                else tag.searchParams.delete('vpos');
                tag.searchParams.set('vconp', '1');
                var duration = Number(player.video && player.video.duration || 0);
                if (Number.isFinite(duration) && duration > 0) tag.searchParams.set('vid_d', String(Math.max(1, Math.round(duration))));
            }
        } else if (!player.rewarded) {
            // If the platform content has already failed, the fail-open VAST
            // request still runs but must not claim Accompanying Content. Also
            // remove Horus' historical standalone plcmt=4 declaration.
            if (player.contentMode && player.contentFailed) {
                tag.searchParams.delete('plcmt');
                tag.searchParams.delete('vpos');
                tag.searchParams.delete('vid_d');
            } else if (tag.searchParams.get('plcmt') === '4') {
                tag.searchParams.delete('plcmt');
            }
        }
        return tag.href;
    }

    function waitUntilViewable(player, ima, vastUrl) {
        setStatus(player.container, 'waiting-viewability');
        player.viewport.ready(function () { startAds(player, ima, vastUrl); });
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
        var vastUrl = vastUrlAttribute(container);
        if (!vastUrl || !/^https:\/\//i.test(vastUrl)) {
            setStatus(container, 'invalid');
            if (!rewardedMode(container)) hideFloatingSurface(container);
            return;
        }
        var rewarded = rewardedMode(container);
        var player = rewarded ? null : createPlayer(container);
        var contentUrl = rewarded ? '' : validContentUrl(container.getAttribute('data-hm-video-content-url'));
        setStatus(container, 'loading-sdk');
        loadSdk().then(function (ima) {
            if (rewarded) {
                if (container.getAttribute('data-hm-video-runtime-state') !== 'dismissed') prepareRewarded(container, ima, vastUrl);
            } else if (!player.destroyed && contentUrl) {
                prepareContentPlayer(player, ima, vastUrl, contentUrl);
            } else if (!player.destroyed) {
                // Fail-open compatibility: if the platform content source is
                // absent, the VAST request still runs through the legacy player.
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
