(function (window, document) {
    'use strict';

    var STATE_KEY = '__HORUS_GPT_DIRECT_RUNTIME_V1__';
    if (window[STATE_KEY]) {
        if (typeof window[STATE_KEY].scan === 'function') window[STATE_KEY].scan();
        return;
    }

    var state = window[STATE_KEY] = { observer: null, scan: scan };
    var SELECTOR = '[data-hm-gpt-direct="1"]';
    var GPT_URL = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';

    function validPath(value) {
        return /^\/[0-9]{1,20}\/[A-Za-z0-9_.\-/]{1,240}$/.test(String(value || ''));
    }

    function sizes(value) {
        try {
            var decoded = JSON.parse(String(value || ''));
            if (!Array.isArray(decoded) || !decoded.length || decoded.length > 20) return null;
            var normalized = decoded.map(function (size) {
                if (!Array.isArray(size) || size.length !== 2) return null;
                var width = Number(size[0]);
                var height = Number(size[1]);
                if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || width > 10000 || height < 1 || height > 10000) return null;
                return [width, height];
            });
            return normalized.every(Boolean) ? normalized : null;
        } catch (error) {
            return null;
        }
    }

    function validId(value) {
        return /^[A-Za-z][A-Za-z0-9_:.-]{0,127}$/.test(String(value || ''));
    }

    function frameDocument(adUnitPath, allowedSizes, innerId) {
        var pathJson = JSON.stringify(adUnitPath);
        var sizesJson = JSON.stringify(allowedSizes);
        var idJson = JSON.stringify(innerId);
        var width = Math.max.apply(null, allowedSizes.map(function (size) { return size[0]; }));
        var height = Math.max.apply(null, allowedSizes.map(function (size) { return size[1]; }));

        return '<!doctype html><html><head><meta charset="utf-8">'
            + '<meta name="viewport" content="width=device-width,initial-scale=1">'
            + '<style>html,body{margin:0;padding:0;overflow:hidden;background:transparent}#' + innerId + '{width:' + width + 'px;min-height:' + height + 'px}</style>'
            + '<script>window.googletag=window.googletag||{cmd:[]};googletag.cmd.push(function(){'
            + 'var slot=googletag.defineSlot(' + pathJson + ',' + sizesJson + ',' + idJson + ');'
            + 'if(!slot){return;}if(slot.setForceSafeFrame){slot.setForceSafeFrame(true);}slot.addService(googletag.pubads());'
            + 'googletag.enableServices();googletag.display(' + idJson + ');});<\/script>'
            + '<script async src="' + GPT_URL + '"><\/script></head><body><div id="' + innerId + '"></div></body></html>';
    }

    function render(container) {
        if (!container || container.getAttribute('data-hm-gpt-runtime-state')) return;

        var adUnitPath = container.getAttribute('data-hm-gpt-ad-unit-path');
        var allowedSizes = sizes(container.getAttribute('data-hm-gpt-sizes'));
        var containerId = String(container.id || '');
        if (!validPath(adUnitPath) || !allowedSizes || !validId(containerId)) {
            container.setAttribute('data-hm-gpt-runtime-state', 'invalid');
            return;
        }

        container.setAttribute('data-hm-gpt-runtime-state', 'starting');
        var frame = document.createElement('iframe');
        var width = Math.max.apply(null, allowedSizes.map(function (size) { return size[0]; }));
        var height = Math.max.apply(null, allowedSizes.map(function (size) { return size[1]; }));
        var innerId = 'hm-gpt-slot-' + containerId.replace(/[^A-Za-z0-9_:.-]/g, '-');

        frame.title = 'Advertisement';
        frame.setAttribute('aria-label', 'Advertisement');
        frame.setAttribute('data-hm-gpt-direct-frame', '1');
        frame.setAttribute('width', String(width));
        frame.setAttribute('height', String(height));
        frame.setAttribute('scrolling', 'no');
        frame.setAttribute('frameborder', '0');
        frame.style.border = '0';
        frame.style.display = 'block';
        frame.style.maxWidth = '100%';
        frame.srcdoc = frameDocument(adUnitPath, allowedSizes, innerId);
        frame.onload = function () {
            container.setAttribute('data-hm-gpt-runtime-state', 'loaded');
            container.setAttribute('data-hm-gpt-status', 'requested');
        };
        frame.onerror = function () {
            container.setAttribute('data-hm-gpt-runtime-state', 'failed');
        };
        container.appendChild(frame);
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
