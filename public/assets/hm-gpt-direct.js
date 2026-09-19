(function (window, document) {
    'use strict';

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
        return /^\/[0-9]{1,20}\/[A-Za-z0-9_.\-/]{1,240}$/.test(String(value || ''));
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
        if (!mappings.length) return allowedSizes.slice();

        var viewport = viewportSize();
        var width = viewport[0];
        var height = viewport[1];
        if (!width && !height) return allowedSizes.slice();

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
            if (selected.length) return selected;
        }

        return [];
    }

    function validId(value) {
        return /^[A-Za-z][A-Za-z0-9_-]{0,127}$/.test(String(value || ''));
    }

    function normalizedRenderedSize(size, allowedSizes) {
        var normalized = normalizedSize(size);
        if (!normalized) return null;

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
            var renderedSize = normalizedRenderedSize(event.size, allowedSizes);
            var fluidAllowed = allowedSizes.indexOf('fluid') !== -1;
            if (!renderedSize && !fluidAllowed) {
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
        var seconds = Math.max(0, Math.min(86400, Number(container.getAttribute('data-hm-reward-cooldown-seconds') || 900)));
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
        var listeners = [], previousFocus = null, prompt = null, watch = null, decline = null;
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
            listeners.forEach(function (entry) { if (pubads.removeEventListener) pubads.removeEventListener(entry[0], entry[1]); });
            window.removeEventListener('keydown', keyboard);
            if (slot) destroySlot(slot);
            container.style.display = 'none';
            if (state.rewarded === container) state.rewarded = null;
            container.setAttribute('data-hm-reward-phase', reason);
            if (reason === 'failed' || reason === 'empty' || reason === 'ineligible') report(container, reason);
            if (previousFocus && previousFocus.isConnected && previousFocus.focus) previousFocus.focus({ preventScroll: true });
            emit('horus:rewarded-closed', { granted: granted, reason: reason });
        }
        container.__hmDestroy = function () { close('closed'); };
        function keyboard(event) {
            if (showing || closed) return;
            if (event.key === 'Escape') { event.preventDefault(); close('dismissed'); }
            if (event.key === 'Tab') {
                event.preventDefault();
                (document.activeElement === watch ? decline : watch).focus();
            }
        }
        function listen(name, handler) {
            var callback = function (event) { if (!closed && event && event.slot === slot) handler(event); };
            pubads.addEventListener(name, callback);
            listeners.push([name, callback]);
        }
        function ready(event) {
            if (prompt || showing) return;
            window.clearTimeout(timer);
            var ar = /^ar\b/i.test(String(document.documentElement.lang || ''));
            previousFocus = document.activeElement;
            prompt = document.createElement('div');
            var title = document.createElement('strong');
            var copy = document.createElement('p');
            watch = document.createElement('button');
            decline = document.createElement('button');
            container.style.cssText = 'position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;box-sizing:border-box;padding:20px;background:rgba(5,8,22,.78);overflow:auto;';
            prompt.style.cssText = 'box-sizing:border-box;width:100%;max-width:480px;padding:28px;border:1px solid rgba(241,183,51,.22);border-radius:26px;background:linear-gradient(135deg,#050b1e,#0a2153);color:#f6f8ff;text-align:center;font:16px/1.6 system-ui,sans-serif;box-shadow:0 28px 90px rgba(0,0,0,.38);';
            prompt.setAttribute('role', 'dialog');
            prompt.setAttribute('aria-modal', 'true');
            prompt.setAttribute('dir', ar ? 'rtl' : 'ltr');
            prompt.setAttribute('aria-label', ar ? 'استكمال القراءة' : 'Continue reading');
            title.textContent = ar ? 'أكمل قراءتك' : 'Continue your reading';
            title.style.cssText = 'font:700 24px/1.4 system-ui,sans-serif;color:#f6f8ff;';
            copy.textContent = ar ? 'شاهد الإعلان للحصول على إمكانية استكمال قراءة المحتوى. يمكنك الإغلاق في أي وقت.' : 'View an ad to continue reading the content. You can close at any time.';
            copy.style.cssText = 'margin:16px 0;color:#c7d1e5;font:400 16px/1.6 system-ui,sans-serif;';
            watch.type = decline.type = 'button';
            watch.textContent = ar ? 'شاهد الإعلان واستكمل القراءة' : 'View ad and continue reading';
            decline.textContent = ar ? 'متابعة القراءة الآن' : 'Continue reading now';
            var buttonCss = 'display:block;width:100%;min-height:44px;margin-top:12px;white-space:normal;padding:12px;border-radius:999px;font:600 15px/1.5 system-ui,sans-serif;cursor:pointer;';
            watch.style.cssText = buttonCss + 'border:0;color:#071127;background:linear-gradient(115deg,#ffe495,#f1b733 56%,#cf8b13);';
            decline.style.cssText = buttonCss + 'border:1px solid #9da9c2;color:#f6f8ff;background:transparent;';
            watch.addEventListener('click', function (click) {
                if (closed || showing || !click.isTrusted) return;
                showing = true;
                container.style.display = 'none';
                try {
                    if (!event.makeRewardedVisible()) { close('failed'); return; }
                    container.setAttribute('data-hm-reward-phase', 'showing');
                    emit('horus:rewarded-opened');
                } catch (error) { close('failed'); }
            });
            decline.addEventListener('click', function () { close('dismissed'); });
            [title, copy, watch, decline].forEach(function (node) { prompt.appendChild(node); });
            container.appendChild(prompt);
            window.addEventListener('keydown', keyboard);
            watch.focus({ preventScroll: true });
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
