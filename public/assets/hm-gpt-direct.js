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

    var STATE_KEY = '__HORUS_GPT_DIRECT_RUNTIME_V5__';
    if (window[STATE_KEY]) {
        if (typeof window[STATE_KEY].scan === 'function') window[STATE_KEY].scan();
        return;
    }

    var state = window[STATE_KEY] = { observer: null, scan: scan, libraryInjected: false };
    var SELECTOR = '[data-hm-gpt-direct="1"]';
    var GPT_URL = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
    var RUNTIME_VERSION = '5';
    var MIN_RENDER_RATIO = 0.4;
    var MAX_RENDER_RATIO = 2;

    function validPath(value) {
        var path = String(value || '');
        return path.length <= 280 && /^\/[0-9]{1,20}(?:,[0-9]{1,20})?\/[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*$/.test(path);
    }

    function normalizedSize(size) {
        if (!Array.isArray(size) || size.length !== 2) return null;
        var width = Number(size[0]);
        var height = Number(size[1]);
        if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || width > 10000 || height < 1 || height > 10000) return null;
        return [width, height];
    }

    function normalizedSlotSize(size) {
        if (typeof size === 'string' && size.toLowerCase() === 'fluid') return 'fluid';
        if (Array.isArray(size) && size.length === 1 && String(size[0] || '').toLowerCase() === 'fluid') return 'fluid';
        return normalizedSize(size);
    }

    function sizes(value) {
        try {
            var decoded = JSON.parse(String(value || ''));
            if (!Array.isArray(decoded) || !decoded.length || decoded.length > 20) return null;
            var normalized = decoded.map(normalizedSlotSize);
            return normalized.every(Boolean) ? normalized : null;
        } catch (error) {
            return null;
        }
    }

    function sizeKey(size) {
        return size === 'fluid' ? 'fluid' : size[0] + 'x' + size[1];
    }

    function sizeMappings(value) {
        if (!value) return [];
        try {
            var decoded = JSON.parse(String(value));
            if (!Array.isArray(decoded) || decoded.length > 100) return [];
            return decoded.map(function (mapping) {
                mapping = mapping || {};
                var viewport = Array.isArray(mapping.viewport) ? mapping.viewport : [0, 0];
                var maximum = Array.isArray(mapping.maxViewport) ? mapping.maxViewport : [0, 0];
                var mappedSizes = Array.isArray(mapping.sizes) ? mapping.sizes.map(normalizedSlotSize).filter(Boolean) : [];
                return {
                    minWidth: Math.max(0, Number(viewport[0] || 0)),
                    minHeight: Math.max(0, Number(viewport[1] || 0)),
                    maxWidth: Math.max(0, Number(maximum[0] || 0)),
                    maxHeight: Math.max(0, Number(maximum[1] || 0)),
                    sizes: mappedSizes,
                };
            }).filter(function (mapping) { return mapping.sizes.length > 0; });
        } catch (error) {
            return [];
        }
    }

    function viewportSize() {
        var root = document.documentElement || {};
        return [
            Number(window.innerWidth || root.clientWidth || 0),
            Number(window.innerHeight || root.clientHeight || 0),
        ];
    }

    function eligibleSizes(container, allowedSizes) {
        var mappings = sizeMappings(container.getAttribute('data-hm-gpt-size-map'));
        if (!mappings.length) return fitContainerSizes(container, allowedSizes);

        var viewport = viewportSize();
        var width = viewport[0];
        var height = viewport[1];
        if (!width && !height) return fitContainerSizes(container, allowedSizes);

        var allowed = {};
        allowedSizes.forEach(function (size) { allowed[sizeKey(size)] = true; });
        var matches = mappings.filter(function (mapping) {
            if (width && width < mapping.minWidth) return false;
            if (height && height < mapping.minHeight) return false;
            if (mapping.maxWidth && width && width > mapping.maxWidth) return false;
            if (mapping.maxHeight && height && height > mapping.maxHeight) return false;
            return true;
        }).sort(function (left, right) {
            if (right.minWidth !== left.minWidth) return right.minWidth - left.minWidth;
            return right.minHeight - left.minHeight;
        });

        if (!matches.length) return [];

        for (var index = 0; index < matches.length; index += 1) {
            var selected = matches[index].sizes.filter(function (size) { return allowed[sizeKey(size)]; });
            if (selected.length) return fitContainerSizes(container, selected);
        }

        return [];
    }

    function fitContainerSizes(container, selected) {
        // Manual Responsive Display owns this explicit constraint. Existing
        // sticky, in-article, rewarded and other surface contracts stay intact.
        if (container.getAttribute('data-hm-gpt-fit-container') !== '1') return selected.slice();
        var root = container.closest ? container.closest('.hm-ad[data-placement], .hm-native[data-placement]') : null;
        root = root || container.parentElement;
        var available = viewportSize()[0];
        if (root && typeof root.clientWidth === 'number') {
            var contentWidth = root.clientWidth;
            if (typeof window.getComputedStyle === 'function') {
                var css = window.getComputedStyle(root);
                contentWidth -= (parseFloat(css.paddingLeft) || 0) + (parseFloat(css.paddingRight) || 0);
            }
            // A zero-width/hidden placement must not request a paid creative.
            available = available > 0 ? Math.min(available, contentWidth) : contentWidth;
        }
        if (!(available > 0)) return [];
        return selected.filter(function (size) { return size === 'fluid' || size[0] <= available; });
    }

    function validId(value) {
        return /^[A-Za-z][A-Za-z0-9_-]{0,127}$/.test(String(value || ''));
    }

    function normalizedRenderedSize(size, allowedSizes, flexible) {
        // Only manual Responsive Display opts into actual-creative sizing.
        // GPT's returned dimensions may differ from every requested size pair;
        // the surface's available width is checked separately before rendering.
        if (flexible && (!Array.isArray(size) || typeof size[0] !== 'number' || typeof size[1] !== 'number')) return null;
        var normalized = normalizedSize(size);
        if (!normalized || flexible) return normalized;

        for (var exactIndex = 0; exactIndex < allowedSizes.length; exactIndex += 1) {
            var exact = allowedSizes[exactIndex];
            if (exact === 'fluid') continue;
            if (exact[0] === normalized[0] && exact[1] === normalized[1]) return normalized;
        }

        // Google Ad Manager can legitimately return a rendered creative whose
        // pixel dimensions differ from the requested slot because of ad-slot
        // expansion/contraction or creatives configured to differ from the ad
        // unit size. Treat that trusted GPT result as valid only when it remains
        // reasonably close to at least one reviewed/requested fixed size.
        // Fluid is intentionally unbounded in height and is handled separately.
        for (var index = 0; index < allowedSizes.length; index += 1) {
            var requested = allowedSizes[index];
            if (requested === 'fluid') continue;
            var widthRatio = normalized[0] / requested[0];
            var heightRatio = normalized[1] / requested[1];
            if (widthRatio >= MIN_RENDER_RATIO && widthRatio <= MAX_RENDER_RATIO
                && heightRatio >= MIN_RENDER_RATIO && heightRatio <= MAX_RENDER_RATIO) {
                return normalized;
            }
        }

        return null;
    }

    function report(container, status, size) {
        if (size) {
            var width = String(size[0]);
            var height = String(size[1]);
            container.setAttribute('data-hm-gpt-rendered-width', width);
            container.setAttribute('data-hm-gpt-rendered-height', height);
            if (container.style) {
                container.style.width = width + 'px';
                container.style.height = height + 'px';
                container.style.maxWidth = '100%';
            }
        }
        container.setAttribute('data-hm-gpt-runtime-state', status);
        container.setAttribute('data-hm-gpt-status', status);
    }

    function destroySlot(slot) {
        try {
            if (window.googletag && typeof window.googletag.destroySlots === 'function') window.googletag.destroySlots([slot]);
        } catch (error) {
            // Cleanup failure must never promote a failed/empty slot to success.
        }
    }

    function startSlot(container, adUnitPath, allowedSizes) {
        if (!container || container.getAttribute('data-hm-gpt-runtime-version') !== RUNTIME_VERSION) return;
        var currentState = String(container.getAttribute('data-hm-gpt-runtime-state') || '');
        if (currentState === 'failed' || currentState === 'empty' || currentState === 'rendered' || currentState === 'requested') return;

        // GPT may load after a layout change. Recheck before the actual request
        // without refreshing or redefining an already requested placement.
        if (container.getAttribute('data-hm-gpt-fit-container') === '1') {
            allowedSizes = eligibleSizes(container, sizes(container.getAttribute('data-hm-gpt-sizes')) || allowedSizes);
            container.setAttribute('data-hm-gpt-eligible-sizes', JSON.stringify(allowedSizes));
            if (!allowedSizes.length) { report(container, 'ineligible'); return; }
        }

        var googletag = window.googletag;
        if (!googletag || typeof googletag.defineSlot !== 'function' || typeof googletag.pubads !== 'function' || typeof googletag.display !== 'function') {
            report(container, 'failed');
            return;
        }

        var slot;
        var pubads;
        try {
            slot = googletag.defineSlot(adUnitPath, allowedSizes, container.id);
            if (!slot) {
                report(container, 'failed');
                return;
            }
            pubads = googletag.pubads();
            if (!pubads || typeof slot.addService !== 'function') {
                report(container, 'failed');
                destroySlot(slot);
                return;
            }
        } catch (error) {
            report(container, 'failed');
            if (slot) destroySlot(slot);
            return;
        }

        var finished = false;
        var onRender = function (event) {
            if (finished || !event || event.slot !== slot) return;
            finished = true;
            if (pubads && typeof pubads.removeEventListener === 'function') {
                try { pubads.removeEventListener('slotRenderEnded', onRender); } catch (error) { /* no-op */ }
            }
            if (event.isEmpty) {
                report(container, 'empty');
                destroySlot(slot);
                return;
            }
            var flexibleSize = container.getAttribute('data-hm-gpt-fit-container') === '1';
            var renderedSize = normalizedRenderedSize(event.size, allowedSizes, flexibleSize);
            var fluidAllowed = allowedSizes.indexOf('fluid') !== -1;
            var fluidRendered = fluidAllowed && (!flexibleSize || event.size == null || normalizedSlotSize(event.size) === 'fluid');
            if (renderedSize && !fitContainerSizes(container, [renderedSize]).length) {
                report(container, 'failed');
                destroySlot(slot);
                return;
            }
            if (!renderedSize && !fluidRendered) {
                report(container, 'failed');
                destroySlot(slot);
                return;
            }
            // A non-empty trusted GPT response is sufficient for a fluid slot:
            // fluid/native creatives intentionally determine their own height.
            report(container, 'rendered', renderedSize || null);
        };

        try {
            if (typeof pubads.addEventListener !== 'function') {
                report(container, 'failed');
                destroySlot(slot);
                return;
            }
            pubads.addEventListener('slotRenderEnded', onRender);
            slot.addService(pubads);
            container.setAttribute('data-hm-gpt-runtime-state', 'requested');
            if (typeof googletag.enableServices === 'function') googletag.enableServices();
            googletag.display(container.id);
        } catch (error) {
            if (!finished) {
                finished = true;
                report(container, 'failed');
                destroySlot(slot);
            }
        }
    }

    function scriptSource(script) {
        if (!script) return '';
        if (typeof script.src === 'string') return script.src;
        if (script.getAttribute) return String(script.getAttribute('src') || '');
        return '';
    }

    function existingGptScript() {
        if (window.googletag && window.googletag.apiReady) return true;
        if (!document.getElementsByTagName) return false;
        var scripts = document.getElementsByTagName('script') || [];
        for (var index = 0; index < scripts.length; index += 1) {
            var source = scriptSource(scripts[index]);
            if (source === GPT_URL || source.indexOf(GPT_URL + '?') === 0) return true;
        }
        return false;
    }

    function ensureGptLibrary() {
        window.googletag = window.googletag || { cmd: [] };
        window.googletag.cmd = window.googletag.cmd || [];
        if (existingGptScript()) return true;
        if (state.libraryInjected) return true;
        if (!document.createElement) return false;

        var parent = document.head;
        if (!parent && document.getElementsByTagName) {
            var heads = document.getElementsByTagName('head') || [];
            parent = heads.length ? heads[0] : null;
        }
        parent = parent || document.body;
        if (!parent || typeof parent.appendChild !== 'function') return false;

        var script = document.createElement('script');
        script.async = true;
        script.src = GPT_URL;
        if (script.setAttribute) {
            script.setAttribute('crossorigin', 'anonymous');
            script.setAttribute('data-hm-gpt-library', '1');
        }
        script.onerror = function () {
            state.libraryInjected = false;
            Array.prototype.forEach.call(document.querySelectorAll ? document.querySelectorAll(SELECTOR) : [], function (container) {
                if (container.getAttribute('data-hm-gpt-runtime-version') === RUNTIME_VERSION
                    && container.getAttribute('data-hm-gpt-runtime-state') === 'queued') {
                    report(container, 'failed');
                }
            });
        };
        state.libraryInjected = true;
        parent.appendChild(script);
        return true;
    }

    function render(container) {
        if (!container || container.getAttribute('data-hm-gpt-runtime-version') === RUNTIME_VERSION) return;

        if (container.getAttribute('data-hm-gpt-rewarded') === '1') {
            renderRewarded(container);
            return;
        }

        var adUnitPath = container.getAttribute('data-hm-gpt-ad-unit-path');
        var declaredSizes = sizes(container.getAttribute('data-hm-gpt-sizes'));
        var containerId = String(container.id || '');
        var providerInnerId = String(container.getAttribute('data-hm-gpt-inner-id') || '');
        if (!validPath(adUnitPath) || !declaredSizes || !validId(containerId) || !validId(providerInnerId)) {
            container.setAttribute('data-hm-gpt-runtime-version', RUNTIME_VERSION);
            container.setAttribute('data-hm-gpt-runtime-state', 'invalid');
            return;
        }

        var allowedSizes = eligibleSizes(container, declaredSizes);
        container.setAttribute('data-hm-gpt-runtime-version', RUNTIME_VERSION);
        container.setAttribute('data-hm-gpt-document-context', 'publisher');
        if (!allowedSizes.length) {
            container.setAttribute('data-hm-gpt-runtime-state', 'ineligible');
            return;
        }
        container.setAttribute('data-hm-gpt-eligible-sizes', JSON.stringify(allowedSizes));

        var initialSize = allowedSizes.filter(function (size) { return size !== 'fluid'; })[0] || null;
        if (container.style) {
            if (initialSize) {
                container.style.width = String(initialSize[0]) + 'px';
                container.style.height = String(initialSize[1]) + 'px';
                container.style.maxWidth = '100%';
            } else {
                // Native fluid slots must have a parent/container with non-zero
                // width; GPT will grow the height to the delivered creative.
                container.style.width = '100%';
                container.style.height = '';
                container.style.maxWidth = '100%';
            }
        }
        container.setAttribute('data-hm-gpt-runtime-state', 'queued');

        window.googletag = window.googletag || { cmd: [] };
        window.googletag.cmd = window.googletag.cmd || [];
        if (!window.googletag.cmd || typeof window.googletag.cmd.push !== 'function') {
            report(container, 'failed');
            return;
        }

        window.googletag.cmd.push(function () { startSlot(container, adUnitPath, allowedSizes); });
        if (!ensureGptLibrary()) report(container, 'failed');
    }

    function renderRewarded(container) {
        var path = String(container.getAttribute('data-hm-gpt-ad-unit-path') || '');
        container.setAttribute('data-hm-gpt-runtime-version', RUNTIME_VERSION);
        container.setAttribute('data-hm-gpt-document-context', 'publisher');
        container.style.display = 'none';
        if (!/^\/[0-9]{1,20}(?:,[0-9]{1,20})?\/[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*$/.test(path) || path.length > 280) {
            report(container, 'invalid');
            return;
        }
        if (state.rewarded) { report(container, 'ineligible'); return; }
        var seconds = Math.max(0, Math.min(86400, Number(container.getAttribute('data-hm-reward-cooldown-seconds') || 60)));
        var key = 'hm:gpt:rewarded:v1:' + path;
        try {
            var grantedAt = Number(window.localStorage.getItem(key) || 0);
            if (grantedAt > 0 && grantedAt <= Date.now() && Date.now() - grantedAt < seconds * 1000) {
                container.setAttribute('data-hm-reward-phase', 'capped');
                report(container, 'rendered');
                return;
            }
        } catch (error) { /* Storage is optional; ads remain usable in private mode. */ }
        state.rewarded = container;
        var slot = null, pubads = null, closed = false, granted = false, showing = false;
        var listeners = [], previousFocus = null, prompt = null, watch = null, ui = null;
        var timer = window.setTimeout(function () { close('failed'); }, 25000);
        function emit(name, detail) {
            if (typeof window.CustomEvent === 'function') window.dispatchEvent(new window.CustomEvent(name, {
                detail: Object.assign({ placementId: container.id, provider: 'GOOGLE_GPT_REWARDED' }, detail || {}),
            }));
        }
        function close(reason) {
            if (closed) return;
            closed = true;
            window.clearTimeout(timer);
            // Release the publisher page before calling any third-party cleanup.
            container.style.display = 'none';
            listeners.forEach(function (entry) {
                try { if (pubads && pubads.removeEventListener) pubads.removeEventListener(entry[0], entry[1]); } catch (error) {}
            });
            window.removeEventListener('keydown', keyboard);
            if (slot) { try { destroySlot(slot); } catch (error) {} }
            if (state.rewarded === container) state.rewarded = null;
            container.setAttribute('data-hm-reward-phase', reason);
            if (reason === 'failed' || reason === 'empty' || reason === 'ineligible') report(container, reason);
            try { if (previousFocus && previousFocus.isConnected && previousFocus.focus) previousFocus.focus({ preventScroll: true }); } catch (error) {}
            emit('horus:rewarded-closed', { granted: granted, reason: reason });
        }
        container.__hmDestroy = function () { close('closed'); };
        function keyboard(event) {
            if (showing || closed) return;
            if (event.key === 'Escape') { event.preventDefault(); close('dismissed'); }
            if (event.key === 'Tab' && ui) ui.cycleFocus(event);
        }
        function listen(name, handler) {
            var callback = function (event) { if (!closed && event && event.slot === slot) handler(event); };
            pubads.addEventListener(name, callback);
            listeners.push([name, callback]);
        }
        function ready(event) {
            if (closed || prompt || showing) return;
            if (typeof event.makeRewardedVisible !== 'function') { close('failed'); return; }
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { close('expired'); }, 120000);
            previousFocus = document.activeElement;
            ui = rewardedPromptUi({ reading: true, modal: true, dismiss: function () { close('dismissed'); } });
            prompt = ui.prompt;
            watch = ui.watch;
            container.style.cssText = 'position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;box-sizing:border-box;padding:16px;background:rgba(15,23,42,.35);overflow:auto;pointer-events:auto;';
            watch.addEventListener('click', function (click) {
                if (closed || showing || !click.isTrusted) return;
                click.stopPropagation();
                showing = true;
                window.clearTimeout(timer);
                container.style.display = 'none';
                try {
                    if (!event.makeRewardedVisible()) { close('failed'); return; }
                    container.setAttribute('data-hm-reward-phase', 'showing');
                    emit('horus:rewarded-opened');
                } catch (error) { close('failed'); }
            });
            container.appendChild(prompt);
            window.addEventListener('keydown', keyboard);
            try { watch.focus({ preventScroll: true }); } catch (error) {}
            container.setAttribute('data-hm-reward-phase', 'ready');
            report(container, 'rendered');
            emit('horus:rewarded-ready');
        }
        report(container, 'queued');
        window.googletag = window.googletag || { cmd: [] };
        window.googletag.cmd = window.googletag.cmd || [];
        window.googletag.cmd.push(function () {
            if (closed) return;
            try {
                var gpt = window.googletag;
                slot = gpt.defineOutOfPageSlot(path, gpt.enums.OutOfPageFormat.REWARDED);
                if (!slot) { close('ineligible'); return; }
                pubads = gpt.pubads();
                slot.addService(pubads);
                listen('rewardedSlotReady', ready);
                listen('rewardedSlotGranted', function (event) {
                    if (!showing || granted) return;
                    granted = true;
                    container.setAttribute('data-hm-reward-granted', '1');
                    try { window.localStorage.setItem(key, String(Date.now())); } catch (error) {}
                    emit('horus:rewarded-granted', { reward: { type: 'continue_reading', amount: 1 }, googleReward: event.payload || null });
                });
                listen('rewardedSlotClosed', function () { close(granted ? 'completed' : 'dismissed'); });
                listen('slotRenderEnded', function (event) { if (event.isEmpty) close('empty'); });
                gpt.enableServices();
                gpt.display(slot);
                var disabled = gpt.getConfig ? gpt.getConfig('disableInitialLoad').disableInitialLoad : (pubads.isInitialLoadDisabled && pubads.isInitialLoadDisabled());
                if (disabled && pubads.refresh) pubads.refresh([slot]);
            } catch (error) { close('failed'); }
        });
        if (!ensureGptLibrary()) close('failed');
    }

    function scan(root) {
        root = root || document;
        if (root.matches && root.matches(SELECTOR)) render(root);
        if (!root.querySelectorAll) return;
        Array.prototype.forEach.call(root.querySelectorAll(SELECTOR), render);
    }

    scan(document);
    if (typeof window.MutationObserver === 'function' && document.documentElement) {
        state.observer = new window.MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes || [], function (node) { scan(node); });
            });
        });
        state.observer.observe(document.documentElement, { childList: true, subtree: true });
    }
})(window, document);
