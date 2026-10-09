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
        width = Math.max(1, width || fallback[0]);
        var ratio = fallback[0] / fallback[1];
        return [width, Math.max(1, Math.round(width / ratio))];
    }

    function inlineWidthLimit(size) {
        // The configured master remains the compact floating size and ratio.
        // Inline media fills the editorial column, capped on wide layouts; never
        // shrink an existing custom master wider than the normal inline cap.
        return Math.max(640, Number(size[0]) || 0);
    }

    function managerDimensions(player) {
        // IMA lays out its iframe in CSS pixels. A portal may mirror a scaled
        // publisher ancestor; visual request/viewability dimensions stay separate.
        var container = player.container;
        if (player.viewport && player.viewport.portal && !player.floating && container.clientWidth > 0 && container.clientHeight > 0) {
            return [Math.max(1, Math.round(container.clientWidth)), Math.max(1, Math.round(container.clientHeight))];
        }
        return playerDimensions(container, player.size);
    }

    function createPlayer(container) {
        if (container.__hmVideoPlayer) return container.__hmVideoPlayer;
        var size = selectedSize(container);
        var rewarded = rewardedMode(container);
        clearContainer(container);
        var video = document.createElement('video');
        var adLayer = document.createElement('div');
        var closeButton = rewarded ? document.createElement('button') : null;
        var startsMuted = container.getAttribute('data-hm-video-fixed-instream') !== '1' && container.getAttribute('data-hm-video-muted') !== '0';
        // Preserve the initial mute default through insertion/portal setup in
        // WebKit. Later user mute choices change only the live property.
        if (startsMuted) video.setAttribute('muted', '');
        video.muted = startsMuted;
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
        container.style.maxWidth = rewarded ? 'none' : String(inlineWidthLimit(size)) + 'px';
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
            gestureDisplayContainer: null,
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
            contentInventoryType: container.getAttribute('data-hm-video-content-mode') === 'instream' ? 'instream' : 'accompanying',
            contentAutoPlay: video.autoplay === true,
            startupPlaybackState: null,
            startupPlaybackGeneration: 0,
            contentGesturePending: false,
            contentStarted: false,
            contentEnded: false,
            contentFailed: false,
            contentPausedByUser: false,
            contentPlayGeneration: 0,
            adRuntimeGeneration: 0,
            adMediaActive: false,
            adPresentationActive: false,
            adResponseSeen: false,
            adPlaybackStarted: false,
            audioIntentChanged: false,
            prerollAttempts: 0,
            prerollRetryTimer: null,
            nextContentAdTimer: null,
            adBreakCompleted: false,
            repeatMidrollElapsed: 0,
            repeatContentSample: null,
            contentBuffering: false,
            nonLinearAdActive: false,
            currentAd: null,
            adRequestIntent: null,
            audioSync: null,
            audioSyncDepth: 0,
            viewerAudioState: null,
            nonLinearRequestSize: null,
            preRollRequested: false,
            midRollRequested: false,
            midRollConsumedByOverlay: false,
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
        player.viewerAudioState = mediaAudioState(player);
        listenContent(player, video, 'volumechange', function () {
            if (player.destroyed || player.closing || player.audioSyncDepth) return;
            syncManagerAudio(player);
            updateContentAdUi(player);
        });
        if (closeButton && closeButton.addEventListener) closeButton.addEventListener('click', function () {
            destroyPlayer(player, 'dismissed');
        });
        container.__hmDestroy = function (reason) { destroyPlayer(player, reason || 'dismissed'); };
        installPlayerPresentation(player);
        installVideoViewport(player);
        if (player.floating && typeof window.CustomEvent === 'function' && window.dispatchEvent) {
            window.dispatchEvent(new window.CustomEvent('horus:video-floated', { detail: { placementId: String(placementSurface(player).getAttribute('data-placement') || '') } }));
        }
        return player;
    }

    function destroyPlayer(player, reason) {
        if (!player || player.destroyed) return;
        player.destroyed = true;
        player.audioSync = null;
        player.startupPlaybackGeneration += 1;
        window.clearTimeout(player.startupPlaybackTimer);
        cancelPlayerMotion(player);
        var shell = placementSurface(player);
        if (shell) shell.__hmAnimateDismiss = null;
        player.contentPlayGeneration += 1;
        player.adRuntimeGeneration += 1;
        window.clearTimeout(player.startupTimer);
        window.clearTimeout(player.nextContentAdTimer);
        player.nextContentAdTimer = null;
        window.clearTimeout(player.prerollRetryTimer);
        player.prerollRetryTimer = null;
        player.repeatContentSample = null;
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
        try { if (player.gestureDisplayContainer && player.gestureDisplayContainer !== player.displayContainer && player.gestureDisplayContainer.destroy) player.gestureDisplayContainer.destroy(); } catch (error) {}
        player.gestureDisplayContainer = null;
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

    function cancelPlayerMotion(player) {
        var motion = player && player.motion;
        if (!motion) return;
        player.motion = null;
        window.clearTimeout(motion.timer);
        if (motion.media && motion.media.removeEventListener) motion.media.removeEventListener('change', motion.finish);
        if (document.removeEventListener) document.removeEventListener('visibilitychange', motion.visibility);
        motion.surface.removeAttribute('data-hm-video-motion');
        if (motion.layer && !motion.layerWasInert) motion.layer.removeAttribute('inert');
        try {
            motion.animation.onfinish = null;
            motion.animation.oncancel = null;
            motion.animation.cancel();
        } catch (error) {}
    }

    // Individual translate leaves portal scaling and publisher transforms intact.
    // Only the real shell moves: never clone, detach, or recreate a playing ad.
    function animatePlayer(player, phase, duration, done) {
        var surface = placementSurface(player), media;
        try { media = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)'); } catch (error) {}
        if (!surface || typeof surface.animate !== 'function' || player.rewarded || document.visibilityState === 'hidden' || media && media.matches) {
            cancelPlayerMotion(player);
            return false;
        }
        var previous = player.motion && window.getComputedStyle ? window.getComputedStyle(surface) : null;
        var opacity = previous ? previous.opacity : phase === 'enter' || phase === 'return' ? '0' : '1';
        var translate = previous ? previous.translate : phase === 'enter' ? '0 14px' : '0 0';
        cancelPlayerMotion(player);
        var leaving = phase === 'exit' || phase === 'dismiss';
        var animation;
        try {
            animation = surface.animate([
                { opacity: opacity, translate: translate || '0 0' },
                { opacity: leaving ? '0' : '1', translate: leaving ? '0 8px' : '0 0' },
            ], { duration: duration, easing: leaving ? 'cubic-bezier(.4,0,1,1)' : 'cubic-bezier(.16,1,.3,1)', fill: 'both' });
        } catch (error) { return false; }
        var motion = player.motion = { animation: animation, surface: surface, media: media, phase: phase,
            layer: player.adLayer, layerWasInert: player.adLayer && player.adLayer.hasAttribute('inert') };
        // A fading creative is not a click target; external close controls stay usable.
        if (motion.layer) motion.layer.setAttribute('inert', '');
        surface.setAttribute('data-hm-video-motion', phase);
        motion.finish = function () {
            if (player.motion !== motion) return;
            cancelPlayerMotion(player);
            if (!player.destroyed && done) done();
        };
        motion.visibility = function () { if (document.visibilityState === 'hidden') motion.finish(); };
        animation.onfinish = motion.finish;
        animation.oncancel = motion.finish;
        if (media && media.addEventListener) media.addEventListener('change', motion.finish);
        if (document.addEventListener) document.addEventListener('visibilitychange', motion.visibility);
        // A publisher may cancel animations or a background tab may stall them.
        motion.timer = window.setTimeout(motion.finish, duration + 100);
        return true;
    }

    function dismissPlayerWithMotion(player, done) {
        if (!player || player.destroyed || !player.floating) return false;
        if (player.closing) return true;
        if (!animatePlayer(player, 'dismiss', 140, done)) return false;
        player.closing = true;
        player.pendingAdStart = null;
        player.adRuntimeGeneration += 1;
        player.contentPlayGeneration += 1;
        var surface = placementSurface(player);
        surface.setAttribute('inert', '');
        importantStyle(surface.style, 'pointer-events', 'none');
        // Silence immediately; the short visual exit must not extend playback.
        try { if (player.adsManager && player.adsManager.setVolume) player.adsManager.setVolume(0); } catch (error) {}
        try { if (player.adsManager && player.adsManager.pause) player.adsManager.pause(); } catch (error) {}
        try { player.video.pause(); } catch (error) {}
        return true;
    }

    function validContentUrl(value) {
        try {
            var url = new URL(String(value || ''));
            return url.protocol === 'https:' ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function setAdPresentation(player, active) {
        if (!player || player.destroyed || player.closing) return;
        player.adPresentationActive = active === true;
        player.container.setAttribute('data-hm-video-ad-present', active ? '1' : '0');
        updateContentAdUi(player);
        // The arrival/end of an ad must re-evaluate saved scroll geometry even
        // when the reader is stationary. No DOM reparenting or media reloads.
        if (player.viewport) player.viewport.update();
    }

    function releasePendingAdStart(player) {
        if (!player || player.destroyed || player.closing || player.motion || typeof player.pendingAdStart !== 'function') return false;
        if (!player.rewarded && (document.visibilityState === 'hidden' || Number(player.visibleRatio || 0) < 0.5)) return false;
        var start = player.pendingAdStart;
        player.pendingAdStart = null;
        try { start(); return true; } catch (error) { return false; }
    }

    function startAdManagerWhenViewable(player, start) {
        if (!player || player.destroyed || player.closing || typeof start !== 'function') return;
        // The SDK response can beat the next scroll/observer frame (notably
        // on mobile WebKit). Measure the current position before authorizing
        // playback rather than using the previously visible inline geometry.
        if (player.viewport && player.viewport.update) player.viewport.update();
        if (player.destroyed) return;
        var unmeasurableAdapter = typeof window.IntersectionObserver !== 'function' && !player.container.getBoundingClientRect;
        if (player.rewarded || !player.motion && document.visibilityState !== 'hidden' && (Number(player.visibleRatio || 0) >= 0.5 || unmeasurableAdapter)) {
            start();
            return;
        }
        player.pendingAdStart = start;
        setStatus(player.container, 'waiting-ad-viewability');
    }

    function presentUnscheduledAdResponse(player, vastUrl) {
        // ADS_MANAGER_LOADED confirms an ad response, not media preloading.
        // iOS cannot preload media: waiting for LOADED before floating and for
        // floating before start() would deadlock a response arriving after scroll.
        // A VMAP/playlist manager alone does not confirm a current ad break.
        if (!player || player.destroyed || player.closing || !player.adsManager || player.adRules || player.currentAd) return;
        try {
            if (adRulesTag(new URL(vastUrl))) return;
            if (typeof player.adsManager.getCuePoints !== 'function' || player.adsManager.getCuePoints().length) return;
        } catch (error) { return; }
        setAdPresentation(player, true);
    }

    // Chrome is a sibling of the measured media surface. Never put controls
    // over an IMA creative, alter its dimensions, or rebuild its playing DOM.
    function installPlayerPresentation(player) {
        if (player.rewarded) return;
        var surface = placementSurface(player);
        if (!surface || surface === player.container || !surface.insertBefore) return;
        surface.__hmAnimateDismiss = function (done) { return dismissPlayerWithMotion(player, done); };
        var initiallyFloating = surface.getAttribute('data-hm-floating-video-active') === '1';
        surface.setAttribute('data-hm-video-shell', '1');
        surface.setAttribute('data-hm-video-chrome-height', '44');
        surface.setAttribute('data-hm-video-master-width', String(player.size[0]));
        surface.setAttribute('data-hm-video-master-height', String(player.size[1]));
        surface.setAttribute('data-hm-video-floating-state', initiallyFloating ? 'floating' : 'inline');
        var styles = { position: 'relative', display: 'block', width: '100%', 'max-width': inlineWidthLimit(player.size) + 'px',
            height: 'auto', 'min-height': '0', 'margin-left': 'auto', 'margin-right': 'auto', padding: '0',
            border: '0', 'border-radius': '16px', 'box-sizing': 'border-box', background: '#050b1e',
            'box-shadow': '0 12px 36px rgba(5,8,22,.18),0 0 0 1px rgba(157,169,194,.18)', 'text-align': 'left', isolation: 'isolate' };
        if (initiallyFloating) {
            styles.position = 'fixed'; styles.margin = '0'; styles['aspect-ratio'] = 'auto';
            styles.width = 'min(' + player.size[0] + 'px, calc(100vw - 32px - env(safe-area-inset-left, 0px) - env(safe-area-inset-right, 0px)))';
            styles['max-width'] = 'calc(100vw - 32px - env(safe-area-inset-left, 0px) - env(safe-area-inset-right, 0px))';
            styles.right = 'calc(16px + env(safe-area-inset-right, 0px))';
            player.floating = true;
        }
        Object.keys(styles).forEach(function (name) { importantStyle(surface.style, name, styles[name]); });
        var rail = document.createElement('div');
        rail.setAttribute('data-hm-video-chrome', '1');
        rail.style.cssText = 'all:initial;box-sizing:border-box;display:flex;align-items:center;gap:8px;width:100%;height:44px;padding:0 52px 0 12px;border-radius:16px 16px 0 0;background:linear-gradient(110deg,#0a2153,#07132e 60%,#050b1e);box-shadow:inset 0 1px rgba(255,214,107,.22);color:#f6f8ff;font:600 12px/1.4 Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;letter-spacing:.02em;overflow:hidden;direction:ltr;';
        rail.style.cssText = rail.style.cssText.replace(/;/g, ' !important;');
        var label = document.createElement('span');
        label.setAttribute('data-hm-video-label', '1');
        label.style.cssText = 'display:flex !important;align-items:center !important;gap:8px !important;min-width:0 !important;color:inherit !important;font:inherit !important;';
        var brand = document.createElement('span');
        brand.textContent = 'HORUS';
        brand.style.cssText = 'display:block !important;color:#ffd66b !important;font:700 10px/1.4 system-ui,sans-serif !important;letter-spacing:.12em !important;';
        var emblem = document.createElement('img');
        emblem.src = 'https://horusmedia.net/assets/images/horusmedia-emblem-header.png';
        emblem.alt = '';
        emblem.setAttribute('aria-hidden', 'true');
        emblem.setAttribute('loading', 'lazy');
        emblem.setAttribute('decoding', 'async');
        emblem.style.cssText = 'display:block !important;width:24px !important;height:24px !important;object-fit:contain !important;flex:none !important;';
        emblem.onerror = function () { importantStyle(emblem.style, 'display', 'none'); };
        var status = document.createElement('span');
        status.textContent = 'Video';
        status.setAttribute('data-hm-video-badge', '1');
        status.style.cssText = 'display:block !important;padding:3px 7px !important;border:1px solid rgba(157,169,194,.24) !important;border-radius:999px !important;color:#c6d1e6 !important;font:500 10px/1.4 system-ui,sans-serif !important;white-space:nowrap !important;';
        label.appendChild(emblem); label.appendChild(brand); label.appendChild(status); rail.appendChild(label);
        player.chromeLabel = label;
        player.chromeStatus = status;
        surface.insertBefore(rail, surface.firstChild || null);
        player.chrome = rail;
        importantStyle(player.container.style, 'margin', '0 auto');
        importantStyle(player.container.style, 'border-radius', '0 0 16px 16px');
        importantStyle(player.container.style, 'box-sizing', 'border-box');
        player.video.setAttribute('aria-label', 'Video player');
        var close = surface.querySelector && surface.querySelector('[data-hm-placement-close="1"]');
        if (close) {
            close.setAttribute('aria-label', 'Close video player');
            close.setAttribute('title', 'Close video player');
            var buttonStyles = { top: '0', right: '4px', width: '44px', height: '44px', 'min-width': '44px', 'min-height': '44px',
                'max-width': '44px', 'max-height': '44px', 'line-height': '44px', 'font-size': '24px',
                color: '#c6d1e6', background: 'transparent', border: '0', 'border-radius': '12px', 'touch-action': 'manipulation' };
            Object.keys(buttonStyles).forEach(function (name) { importantStyle(close.style, name, buttonStyles[name]); });
            close.addEventListener('focus', function () { importantStyle(close.style, 'outline', '2px solid #f1b733'); importantStyle(close.style, 'outline-offset', '-4px'); });
            close.addEventListener('blur', function () { importantStyle(close.style, 'outline', 'none'); });
        }
    }

    // Layout is independent of content/VAST availability. Observe the inline
    // position before loading IMA so a slow SDK cannot lose the scroll history.
    function installVideoViewport(player) {
        if (!player || player.rewarded || player.viewport) return;
        var surface = placementSurface(player);
        var floatingValue = player.container.getAttribute('data-hm-video-inline-to-floating');
        player.inlineToFloating = floatingValue === '1' || (floatingValue !== '0' && surface && surface.getAttribute('data-hm-video-inline-to-floating') === '1');
        var layout = player.viewport = { canRequestOffscreen: false, inlineCss: surface && surface.style ? surface.style.cssText : '', surface: surface, anchor: null, portal: false, frame: null, stopped: false, onVisible: null, scrolled: false };
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
            var hidden = document.visibilityState === 'hidden' || node.isConnected === false || !(rect.width > 0 && rect.height > 0);
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
            write(anchor.style, 'width', '100%');
            write(anchor.style, 'max-width', inlineWidthLimit(player.size) + 'px');
            write(anchor.style, 'height', 'auto');
            write(anchor.style, 'aspect-ratio', player.size[0] + ' / ' + player.size[1]);
            write(anchor.style, 'box-sizing', 'content-box');
            write(anchor.style, 'padding-top', (Number(surface.getAttribute('data-hm-video-chrome-height')) || 0) + 'px');
            if (window.getComputedStyle) {
                var css = window.getComputedStyle(surface);
                ['margin-top', 'margin-bottom'].forEach(function (name) { write(anchor.style, name, css.getPropertyValue(name)); });
                write(anchor.style, 'margin-left', 'auto'); write(anchor.style, 'margin-right', 'auto');
            }
            surface.parentNode.insertBefore(anchor, surface);
            layout.anchor = anchor;
            if (layout.resize) layout.resize.observe(anchor);
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
            if (player.destroyed || layout.stopped || document.visibilityState === 'hidden') return;
            if (player.visibleRatio < 0.5 && !layout.canRequestOffscreen) return;
            if (layout.onVisible) { var ready = layout.onVisible; layout.onVisible = null; ready(); }
            releasePendingAdStart(player);
        }
        function accept(ratio, data) {
            if (ratio < 0.5 || document.visibilityState === 'hidden') player.repeatContentSample = null;
            player.visibleRatio = Math.max(0, Math.min(1, ratio));
            if (!player.floating && document.visibilityState !== 'hidden' && player.visibleRatio >= 0.5) player.wasInlineVisible = true;
            // Seeing any part of the original media/anchor grants layout
            // eligibility only. A tall inline player may never fit 50% in a
            // short viewport; ad requests/starts still require real viewability.
            if (!player.floating && document.visibilityState !== 'hidden' && data && !data.hidden && data.ratio > 0) player.wasInlineAnchorVisible = true;
            var outside = data ? !data.hidden && data.rect.bottom <= data.view.top + 1 : ratio <= 0.01;
            layout.canRequestOffscreen = player.inlineToFloating && (player.wasInlineVisible || player.wasInlineAnchorVisible) && outside && (layout.scrolled || !data);
            if (!player.floating && player.adPresentationActive && layout.canRequestOffscreen) {
                layout.reserve();
                floatContentPlayer(player);
                var floated = geometry(player.container);
                player.visibleRatio = floated ? floated.ratio : 1;
            }
            notify();
        }
        layout.update = function () {
            layout.frame = null;
            if (layout.stopped || player.destroyed || player.closing) return;
            if (surface && (surface.isConnected === false || surface.getAttribute('data-hm-placement-dismissed') === '1') || layout.anchor && layout.anchor.isConnected === false) {
                destroyPlayer(player, 'dismissed'); return;
            }
            layout.scrolled = layout.scrolled || Number(window.scrollY || window.pageYOffset || 0) !== initialScrollY || Number(window.scrollX || window.pageXOffset || 0) !== initialScrollX;
            // Follow the original slot in both directions, never the fixed box.
            // Re-entry restores styling only: no reparent/reload of the IMA iframe.
            if (player.floating && layout.anchor) {
                var original = geometry(layout.anchor);
                if (!player.adPresentationActive || original && !original.hidden && original.ratio > 0 && original.rect.bottom > original.view.top + 8) {
                    if (!layout.returnReady) {
                        if (player.motion && player.motion.phase === 'exit') return;
                        if (animatePlayer(player, 'exit', 140, function () { layout.returnReady = true; layout.update(); })) return;
                    }
                    layout.returnReady = false;
                    player.floating = false;
                    surface.setAttribute('data-hm-floating-video-active', '0');
                    surface.setAttribute('data-hm-video-floating-state', 'inline');
                    surface.style.cssText = layout.inlineCss;
                    if (!layout.portal) {
                        if (layout.resize && layout.resize.unobserve) layout.resize.unobserve(layout.anchor);
                        if (layout.anchor.parentNode) layout.anchor.parentNode.removeChild(layout.anchor);
                        layout.anchor = null;
                    }
                    if (original && !original.hidden && original.ratio > 0) {
                        animatePlayer(player, 'return', 160, function () { layout.update(); });
                    }
                } else {
                    layout.returnReady = false;
                    if (player.motion && player.motion.phase === 'exit') {
                        animatePlayer(player, 'enter', 180, function () { layout.update(); });
                    }
                }
            }
            var data = geometry(player.floating ? player.container : layout.anchor || player.container);
            if (!data) { notify(); return; }
            if (layout.portal && !player.floating) {
                var rect = data.rect, clip = data.clip;
                // offsetWidth/Height round fractional CSS pixels. That rounding
                // must not masquerade as a publisher transform and shrink chrome.
                var anchorCss = window.getComputedStyle(layout.anchor);
                var inlineWidth = parseFloat(anchorCss.width), inlineHeight = parseFloat(anchorCss.height);
                if (anchorCss.boxSizing !== 'border-box') {
                    inlineWidth += (parseFloat(anchorCss.paddingLeft) || 0) + (parseFloat(anchorCss.paddingRight) || 0)
                        + (parseFloat(anchorCss.borderLeftWidth) || 0) + (parseFloat(anchorCss.borderRightWidth) || 0);
                    inlineHeight += (parseFloat(anchorCss.paddingTop) || 0) + (parseFloat(anchorCss.paddingBottom) || 0)
                        + (parseFloat(anchorCss.borderTopWidth) || 0) + (parseFloat(anchorCss.borderBottomWidth) || 0);
                }
                inlineWidth = inlineWidth > 0 ? inlineWidth : Number(layout.anchor.offsetWidth) || rect.width;
                inlineHeight = inlineHeight > 0 ? inlineHeight : Number(layout.anchor.offsetHeight) || rect.height;
                var scaleX = rect.width / inlineWidth, scaleY = rect.height / inlineHeight;
                write(surface.style, 'position', 'fixed'); write(surface.style, 'margin', '0');
                write(surface.style, 'top', rect.top + 'px'); write(surface.style, 'left', rect.left + 'px');
                write(surface.style, 'right', 'auto'); write(surface.style, 'bottom', 'auto');
                write(surface.style, 'width', inlineWidth + 'px'); write(surface.style, 'height', inlineHeight + 'px');
                write(surface.style, 'transform-origin', 'top left');
                write(surface.style, 'transform', 'scale(' + scaleX + ',' + scaleY + ')');
                write(surface.style, 'visibility', data.hidden || data.ratio <= 0 ? 'hidden' : 'visible');
                write(surface.style, 'clip-path', data.ratio >= 0.999 ? 'none' : 'inset(' + Math.max(0, clip.top - rect.top) / scaleY + 'px ' + Math.max(0, rect.right - clip.right) / scaleX + 'px ' + Math.max(0, rect.bottom - clip.bottom) / scaleY + 'px ' + Math.max(0, clip.left - rect.left) / scaleX + 'px)');
            }
            var visibleRatio = data.ratio;
            if (layout.portal && !player.floating && player.container.getBoundingClientRect) {
                var media = player.container.getBoundingClientRect();
                visibleRatio = data.hidden || !(media.width > 0 && media.height > 0) ? 0
                    : Math.max(0, Math.min(media.right, data.clip.right) - Math.max(media.left, data.clip.left))
                    * Math.max(0, Math.min(media.bottom, data.clip.bottom) - Math.max(media.top, data.clip.top)) / (media.width * media.height);
            }
            accept(visibleRatio, data);
            if (player.adsManager && player.adsManager.resize) {
                var size = managerDimensions(player), signature = size.join('x');
                if (layout.size !== signature) {
                    layout.size = signature;
                    try { player.adsManager.resize(size[0], size[1], window.google && window.google.ima ? window.google.ima.ViewMode.NORMAL : 'normal'); } catch (error) {}
                    checkNonLinearFit(player);
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
        if (!player || player.destroyed || !player.inlineToFloating || !player.adPresentationActive || player.floating) return;
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
        importantStyle(surface.style, 'right', 'calc(16px + env(safe-area-inset-right, 0px))');
        importantStyle(surface.style, 'left', 'auto');
        importantStyle(surface.style, 'top', 'auto');
        importantStyle(surface.style, 'transform', 'none');
        importantStyle(surface.style, 'margin', '0');
        importantStyle(surface.style, 'width', 'min(' + player.size[0] + 'px, calc(100vw - 32px - env(safe-area-inset-left, 0px) - env(safe-area-inset-right, 0px)))');
        importantStyle(surface.style, 'max-width', 'calc(100vw - 32px - env(safe-area-inset-left, 0px) - env(safe-area-inset-right, 0px))');
        importantStyle(surface.style, 'aspect-ratio', player.chrome ? 'auto' : player.size[0] + ' / ' + player.size[1]);
        surface.setAttribute('data-hm-video-master-width', String(player.size[0]));
        surface.setAttribute('data-hm-video-master-height', String(player.size[1]));
        importantStyle(surface.style, 'box-sizing', 'border-box');
        importantStyle(surface.style, 'background', '#050b1e');
        importantStyle(surface.style, 'box-shadow', '0 20px 56px rgba(5,8,22,.32),0 0 0 1px rgba(241,183,51,.22)');
        importantStyle(surface.style, 'bottom', 'calc(16px + env(safe-area-inset-bottom, 0px))');
        animatePlayer(player, 'enter', 220, function () { if (player.viewport) player.viewport.update(); });
        releasePendingAdStart(player);
        try {
            if (player.adsManager && player.adsManager.resize) {
                var dimensions = managerDimensions(player);
                player.adsManager.resize(dimensions[0], dimensions[1], window.google && window.google.ima ? window.google.ima.ViewMode.NORMAL : 'normal');
                checkNonLinearFit(player);
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

    function mixedContentAds(player) {
        return player.contentMode && !player.rewarded && !player.contentFailed
            && player.container.getAttribute('data-hm-video-ad-format') !== 'video_only';
    }

    function adRulesTag(tag) {
        return tag.searchParams.get('ad_rule') === '1' || /^(?:xml_)?vmap/i.test(tag.searchParams.get('output') || '');
    }

    function mediaAudioState(player) {
        var volume = Number(player.video.volume);
        volume = Number.isFinite(volume) ? Math.max(0, Math.min(1, volume)) : 1;
        return { muted: player.video.muted === true, volume: volume };
    }

    function writeMediaAudioState(player, audio) {
        if (!audio || player.destroyed || player.closing) return;
        player.audioSyncDepth += 1;
        try {
            if (audio.muted && !player.video.muted) player.video.muted = true;
            if (player.video.volume !== audio.volume) player.video.volume = audio.volume;
            if (player.video.muted !== audio.muted) player.video.muted = audio.muted;
        } finally { player.audioSyncDepth -= 1; }
    }

    function sameAudioState(left, right) {
        return left && right && left.muted === right.muted && left.volume === right.volume;
    }

    function viewerAudioState(player, explicitChoice) {
        var audio = mediaAudioState(player), binding = player.audioSync;
        // Native volumechange has no source marker. Only recognize the known
        // pre-break snapshot during IMA's terminal restoration window, never
        // while an ad is playing or after content has resumed.
        if (binding && binding.restoring && binding.preferredAudio && !explicitChoice
            && sameAudioState(audio, binding.restoreBaseline)) {
            audio = binding.preferredAudio;
            writeMediaAudioState(player, audio);
        } else if (binding) {
            // Native/host controls are just as authoritative as IMA controls.
            // Keep their latest choice through the same bounded SDK restore.
            binding.preferredAudio = audio;
        }
        if (explicitChoice || player.viewerAudioState && !sameAudioState(audio, player.viewerAudioState)) player.audioIntentChanged = true;
        player.viewerAudioState = audio;
        return audio;
    }

    function finishAudioRestoration(player) {
        viewerAudioState(player);
        if (player.audioSync) {
            player.audioSync.restoring = false;
            player.audioSync.preferredAudio = null;
        }
    }

    function syncManagerAudio(player, explicitChoice) {
        if (player.audioSyncDepth || player.destroyed || player.closing) return;
        var audio = viewerAudioState(player, explicitChoice), binding = player.audioSync;
        if (!binding || !binding.current()) return;
        var volume = audio.muted ? 0 : audio.volume;
        if (binding.volume === volume) return;
        binding.volume = volume;
        player.audioSyncDepth += 1;
        try { if (binding.manager.setVolume) binding.manager.setVolume(volume); }
        catch (error) { binding.volume = null; }
        finally { player.audioSyncDepth -= 1; }
    }

    function initializeAdManager(player, ima, currentRequest) {
        var manager = player.adsManager, audio = viewerAudioState(player);
        var dimensions = managerDimensions(player);
        player.viewerAudioState = audio;
        // init() and teardown may restore the shared media element. Neither is
        // a new viewer choice, and neither may replace a pending mute/volume.
        player.audioSyncDepth += 1;
        try { manager.init(dimensions[0], dimensions[1], ima.ViewMode.NORMAL); }
        finally { player.audioSyncDepth -= 1; }
        if (player.destroyed || player.closing || player.adsManager !== manager || currentRequest && !currentRequest()) return false;
        writeMediaAudioState(player, audio);
        var binding = player.audioSync = { manager: manager, volume: null, restoreBaseline: audio, restoring: false, preferredAudio: null, current: function () {
            return !player.destroyed && !player.closing && player.audioSync === binding && player.adsManager === manager
                && (!currentRequest || currentRequest());
        } };
        function adVolumeChanged() {
            if (!binding.current() || player.audioSyncDepth || !manager.getVolume) return;
            var volume;
            try { volume = Number(manager.getVolume()); } catch (error) { return; }
            if (!Number.isFinite(volume)) return;
            volume = Math.max(0, Math.min(1, volume));
            // setVolume can echo synchronously or later. Ignore both without
            // losing a genuine change made through IMA's own volume controls.
            if (binding.volume === volume) return;
            binding.volume = volume;
            player.audioIntentChanged = true;
            var live = mediaAudioState(player);
            var previous = player.viewerAudioState;
            player.viewerAudioState = { muted: volume === 0,
                volume: volume > 0 ? volume : live.volume > 0 ? live.volume : previous ? previous.volume : live.volume };
            binding.preferredAudio = player.viewerAudioState;
            writeMediaAudioState(player, player.viewerAudioState);
            updateContentAdUi(player);
        }
        [ima.AdEvent.Type.VOLUME_CHANGED, ima.AdEvent.Type.VOLUME_MUTED].forEach(function (name) {
            if (name) manager.addEventListener(name, adVolumeChanged);
        });
        syncManagerAudio(player);
        return binding.current();
    }

    function fixedAudibleInventory(player) {
        return player.container.getAttribute('data-hm-video-fixed-instream') === '1';
    }

    function adPlaybackIntent(player) {
        var audio = mediaAudioState(player);
        var muted = audio.muted || audio.volume === 0;
        // Classification is publisher-declared. Playback signals describe the
        // actual content start and live audio level, including a zero-volume slider.
        return { autoPlay: player.contentMode ? player.contentAutoPlay : player.video.autoplay === true,
            muted: muted, volume: muted ? 0 : audio.volume };
    }

    function nonLinearDimensions(player, dimensions) {
        var width = dimensions[0], height = dimensions[1];
        if (player.inlineToFloating || player.floating) {
            var viewportWidth = Number(window.innerWidth || document.documentElement.clientWidth || width);
            var compactWidth = Math.min(player.size[0], Math.max(1, viewportWidth - 32));
            // Account for safe-area insets using the same CSS contract as the
            // floating surface, without changing/reparenting the playing IMA DOM.
            if (document.body && document.body.appendChild && window.getComputedStyle) {
                var probe = document.createElement('div');
                probe.style.cssText = 'position:fixed;visibility:hidden;pointer-events:none;height:0;width:calc(100vw - 32px - env(safe-area-inset-left, 0px) - env(safe-area-inset-right, 0px));';
                document.body.appendChild(probe);
                if (probe.getBoundingClientRect) compactWidth = Math.min(compactWidth, Math.max(1, probe.getBoundingClientRect().width));
                if (probe.parentNode) probe.parentNode.removeChild(probe);
            }
            width = Math.min(width, Math.floor(compactWidth));
            height = Math.min(height, Math.floor(compactWidth * player.size[1] / player.size[0]));
        }
        // Advertise the full usable area, never fabricated inventory sizes.
        // IMA derives afvsz; short/small masters can legitimately support none.
        return [Math.max(1, Math.floor(width)), Math.max(1, Math.floor(height))];
    }

    function controlIcon(button, name, label) {
        var paths = {
            play: '<path d="m9 5 11 7-11 7Z" fill="currentColor" stroke="none"/>',
            pause: '<path d="M8 5v14M16 5v14" stroke-width="3"/>',
            muted: '<path d="m11 5-6 4H2v6h3l6 4Z"/><path d="m16 9 6 6m0-6-6 6"/>',
            sound: '<path d="m11 5-6 4H2v6h3l6 4Z"/><path d="M15 8a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14"/>',
        };
        if (button.getAttribute('data-hm-icon') !== name) {
            button.innerHTML = '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="display:block!important;width:18px!important;height:18px!important;margin:auto!important;pointer-events:none!important">' + paths[name] + '</svg>';
            button.setAttribute('data-hm-icon', name);
        }
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    }

    function updateContentAdUi(player) {
        var active = player.adMediaActive || player.nonLinearAdActive || !player.contentMode && player.adPresentationActive;
        var contentControls = player.contentMode && !player.adMediaActive;
        if (player.adLayer && player.adLayer.style) player.adLayer.style.pointerEvents = active ? 'auto' : 'none';
        if (player.chromeLabel) {
            var width = playerDimensions(player.container, player.size)[0];
            importantStyle(player.chromeLabel.style, 'display', contentControls && width < 300 ? 'none' : 'flex');
        }
        if (player.chromeStatus) {
            player.chromeStatus.textContent = player.adPresentationActive ? 'Ad' : 'Video';
            importantStyle(player.chromeStatus.style, 'color', player.adPresentationActive ? '#ffe7a9' : '#c6d1e6');
            importantStyle(player.chromeStatus.style, 'border-color', player.adPresentationActive ? 'rgba(241,183,51,.4)' : 'rgba(157,169,194,.24)');
        }
        if (player.overlayControls) {
            importantStyle(player.overlayControls.style, 'display', contentControls ? 'flex' : 'none');
            controlIcon(player.overlayPlay, player.video.paused ? 'play' : 'pause', player.video.paused ? 'Play video content' : 'Pause video content');
            controlIcon(player.overlayMute, player.video.muted ? 'muted' : 'sound', player.video.muted ? 'Unmute video content' : 'Mute video content');
        }
        if (player.contentMode) player.video.controls = !active;
    }

    function installOverlayControls(player) {
        if (!player.chrome || player.overlayControls) return;
        var controls = document.createElement('div');
        controls.setAttribute('data-hm-video-content-controls', '1');
        controls.style.cssText = 'display:none;align-items:center;gap:2px;margin-left:auto;flex:none;'.replace(/;/g, ' !important;');
        function button(label, name) {
            var node = document.createElement('button');
            node.type = 'button';
            node.setAttribute('aria-label', label);
            node.setAttribute('data-hm-video-content-control', name);
            node.style.cssText = 'all:initial;box-sizing:border-box;display:block;width:44px;height:44px;min-height:44px;min-width:44px;padding:0;border:0;border-radius:10px;background:transparent;color:#f6f8ff;cursor:pointer;touch-action:manipulation;'.replace(/;/g, ' !important;');
            listenContent(player, node, 'focus', function () { importantStyle(node.style, 'outline', '2px solid #f1b733'); importantStyle(node.style, 'outline-offset', '-4px'); });
            listenContent(player, node, 'blur', function () { importantStyle(node.style, 'outline', 'none'); });
            listenContent(player, node, 'mouseenter', function () { importantStyle(node.style, 'background', 'rgba(157,169,194,.16)'); });
            listenContent(player, node, 'mouseleave', function () { importantStyle(node.style, 'background', 'transparent'); });
            controls.appendChild(node); return node;
        }
        player.overlayPlay = button('Pause video content', 'play');
        player.overlayMute = button('Unmute video content', 'mute');
        listenContent(player, player.overlayPlay, 'click', function (event) {
            if (event && event.stopPropagation) event.stopPropagation();
            if (player.adMediaActive || player.adBreakPending && !player.nonLinearAdActive || player.destroyed) return;
            if (!player.preRollRequested && player.startContentFromGesture) {
                if (event && event.isTrusted) player.startContentFromGesture();
                return;
            }
            if (player.video.paused) { player.contentPausedByUser = false; resumeContent(player); }
            else { player.contentPausedByUser = true; player.contentPlayGeneration += 1; player.video.pause(); }
            updateContentAdUi(player);
        });
        listenContent(player, player.overlayMute, 'click', function (event) {
            if (event && event.stopPropagation) event.stopPropagation();
            if (player.adMediaActive || player.destroyed) return;
            player.video.muted = !player.video.muted;
            syncManagerAudio(player, true);
            updateContentAdUi(player);
        });
        player.chrome.appendChild(controls); player.overlayControls = controls;
        updateContentAdUi(player);
    }

    function currentAdFromEvent(event) {
        try { return event && typeof event.getAd === 'function' ? event.getAd() : null; } catch (error) { return null; }
    }

    function nonLinearFits(player) {
        if (!player.nonLinearAdActive || !player.currentAd) return true;
        var ad = player.currentAd, size = nonLinearDimensions(player, playerDimensions(player.container, player.size));
        var width = typeof ad.getWidth === 'function' ? Number(ad.getWidth()) : 0;
        var height = typeof ad.getHeight === 'function' ? Number(ad.getHeight()) : 0;
        return !(width > size[0] || height > size[1]);
    }

    function retireNonLinearAd(player, reason) {
        if (!player || player.destroyed || !player.nonLinearAdActive || player.adRules) return;
        var position = player.currentBreak;
        var manager = player.adsManager;
        // Retire callbacks before stop(), which may synchronously emit terminal
        // events. Destroy below removes SDK assets/tracking, not just their CSS.
        player.adRuntimeGeneration += 1;
        try { if (manager && manager.stop) manager.stop(); } catch (error) {}
        if (reason) player.container.setAttribute('data-hm-video-nonlinear-end', reason);
        finishContentAdBreak(player, position);
    }

    function checkNonLinearFit(player) {
        if (!nonLinearFits(player)) retireNonLinearAd(player, 'resize');
        updateContentAdUi(player);
    }

    function setContentMediaOwnership(player, adActive) {
        player.adMediaActive = adActive;
        player.contentPlayGeneration += 1;
        // A transparent IMA layer must not intercept native content controls
        // between breaks or when the browser requires a playback gesture.
        updateContentAdUi(player);
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
        var audio = viewerAudioState(player);
        // Retire callbacks BEFORE SDK teardown, which can itself dispatch events
        // and reject an older content play() promise on a shared video element.
        player.adRuntimeGeneration += 1;
        player.audioSync = null;
        player.contentPlayGeneration += 1;
        player.pendingAdStart = null;
        player.repeatContentSample = null;
        window.clearTimeout(player.prerollRetryTimer);
        player.prerollRetryTimer = null;
        window.clearTimeout(player.startupTimer);
        if (player.resizeHandler && window.removeEventListener) {
            try { window.removeEventListener('resize', player.resizeHandler); } catch (error) {}
            player.resizeHandler = null;
        }
        player.audioSyncDepth += 1;
        try { if (player.adsManager && player.adsManager.destroy) player.adsManager.destroy(); } catch (error) {}
        // IMA requires contentComplete() before reusing the same ad tag for a
        // later legitimate manual break. Ad-rules/VMAP keep their AdsLoader
        // alive and therefore never enter this cleanup path between breaks.
        try { if (player.adsLoader && player.adsLoader.contentComplete) player.adsLoader.contentComplete(); } catch (error) {}
        try { if (player.adsLoader && player.adsLoader.destroy) player.adsLoader.destroy(); } catch (error) {}
        // Keep the gesture-unlocked IMA media element through legitimate content
        // breaks and confirmed no-fill retry. Final player teardown owns it.
        try { if (player.displayContainer && player.displayContainer !== player.gestureDisplayContainer && player.displayContainer.destroy) player.displayContainer.destroy(); } catch (error) {}
        player.audioSyncDepth -= 1;
        player.viewerAudioState = audio;
        writeMediaAudioState(player, audio);
        player.adsManager = null;
        player.adsLoader = null;
        player.displayContainer = null;
        player.adBreakPending = false;
        player.currentBreak = null;
        player.nonLinearAdActive = false;
        player.currentAd = null;
        player.rejectedNonLinearAd = null;
        setAdPresentation(player, false);
        setContentMediaOwnership(player, false);
    }

    function finishContentPlayer(player, reason) {
        if (!player || player.destroyed) return;
        function finish() {
            var surface = placementSurface(player);
            if (surface && surface.style) surface.style.display = 'none';
            destroyPlayer(player, reason || 'completed');
        }
        if (!dismissPlayerWithMotion(player, finish)) finish();
    }

    function markContentPlaying(player) {
        if (!player || player.destroyed || player.closing || player.contentFailed || player.adMediaActive || player.contentPausedByUser) return;
        if (player.startupPlaybackState === 'checking' || player.video.paused) return;
        tracePhase('Video content', player.container);
        player.contentStarted = true;
        player.contentBuffering = false;
        player.repeatContentSample = null;
        player.container.setAttribute('data-hm-video-detail', '');
        setStatus(player.container, 'content-playing');
    }

    function resumeContent(player) {
        if (!player || player.destroyed || player.closing || player.contentEnded || player.adMediaActive) return;
        if (player.contentFailed) {
            finishContentPlayer(player, 'content-error');
            return;
        }
        if (player.contentPausedByUser) return;
        var generation = ++player.contentPlayGeneration;
        function currentAttempt() {
            return !player.destroyed && !player.adMediaActive && !player.contentPausedByUser && generation === player.contentPlayGeneration;
        }
        function failed(error) {
            if (!currentAttempt()) return;
            var name = String(error && error.name || '');
            if (name === 'NotAllowedError' || name === 'AbortError') {
                // Policy denial or an interrupted play is not broken media.
                // Keep the native play control usable; never loop ad requests or
                // pretend playback/impressions happened before actual playback.
                // A failed content play must not leave a clickable overlay over
                // the browser's native gesture control. Retire it without retry.
                if (player.nonLinearAdActive) { retireNonLinearAd(player, 'content-activation'); return; }
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
        var completed = player.adBreakCompleted;
        cleanupContentAdRuntime(player);
        if (position === 'postroll' || player.contentEnded) {
            finishContentPlayer(player, 'completed');
            return;
        }
        resumeContent(player);
        if (completed && intervalContentSchedule(player) && repeatedMidrollInterval(player) && !player.adRules) {
            var generation = player.adRuntimeGeneration;
            // Continue only after the whole IMA response/pod has finished, never
            // on an individual COMPLETE or on an empty ALL_ADS_COMPLETED event.
            player.nextContentAdTimer = window.setTimeout(function () {
                player.nextContentAdTimer = null;
                if (player.destroyed || player.closing || generation !== player.adRuntimeGeneration
                    || player.contentEnded || player.contentFailed || player.contentPausedByUser || player.adBreakPending) return;
                if (player.viewport && player.viewport.update) player.viewport.update();
                if (document.visibilityState === 'hidden' || Number(player.visibleRatio || 0) < 0.5 || player.motion) return;
                requestContentAdBreak(player, player.contentIma, player.contentVastUrl, 'midroll');
            }, 0);
        }
    }

    function failContentAdBreak(player, error, stage, position) {
        if (!player || player.destroyed) return;
        recordVideoError(player, error, stage);
        if (!fixedAudibleInventory(player) && retryMutedAutoplayPreroll(player, error, position)) return;
        if (retryEmptyPreroll(player, error, position, stage)) return;
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

    function retryMutedAutoplayPreroll(player, error, position) {
        var code = error && typeof error.getErrorCode === 'function' ? Number(error.getErrorCode()) : 0;
        var intent = player.adRequestIntent, audio = mediaAudioState(player);
        // Silent content can pass play() while an audible IMA creative is still
        // blocked. Only HTML5 IMA's explicit 1205 authorizes this one fallback;
        // a fatal manager is retired, never restarted with new volume settings.
        if (code !== 1205 || position !== 'preroll' || player.prerollAttempts !== 1
            || !player.adsManager || player.adPlaybackStarted || player.contentStarted
            || player.destroyed || player.closing || player.contentPausedByUser
            || player.adRules || player.adRuleCuePoints.length || player.audioIntentChanged
            || !intent || !intent.autoPlay || intent.muted || audio.muted || audio.volume !== intent.volume) return false;
        if (!schedulePrerollRetry(player, 'autoplay-muted', code, function () {
            var current = mediaAudioState(player);
            return !player.audioIntentChanged && !player.adPlaybackStarted && !player.contentPausedByUser
                && current.muted && current.volume === audio.volume;
        })) return false;
        var mutedAudio = { muted: true, volume: audio.volume };
        writeMediaAudioState(player, mutedAudio);
        player.viewerAudioState = mutedAudio;
        updateContentAdUi(player);
        return true;
    }

    function schedulePrerollRetry(player, reason, code, stillEligible) {
        if (!player || player.destroyed || player.closing || player.prerollAttempts !== 1
            || player.prerollRetryTimer !== null || player.contentStarted || player.adPlaybackStarted || player.adRules || player.adRuleCuePoints.length) return false;
        // Each caller owns its error/audio eligibility. All recovery kinds share
        // one retry and the existing independent per-attempt phase watchdogs.
        cleanupContentAdRuntime(player);
        var generation = player.adRuntimeGeneration;
        player.adBreakPending = true;
        player.currentBreak = 'preroll';
        player.container.setAttribute('data-hm-video-preroll-retry', reason);
        setStatus(player.container, 'waiting-preroll-retry');
        tracePhase('VAST preroll retry', player.container, { code: code, attempt: 2, reason: reason, delayMs: 1000 });
        player.prerollRetryTimer = window.setTimeout(function () {
            player.prerollRetryTimer = null;
            if (player.destroyed || player.closing || generation !== player.adRuntimeGeneration) return;
            player.adBreakPending = false;
            player.currentBreak = null;
            if (player.contentStarted || player.contentEnded || player.adPlaybackStarted || player.adRules || player.adRuleCuePoints.length
                || typeof stillEligible === 'function' && !stillEligible()) {
                resumeContent(player);
                return;
            }
            if (player.viewport && player.viewport.update) player.viewport.update();
            if (document.visibilityState === 'hidden' || Number(player.visibleRatio || 0) < 0.5 || player.motion) {
                resumeContent(player);
                return;
            }
            requestContentAdBreak(player, player.contentIma, player.contentVastUrl, 'preroll');
        }, 1000);
        return true;
    }

    function retryEmptyPreroll(player, error, position, stage) {
        var code = error && typeof error.getErrorCode === 'function' ? Number(error.getErrorCode()) : 0;
        var empty = code === 303 || code === 1009;
        var transient = code === 301 || code === 1012;
        // A loader-stage timeout/network error can be transient, but is not
        // no-fill. Never reinterpret playback/configuration errors using a
        // nested VAST code, retry a live response, or restart an ad-rules pod.
        if (position !== 'preroll' || stage !== 'request-preroll' || player.adsManager
            || player.adResponseSeen || (!empty && !transient)) return false;
        return schedulePrerollRetry(player, empty ? 'no-fill' : 'transient', code);
    }

    function intervalContentSchedule(player) {
        return player.container.getAttribute('data-hm-video-break-schedule') === 'interval';
    }

    function repeatedMidrollInterval(player) {
        var raw = player.container.getAttribute('data-hm-video-mid-roll-interval-seconds');
        if (raw === null || raw === '') return 60;
        var seconds = Number(raw);
        return Number.isInteger(seconds) && (seconds === 0 || seconds >= (intervalContentSchedule(player) ? 5 : 30) && seconds <= 600) ? seconds : 60;
    }

    function contentProgressNow() {
        return window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now();
    }

    function requestRepeatedContentBreak(player, ima, vastUrl) {
        var interval = repeatedMidrollInterval(player), video = player.video;
        var duration = Number(video.duration), current = Number(video.currentTime);
        var playbackRate = video.playbackRate === undefined ? 1 : Number(video.playbackRate);
        if (!interval || player.destroyed || player.closing || player.adRules || !player.contentStarted
            || player.contentFailed || player.contentEnded || player.adBreakPending || player.adsManager
            || player.adMediaActive || player.nonLinearAdActive || player.contentPausedByUser
            || (fixedAudibleInventory(player) && adPlaybackIntent(player).muted)
            || player.contentBuffering || video.paused || video.seeking || !Number.isFinite(playbackRate) || playbackRate <= 0 || document.visibilityState === 'hidden'
            || Number(player.visibleRatio || 0) < 0.5 || (!intervalContentSchedule(player) && !player.midRollRequested && !player.midRollConsumedByOverlay)
            || !Number.isFinite(duration) || !Number.isFinite(current) || duration <= 0 || current < 0
            // Horus UX choice for additional breaks: retain a short content tail
            // rather than placing another midroll directly before the postroll.
            || duration - current <= (intervalContentSchedule(player) ? 1 : 15)) {
            player.repeatContentSample = null;
            return;
        }
        var now = contentProgressNow(), previous = player.repeatContentSample;
        player.repeatContentSample = { time: current, at: now };
        if (!previous) return;
        var elapsed = Math.max(0, (now - previous.at) / 1000), progress = current - previous.time;
        var rate = Math.max(1, playbackRate);
        // Time passing alone earns nothing. Seek jumps cannot count as watched
        // content, and faster playback cannot compress the wall-time spacing.
        if (elapsed <= 0 || progress <= 0 || progress > elapsed * rate + 0.5) return;
        player.repeatMidrollElapsed += Math.min(progress, elapsed);
        if (player.repeatMidrollElapsed + 0.000001 < interval) return;
        player.repeatMidrollElapsed = 0;
        player.repeatContentSample = null;
        // The next observer frame can lag a scroll/removal. Re-measure before
        // this additional request; never queue it if eligibility was lost.
        if (player.viewport && player.viewport.update) player.viewport.update();
        if (player.destroyed || player.closing || player.motion || document.visibilityState === 'hidden'
            || Number(player.visibleRatio || 0) < 0.5) return;
        requestContentAdBreak(player, ima, vastUrl, 'midroll');
    }

    function requestContentAdBreak(player, ima, vastUrl, position) {
        if (!player || player.destroyed || player.closing || player.adBreakPending) return;
        if (player.adRules && position !== 'preroll') return;
        // Fixed sound-on declarations require sound-on playback. Respect viewer
        // mute without rewriting the tag or forcing audio back on.
        if (fixedAudibleInventory(player) && adPlaybackIntent(player).muted) {
            if (position === 'postroll') finishContentPlayer(player, 'completed');
            else resumeContent(player);
            return;
        }
        window.clearTimeout(player.nextContentAdTimer);
        player.nextContentAdTimer = null;
        player.adBreakCompleted = false;
        player.adBreakPending = true;
        player.currentBreak = position;
        player.repeatContentSample = null;
        if (position === 'midroll') player.repeatMidrollElapsed = 0;
        player.contentPlayGeneration += 1;
        if (player.contentStarted && player.video && player.video.pause) {
            try { player.video.pause(); } catch (error) {}
        }
        cleanupContentAdRuntime(player);
        player.adBreakPending = true;
        player.currentBreak = position;
        player.adResponseSeen = false;
        if (position === 'preroll') {
            player.prerollAttempts += 1;
            player.container.setAttribute('data-hm-video-preroll-attempts', String(player.prerollAttempts));
        }
        player.adRequestIntent = adPlaybackIntent(player);
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
            var gestureDisplay = player.gestureDisplayContainer;
            player.displayContainer = gestureDisplay || new ima.AdDisplayContainer(player.adLayer, player.video);
            if (!gestureDisplay) player.displayContainer.initialize();
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
                    function applyAdMode(adEvent, takeLinearOwnership) {
                        if (!currentRequest() || player.rejectingNonLinearBreak) return false;
                        var ad = currentAdFromEvent(adEvent);
                        if (!ad || typeof ad.isLinear !== 'function') return;
                        if (player.rejectedNonLinearAd === ad) return false;
                        player.adResponseSeen = true;
                        player.currentAd = ad;
                        player.nonLinearAdActive = !ad.isLinear();
                        player.container.setAttribute('data-hm-video-ad-linearity', player.nonLinearAdActive ? 'nonlinear' : 'linear');
                        // Linearity describes playback mode, not proof of a video
                        // asset: IMA can render image/text demand as full-slot.
                        try { if (typeof ad.getContentType === 'function') player.container.setAttribute('data-hm-video-ad-content-type', String(ad.getContentType() || '')); } catch (error) {}
                        if (player.nonLinearAdActive && player.adRules) {
                            // IMA HTML5 does not support VMAP overlays. Discard
                            // only this unsupported break, preserving its schedule.
                            window.clearTimeout(player.startupTimer);
                            player.nonLinearAdActive = false;
                            player.rejectedNonLinearAd = ad;
                            player.adBreakPending = false; player.currentBreak = null;
                            setContentMediaOwnership(player, false);
                            player.rejectingNonLinearBreak = true;
                            try { if (player.adsManager.discardAdBreak) player.adsManager.discardAdBreak(); } finally { player.rejectingNonLinearBreak = false; }
                            resumeContent(player);
                            return false;
                        }
                        if (player.nonLinearAdActive) {
                            // Nonlinear readiness has no video-media startup phase.
                            window.clearTimeout(player.startupTimer);
                            if (!mixedContentAds(player) || player.contentEnded || position === 'postroll' || !nonLinearFits(player)) {
                                retireNonLinearAd(player, player.contentEnded || position === 'postroll' ? 'content-ended' : 'unsupported');
                                return false;
                            }
                            setContentMediaOwnership(player, false);
                            resumeContent(player);
                        } else if (takeLinearOwnership !== false) {
                            setContentMediaOwnership(player, true);
                            try { player.video.pause(); } catch (error) {}
                        }
                    }
                    player.adsManager.addEventListener(adTypes.LOADED, function (adEvent) {
                        if (!currentRequest()) return;
                        // Preloaded VMAP/pod ads can LOADED well before their
                        // cue. They must not pause content or replace an active ad.
                        if (player.currentAd && (player.adMediaActive || player.nonLinearAdActive)) return;
                        if (player.adRules) {
                            // A confirmed preroll may arrive after the reader
                            // has scrolled away, before manager.start(). Only
                            // its zero-offset pod can make startup viewable;
                            // preloaded future cues must remain inline.
                            if (player.contentStarted || player.currentBreak !== 'preroll') return;
                            var loadedAd = currentAdFromEvent(adEvent), loadedPod;
                            try { loadedPod = loadedAd && loadedAd.getAdPodInfo && loadedAd.getAdPodInfo(); } catch (error) { return; }
                            try { if (!loadedPod || !loadedPod.getTimeOffset || loadedPod.getTimeOffset() !== 0) return; } catch (error) { return; }
                        }
                        setStatus(player.container, 'loaded-' + position);
                        if (currentAdFromEvent(adEvent) && applyAdMode(adEvent, false) !== false && currentRequest()) setAdPresentation(player, true);
                    });
                    if (adTypes.LINEAR_CHANGED) player.adsManager.addEventListener(adTypes.LINEAR_CHANGED, function (adEvent) {
                        if (player.adRules && !player.adBreakPending && !player.adMediaActive && !player.nonLinearAdActive) return;
                        applyAdMode(adEvent);
                    });
                    player.adsManager.addEventListener(adTypes.STARTED, function (adEvent) {
                        if (currentRequest()) player.adPlaybackStarted = true;
                        if (currentRequest() && player.audioSync) player.audioSync.restoring = false;
                        if (applyAdMode(adEvent) === false) return;
                        if (!currentRequest()) return;
                        window.clearTimeout(player.startupTimer);
                        tracePhase('Ad start', player.container, { position: player.currentBreak, linearity: player.nonLinearAdActive ? 'nonlinear' : 'linear', contentType: player.container.getAttribute('data-hm-video-ad-content-type') || '' });
                        player.adResponseSeen = true;
                        setAdPresentation(player, true);
                        setStatus(player.container, 'started');
                    });
                    function nonLinearEnded() {
                        if (currentRequest() && player.nonLinearAdActive) retireNonLinearAd(player, 'completed');
                    }
                    if (adTypes.USER_CLOSE) player.adsManager.addEventListener(adTypes.USER_CLOSE, nonLinearEnded);
                    function adEnded() {
                        if (!currentRequest()) return;
                        if (player.audioSync && !player.nonLinearAdActive) {
                            // Capture an active-playback choice even when its
                            // native volumechange event is still queued.
                            viewerAudioState(player);
                            player.audioSync.restoring = true;
                        }
                        if (player.nonLinearAdActive) nonLinearEnded();
                        else setAdPresentation(player, false);
                    }
                    if (adTypes.COMPLETE) player.adsManager.addEventListener(adTypes.COMPLETE, function () {
                        if (currentRequest()) player.adBreakCompleted = true;
                        adEnded();
                    });
                    if (adTypes.SKIPPED) player.adsManager.addEventListener(adTypes.SKIPPED, adEnded);
                    if (adTypes.CONTENT_PAUSE_REQUESTED) player.adsManager.addEventListener(adTypes.CONTENT_PAUSE_REQUESTED, function (adEvent) {
                        if (!currentRequest()) return;
                        if (player.audioSync && !player.adMediaActive) player.audioSync.restoreBaseline = viewerAudioState(player);
                        if (applyAdMode(adEvent) === false || !currentRequest() || player.nonLinearAdActive) return;
                        if (player.adRules) player.adBreakPending = true;
                        // IMA may temporarily own this exact video element. Its
                        // media errors and ended events are not content failures.
                        setContentMediaOwnership(player, true);
                        // Break-level pause events may have no ad yet. Ownership
                        // alone must not pin an empty request; STARTED/LOADED will
                        // make the actual ad visible when it becomes available.
                        if (currentAdFromEvent(adEvent)) setAdPresentation(player, true);
                        try { if (player.video && player.video.pause) player.video.pause(); } catch (error) {}
                    });
                    if (adTypes.CONTENT_RESUME_REQUESTED) player.adsManager.addEventListener(adTypes.CONTENT_RESUME_REQUESTED, function () {
                        if (!currentRequest() || player.contentEnded) return;
                        finishAudioRestoration(player);
                        // A VMAP may legitimately start with content and a
                        // later cue point. It has finished startup without an ad.
                        window.clearTimeout(player.startupTimer);
                        if (player.adRules && !player.nonLinearAdActive) {
                            player.adBreakPending = false;
                            player.currentBreak = null;
                        }
                        // Content resumption does not mean an overlay finished.
                        if (!player.nonLinearAdActive) setAdPresentation(player, false);
                        setContentMediaOwnership(player, false);
                        resumeContent(player);
                    });
                    if (adTypes.ALL_ADS_COMPLETED) player.adsManager.addEventListener(adTypes.ALL_ADS_COMPLETED, function () {
                        if (!currentRequest()) return;
                        finishAudioRestoration(player);
                        setAdPresentation(player, false);
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
                    if (!initializeAdManager(player, ima, currentRequest)) return;
                    presentUnscheduledAdResponse(player, vastUrl);
                    startAdManagerWhenViewable(player, function () {
                        if (currentRequest() && player.adsManager) {
                            syncManagerAudio(player);
                            if (!currentRequest() || !player.adsManager || player.closing) return;
                            if (player.audioSync) player.audioSync.restoreBaseline = mediaAudioState(player);
                            if (!player.nonLinearAdActive) armStartupPhase('media-start');
                            player.adsManager.start();
                        }
                    });
                    if (!currentRequest() || !player.adsManager) return;
                    player.resizeHandler = function () {
                        if (!currentRequest() || !player.adsManager) return;
                        var resized = managerDimensions(player);
                        player.adsManager.resize(resized[0], resized[1], ima.ViewMode.NORMAL);
                        checkNonLinearFit(player);
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
            var nonLinearSize = nonLinearDimensions(player, dimensions);
            player.nonLinearRequestSize = nonLinearSize;
            request.nonLinearAdSlotWidth = nonLinearSize[0];
            request.nonLinearAdSlotHeight = nonLinearSize[1];
            var contentDuration = Number(player.video && player.video.duration || 0);
            if (Number.isFinite(contentDuration) && contentDuration > 0) request.contentDuration = contentDuration;
            if (request.setAdWillAutoPlay) request.setAdWillAutoPlay(player.adRequestIntent.autoPlay);
            if (request.setAdWillPlayMuted) request.setAdWillPlayMuted(player.adRequestIntent.muted);
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

    function checkContentAutoplay(player, ready, fromGesture) {
        if (!player || player.destroyed || player.closing || player.preRollRequested || player.startupPlaybackState === 'checking') return;
        var generation = ++player.startupPlaybackGeneration;
        player.startupPlaybackState = 'checking';
        player.contentGesturePending = false;
        player.contentAutoPlay = !fromGesture;
        function current() { return !player.destroyed && !player.closing && !player.preRollRequested && generation === player.startupPlaybackGeneration; }
        function waitForPlay(detail) {
            if (!current()) return;
            window.clearTimeout(player.startupPlaybackTimer);
            player.startupPlaybackGeneration += 1;
            player.startupPlaybackState = 'gesture';
            player.contentAutoPlay = false;
            try { player.video.pause(); } catch (error) {}
            player.video.controls = true;
            setStatus(player.container, 'content-ready', detail || 'user-activation-required');
            updateContentAdUi(player);
        }
        // A stalled media promise is not proof that audible autoplay works.
        // Leave the real Play control available rather than inventing a signal.
        player.startupPlaybackTimer = window.setTimeout(function () { waitForPlay('playback-check-timeout'); }, 5000);
        function attempt(muted, allowMutedFallback) {
            if (!current()) return;
            player.video.muted = muted;
            function rejected(error) {
                if (!current()) return;
                if (allowMutedFallback && String(error && error.name || '') === 'NotAllowedError') { attempt(true, false); return; }
                waitForPlay(String(error && error.name || '') === 'NotAllowedError' ? 'user-activation-required' : 'playback-check-failed');
            }
            try {
                var result = player.video.play();
                if (!result || typeof result.then !== 'function') { waitForPlay('playback-check-unavailable'); return; }
                result.then(function () {
                    if (!current()) return;
                    window.clearTimeout(player.startupPlaybackTimer);
                    player.startupPlaybackState = 'ready';
                    player.video.pause();
                    updateContentAdUi(player);
                    ready();
                }).catch(rejected);
            } catch (error) { rejected(error); }
        }
        // This branch is selected by the publisher's explicit sound preference,
        // never inferred from a VAST parameter or an inventory declaration.
        attempt(player.video.muted, !fromGesture && !fixedAudibleInventory(player));
    }

    function initializeContentGesture(player, ima) {
        if (!player || player.destroyed || player.closing || player.preRollRequested) return false;
        if (player.gestureDisplayContainer) return true;
        try {
            // IMA requires this exact initialization inside the trusted user
            // event, before any play promise. Reuse it for the first request.
            player.gestureDisplayContainer = new ima.AdDisplayContainer(player.adLayer, player.video);
            player.gestureDisplayContainer.initialize();
            return true;
        } catch (error) {
            try { if (player.gestureDisplayContainer && player.gestureDisplayContainer.destroy) player.gestureDisplayContainer.destroy(); } catch (ignored) {}
            player.gestureDisplayContainer = null;
            setStatus(player.container, 'content-ready', 'player-activation-failed');
            return false;
        }
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
        player.contentIma = ima;
        player.contentVastUrl = vastUrl;
        try { player.adRules = adRulesTag(new URL(vastUrl)); } catch (error) {}
        player.video.autoplay = false;
        player.video.controls = true;
        player.video.preload = 'metadata';
        if (player.video.muted) player.video.setAttribute('muted', '');
        player.video.setAttribute('controls', '');
        player.video.setAttribute('preload', 'metadata');
        player.video.setAttribute('aria-label', player.contentInventoryType === 'instream' ? 'Video content' : 'Accompanying video content');
        var surface = placementSurface(player);
        if (surface && surface.style && !player.floating && !(player.viewport && player.viewport.portal)) surface.style.position = 'relative';

        installOverlayControls(player);
        ['pointerdown', 'pointerup', 'keydown', 'touchend'].forEach(function (name) {
            listenContent(player, player.video, name, function (event) {
                if (name === 'pointerdown' && event && event.pointerType !== 'mouse') return;
                if (name === 'pointerup' && event && event.pointerType === 'mouse') return;
                if (name === 'keydown' && event && (event.key === 'Escape' || event.ctrlKey || event.altKey || event.metaKey)) return;
                if (event && event.isTrusted && player.startupPlaybackState === 'gesture') player.contentGesturePending = initializeContentGesture(player, ima);
            });
        });
        listenContent(player, player.video, 'play', function () { if (!player.adMediaActive) player.contentPausedByUser = false; });
        listenContent(player, player.video, 'playing', function () {
            if (player.startupPlaybackState === 'gesture' && !player.preRollRequested) {
                if (!player.contentGesturePending) { player.video.pause(); return; }
                player.contentGesturePending = false;
                player.startupPlaybackState = 'ready';
                player.contentAutoPlay = false;
                player.video.pause();
                beginPreroll();
                return;
            }
            markContentPlaying(player); updateContentAdUi(player);
        });
        listenContent(player, player.video, 'pause', function () { player.repeatContentSample = null; updateContentAdUi(player); });
        ['waiting', 'stalled'].forEach(function (name) {
            listenContent(player, player.video, name, function () {
                if (!player.adMediaActive) player.contentBuffering = true;
                player.repeatContentSample = null;
            });
        });
        ['seeking', 'seeked', 'ratechange'].forEach(function (name) {
            listenContent(player, player.video, name, function () { player.repeatContentSample = null; });
        });
        listenContent(player, document, 'visibilitychange', function () { player.repeatContentSample = null; });
        listenContent(player, player.video, 'error', function () {
            if (player.destroyed || player.adMediaActive) return;
            player.contentFailed = true;
            player.container.setAttribute('data-hm-video-content-error', player.contentStarted ? 'playback' : 'load');
            if (player.nonLinearAdActive && !player.adRules) { retireNonLinearAd(player, 'content-error'); return; }
            // Before preroll, keep the surface alive so the independent VAST
            // request can still run when the placement becomes viewable. After
            // an ad break has completed, a later content failure must not leave
            // a dead player pinned on the publisher page.
            if (player.preRollRequested && !player.adBreakPending) {
                finishContentPlayer(player, 'content-error');
            }
        });
        listenContent(player, player.video, 'timeupdate', function () {
            requestRepeatedContentBreak(player, ima, vastUrl);
            if (intervalContentSchedule(player) && repeatedMidrollInterval(player)) return;
            if (player.destroyed || player.adRules || player.midRollRequested || player.midRollConsumedByOverlay || !player.contentStarted || player.contentEnded) return;
            var duration = Number(player.video.duration || 0);
            var current = Number(player.video.currentTime || 0);
            var ratio = Number(player.container.getAttribute('data-hm-video-mid-roll-ratio') || 0.5);
            ratio = Number.isFinite(ratio) ? Math.max(0.1, Math.min(0.9, ratio)) : 0.5;
            if (duration > 1 && current / duration >= ratio) {
                if (player.adBreakPending) {
                    if (player.nonLinearAdActive) {
                        player.midRollConsumedByOverlay = true;
                        player.container.setAttribute('data-hm-video-midroll-skipped', 'overlay-active');
                    }
                    return;
                }
                player.midRollRequested = true;
                requestContentAdBreak(player, ima, vastUrl, 'midroll');
            }
        });
        player.contentEndedHandler = function () {
            if (player.destroyed || player.contentEnded || player.adBreakPending && !player.nonLinearAdActive) return;
            // Content can end beneath a genuine overlay. Do not lose EOS, queue
            // a missed midroll, or leave a five-second clip pinned indefinitely.
            if (player.nonLinearAdActive && !player.adRules) {
                var manager = player.adsManager;
                player.adRuntimeGeneration += 1;
                try { if (manager && manager.stop) manager.stop(); } catch (error) {}
                cleanupContentAdRuntime(player);
            }
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
        player.container.setAttribute('data-hm-video-content-mode', player.contentInventoryType);
        setStatus(player.container, 'content-ready');

        function beginPreroll() {
            if (player.destroyed || player.preRollRequested) return;
            player.preRollRequested = true;
            requestContentAdBreak(player, ima, vastUrl, 'preroll');
        }

        player.startContentFromGesture = function () {
            if (player.startupPlaybackState === 'checking') {
                player.startupPlaybackGeneration += 1;
                window.clearTimeout(player.startupPlaybackTimer);
                player.startupPlaybackState = 'gesture';
                player.video.pause();
                updateContentAdUi(player);
                return;
            }
            if (initializeContentGesture(player, ima)) checkContentAutoplay(player, beginPreroll, true);
        };
        player.viewport.ready(function () {
            if (!player.contentAutoPlay) {
                player.startupPlaybackState = 'gesture';
                setStatus(player.container, 'content-ready', 'user-activation-required');
                updateContentAdUi(player);
            } else if (fixedAudibleInventory(player)) {
                // Fixed sound-on autoplay requests start immediately through IMA;
                // do not put an extra content-play probe or click gate in front.
                beginPreroll();
            } else if (!player.video.muted) {
                startAdManagerWhenViewable(player, function () { checkContentAutoplay(player, beginPreroll, false); });
            } else beginPreroll();
        });
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
        player.adRequestIntent = adPlaybackIntent(player);
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
                player.adsManager.addEventListener(adTypes.LOADED, function () {
                    if (player.destroyed) return;
                    // Presentation can synchronously make a deferred manager
                    // viewable and STARTED. Never overwrite that newer state.
                    setStatus(player.container, 'loaded');
                    setAdPresentation(player, true);
                });
                player.adsManager.addEventListener(adTypes.STARTED, function () {
                    if (player.destroyed) return;
                    window.clearTimeout(player.startupTimer);
                    setAdPresentation(player, true);
                    tracePhase('Video start', player.container, { position: player.currentBreak });
                    setStatus(player.container, 'started');
                    // Keep viewport observation alive for the ad-only fallback.
                    // A loaded ad still has to transition when the reader scrolls.
                });
                [adTypes.COMPLETE, adTypes.SKIPPED, adTypes.CONTENT_RESUME_REQUESTED].forEach(function (name) {
                    if (name) player.adsManager.addEventListener(name, function () { setAdPresentation(player, false); });
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
                        finishContentPlayer(player, 'completed');
                    }
                });
                if (!initializeAdManager(player, ima)) return;
                presentUnscheduledAdResponse(player, vastUrl);
                startAdManagerWhenViewable(player, function () {
                    if (!player.destroyed && player.adsManager) {
                        syncManagerAudio(player);
                        if (player.destroyed || player.closing || !player.adsManager) return;
                        armStartupPhase('media-start');
                        player.adsManager.start();
                    }
                });
                player.resizeHandler = function () {
                    if (!player.adsManager || player.destroyed) return;
                    var resized = managerDimensions(player);
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
            if (request.setAdWillAutoPlay) request.setAdWillAutoPlay(player.adRequestIntent.autoPlay);
            if (request.setAdWillPlayMuted) request.setAdWillPlayMuted(player.adRequestIntent.muted);
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
        if (player.rewarded) destroyPlayer(player, 'error');
        else finishContentPlayer(player, 'error');
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
        var intent = player.adRequestIntent || adPlaybackIntent(player);
        tag.searchParams.set('vpmute', fixedAudibleInventory(player) ? '0' : (intent.muted ? '1' : '0'));
        if (fixedAudibleInventory(player)) tag.searchParams.set('plcmt', '1');
        tag.searchParams.set('vpa', intent.autoPlay ? 'auto' : 'click');
        // GAM sz is inventory targeting, not the IMA rendering surface. Keep
        // explicit single/multi-size targeting stable while responsive inline
        // media grows. AdsRequest separately reports the actual measured area.
        // Generated tags already target the selected master; incomplete tags
        // use that same contract rather than inventing article-width inventory.
        var targetingSize = tag.searchParams.get('sz') || '';
        var validTargetingSize = /^[1-9]\d*x[1-9]\d*(?:\|[1-9]\d*x[1-9]\d*)*$/.test(targetingSize)
            && targetingSize.split(/[x|]/).every(function (part) { return Number.isSafeInteger(Number(part)); });
        if (!validTargetingSize) tag.searchParams.set('sz', player.size[0] + 'x' + player.size[1]);
        // Full manual tags retain their explicit format restriction. Generated
        // mixed tags omit vad_type during publication, using saved provenance.
        if (!mixedContentAds(player) && (!player.contentMode || !tag.searchParams.get('vad_type'))) tag.searchParams.set('vad_type', 'linear');
        if (!player.rewarded && accompanyingAvailable) {
            tag.searchParams.set('plcmt', fixedAudibleInventory(player) || player.contentInventoryType === 'instream' ? '1' : '2');
            if (breakPosition) {
                var adRulesRequest = adRulesTag(tag);
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
                if (!fixedAudibleInventory(player)) tag.searchParams.delete('plcmt');
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
