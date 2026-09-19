(function (window, document) {
    'use strict';

    var STATE_KEY = '__HORUS_ISOLATED_DIRECT_RUNTIME_V1__';
    if (window[STATE_KEY]) {
        if (typeof window[STATE_KEY].scan === 'function') window[STATE_KEY].scan();
        return;
    }

    var SELECTOR = '[data-hm-isolated-direct="1"]';
    var state = window[STATE_KEY] = { observer: null, frames: {}, listenerInstalled: false, scan: scan };

    function installMessageListener() {
        if (state.listenerInstalled || !window.addEventListener) return;
        state.listenerInstalled = true;
        window.addEventListener('message', function (event) {
            var data = event && event.data || {};
            if (data.type !== 'hm-isolated-rendered' || !data.token) return;
            var entry = state.frames[String(data.token)];
            if (!entry || event.source !== entry.frame.contentWindow) return;
            entry.container.setAttribute('data-hm-isolated-runtime-state', 'rendered');
            entry.container.setAttribute('data-hm-isolated-status', 'rendered');
        });
    }

    function renderBridge(token) {
        var encoded = JSON.stringify(String(token));
        return '<script>(function(){var sent=false,token='+encoded+';function visible(){if(sent)return;var nodes=document.querySelectorAll("video,iframe,object,embed,canvas,img,picture,[data-ad-status],[data-ad-rendered]");for(var i=0;i<nodes.length;i++){var n=nodes[i],r=n.getBoundingClientRect?n.getBoundingClientRect():null;if(!r||r.width>1||r.height>1){sent=true;parent.postMessage({type:"hm-isolated-rendered",token:token},"*");return;}}}new MutationObserver(visible).observe(document.documentElement,{childList:true,subtree:true,attributes:true});setTimeout(visible,0);setTimeout(visible,250);})();<\/script>';
    }

    function positiveInteger(value, fallback) {
        var number = Number(value);
        return Number.isInteger(number) && number > 0 && number <= 10000 ? number : fallback;
    }

    function encodedPayload(container, baseAttribute) {
        var direct = container.getAttribute(baseAttribute);
        if (direct) return direct;

        var count = positiveInteger(container.getAttribute(baseAttribute + '-parts'), 0);
        if (count < 1 || count > 64) return null;

        var joined = '';
        for (var index = 0; index < count; index += 1) {
            var part = container.getAttribute(baseAttribute + '-' + index);
            if (!part || part.length > 2000) return null;
            joined += part;
        }
        return joined || null;
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
        try {
            return JSON.parse(raw);
        } catch (error) {
            return fallback;
        }
    }

    function normalizedSize(value) {
        if (!Array.isArray(value) || value.length !== 2) return null;
        var width = positiveInteger(value[0], 0);
        var height = positiveInteger(value[1], 0);
        return width && height ? [width, height] : null;
    }

    function allowedSizes(container) {
        var parsed = jsonAttribute(container, 'data-hm-isolated-sizes', []);
        if (!Array.isArray(parsed)) return [];
        var output = [];
        parsed.forEach(function (candidate) {
            var size = normalizedSize(candidate);
            if (!size) return;
            var key = size[0] + 'x' + size[1];
            if (!output.some(function (existing) { return existing[0] + 'x' + existing[1] === key; })) output.push(size);
        });
        return output.slice(0, 20);
    }

    function sizeAllowed(size, sizes) {
        if (!size || !sizes.length) return true;
        return sizes.some(function (allowed) { return allowed[0] === size[0] && allowed[1] === size[1]; });
    }

    function viewportSize() {
        var root = document.documentElement || {};
        return [
            Number(window.innerWidth || root.clientWidth || 0),
            Number(window.innerHeight || root.clientHeight || 0),
        ];
    }

    function mappingSize(container, sizes) {
        var mappings = jsonAttribute(container, 'data-hm-isolated-size-map', []);
        if (!Array.isArray(mappings) || !mappings.length) return null;
        var viewport = viewportSize();
        var viewportWidth = viewport[0];
        var viewportHeight = viewport[1];

        for (var index = 0; index < mappings.length && index < 100; index += 1) {
            var mapping = mappings[index] || {};
            var minWidth = Math.max(0, Number(mapping.minWidth || 0));
            var minHeight = Math.max(0, Number(mapping.minHeight || 0));
            var maxWidth = mapping.maxWidth == null ? 0 : Math.max(0, Number(mapping.maxWidth || 0));
            var maxHeight = mapping.maxHeight == null ? 0 : Math.max(0, Number(mapping.maxHeight || 0));
            if (viewportWidth && viewportWidth < minWidth) continue;
            if (viewportHeight && viewportHeight < minHeight) continue;
            if (maxWidth && viewportWidth && viewportWidth > maxWidth) continue;
            if (maxHeight && viewportHeight && viewportHeight > maxHeight) continue;

            var size = normalizedSize([mapping.width, mapping.height]);
            if (size && sizeAllowed(size, sizes)) return size;
        }
        return null;
    }

    function selectedSize(container) {
        var sizes = allowedSizes(container);
        var mapped = mappingSize(container, sizes);
        if (mapped) return mapped;
        if (sizes.length) return sizes[0];
        return [
            positiveInteger(container.getAttribute('data-hm-isolated-width'), 300),
            positiveInteger(container.getAttribute('data-hm-isolated-height'), 250),
        ];
    }

    function render(container) {
        if (!container || container.getAttribute('data-hm-isolated-runtime-state')) return;

        var html = decodeBase64(encodedPayload(container, 'data-hm-isolated-html'));
        var csp = decodeBase64(encodedPayload(container, 'data-hm-isolated-csp'));
        var size = selectedSize(container);
        var width = size[0];
        var height = size[1];
        if (!html || !csp || /["<>]/.test(csp)) {
            container.setAttribute('data-hm-isolated-runtime-state', 'invalid');
            return;
        }

        container.setAttribute('data-hm-isolated-runtime-state', 'starting');
        container.setAttribute('data-hm-isolated-selected-width', String(width));
        container.setAttribute('data-hm-isolated-selected-height', String(height));
        if (container.style) {
            container.style.width = String(width) + 'px';
            container.style.maxWidth = '100%';
            container.style.minHeight = String(height) + 'px';
        }

        var frame = document.createElement('iframe');
        frame.title = 'Advertisement';
        frame.setAttribute('aria-label', 'Advertisement');
        frame.setAttribute('data-hm-direct-frame', '1');
        frame.setAttribute('width', String(width));
        frame.setAttribute('height', String(height));
        frame.setAttribute('scrolling', 'no');
        frame.setAttribute('frameborder', '0');
        frame.setAttribute('allow', 'autoplay; fullscreen');
        frame.style.border = '0';
        frame.style.display = 'block';
        frame.style.maxWidth = '100%';
        if (frame.sandbox && frame.sandbox.add) frame.sandbox.add('allow-scripts');
        else frame.setAttribute('sandbox', 'allow-scripts');
        var token = String(container.id || 'hm-isolated') + ':' + String(Math.random()).slice(2);
        installMessageListener();
        state.frames[token] = { container: container, frame: frame };
        frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'
            + csp + '"></head><body style="margin:0;padding:0">' + html + renderBridge(token) + '</body></html>';
        frame.onload = function () {
            container.setAttribute('data-hm-isolated-runtime-state', 'loaded');
            container.setAttribute('data-hm-isolated-status', 'loaded');
        };
        frame.onerror = function () {
            container.setAttribute('data-hm-isolated-runtime-state', 'failed');
            container.setAttribute('data-hm-isolated-status', 'failed');
        };
        container.__hmDestroy = function (reason) {
            delete state.frames[token];
            try { frame.src = 'about:blank'; } catch (error) {}
            try { if (frame.parentNode && frame.parentNode.removeChild) frame.parentNode.removeChild(frame); } catch (error) {}
            container.setAttribute('data-hm-isolated-runtime-state', reason || 'dismissed');
            container.setAttribute('data-hm-isolated-status', reason || 'dismissed');
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
