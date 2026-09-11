(function (window, document) {
    'use strict';

    var STATE_KEY = '__HORUS_ISOLATED_DIRECT_RUNTIME_V1__';
    if (window[STATE_KEY]) {
        if (typeof window[STATE_KEY].scan === 'function') window[STATE_KEY].scan();
        return;
    }

    var SELECTOR = '[data-hm-isolated-direct="1"]';
    var state = window[STATE_KEY] = { observer: null, scan: scan };

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

    function render(container) {
        if (!container || container.getAttribute('data-hm-isolated-runtime-state')) return;

        var html = decodeBase64(container.getAttribute('data-hm-isolated-html'));
        var csp = decodeBase64(container.getAttribute('data-hm-isolated-csp'));
        var width = positiveInteger(container.getAttribute('data-hm-isolated-width'), 300);
        var height = positiveInteger(container.getAttribute('data-hm-isolated-height'), 250);
        if (!html || !csp || /["<>]/.test(csp)) {
            container.setAttribute('data-hm-isolated-runtime-state', 'invalid');
            return;
        }

        container.setAttribute('data-hm-isolated-runtime-state', 'starting');
        var frame = document.createElement('iframe');
        frame.title = 'Advertisement';
        frame.setAttribute('aria-label', 'Advertisement');
        frame.setAttribute('data-hm-direct-frame', '1');
        frame.setAttribute('width', String(width));
        frame.setAttribute('height', String(height));
        frame.setAttribute('scrolling', 'no');
        frame.setAttribute('frameborder', '0');
        frame.style.border = '0';
        frame.style.display = 'block';
        frame.style.maxWidth = '100%';
        if (frame.sandbox && frame.sandbox.add) frame.sandbox.add('allow-scripts');
        else frame.setAttribute('sandbox', 'allow-scripts');
        frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'
            + csp + '"></head><body style="margin:0;padding:0">' + html + '</body></html>';
        frame.onload = function () {
            container.setAttribute('data-hm-isolated-runtime-state', 'loaded');
            container.setAttribute('data-hm-isolated-status', 'requested');
        };
        frame.onerror = function () {
            container.setAttribute('data-hm-isolated-runtime-state', 'failed');
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
