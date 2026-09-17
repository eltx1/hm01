(function (window, document) {
    'use strict';

    var STATE_KEY = '__HORUS_GPT_DIRECT_RUNTIME_V3__';
    if (window[STATE_KEY]) {
        if (typeof window[STATE_KEY].scan === 'function') window[STATE_KEY].scan();
        return;
    }

    var state = window[STATE_KEY] = { observer: null, scan: scan, libraryInjected: false };
    var SELECTOR = '[data-hm-gpt-direct="1"]';
    var GPT_URL = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
    var RUNTIME_VERSION = '3';
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

    function sizes(value) {
        try {
            var decoded = JSON.parse(String(value || ''));
            if (!Array.isArray(decoded) || !decoded.length || decoded.length > 20) return null;
            var normalized = decoded.map(normalizedSize);
            return normalized.every(Boolean) ? normalized : null;
        } catch (error) {
            return null;
        }
    }

    function sizeKey(size) {
        return size[0] + 'x' + size[1];
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
                var mappedSizes = Array.isArray(mapping.sizes) ? mapping.sizes.map(normalizedSize).filter(Boolean) : [];
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
            if (allowedSizes[exactIndex][0] === normalized[0] && allowedSizes[exactIndex][1] === normalized[1]) return normalized;
        }

        // Google Ad Manager can legitimately return a rendered creative whose
        // pixel dimensions differ from the requested slot because of ad-slot
        // expansion/contraction or creatives configured to differ from the ad
        // unit size. Treat that trusted GPT result as valid only when it remains
        // reasonably close to at least one reviewed/requested size. This keeps
        // the original placement boundary while avoiding false rejection of
        // normal GAM responses such as a 980x100 creative for a 980x90 request.
        for (var index = 0; index < allowedSizes.length; index += 1) {
            var requested = allowedSizes[index];
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
            if (!renderedSize) {
                report(container, 'failed');
                destroySlot(slot);
                return;
            }
            report(container, 'rendered', renderedSize);
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

        var initialSize = allowedSizes[0];
        if (container.style) {
            container.style.width = String(initialSize[0]) + 'px';
            container.style.height = String(initialSize[1]) + 'px';
            container.style.maxWidth = '100%';
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
