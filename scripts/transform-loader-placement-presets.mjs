const ELIGIBLE_ANCHOR = `    function eligibleElements(config) {\n        var nodes = [];`;
const STICKY_BLOCK = `                            if (formatSettings.position && element.style && placement.type === 'STICKY') {\n                                element.style.position = 'fixed'; element.style.zIndex = '2147483000'; element.style.left = '50%'; element.style.transform = 'translateX(-50%)';\n                                element.style[formatSettings.position === 'top' ? 'top' : 'bottom'] = '0';\n                            }`;
const NATIVE_ONLY_ANCHOR = `        nativeOnly.forEach(function (item) {\n            ensureElementId(item.element, config, item.placement);`;

const HELPERS = `    function placementFormatSettings(placement) {
        return placement && placement.format && placement.format.settings || {};
    }

    function placementElementExists(code) {
        var found = false;
        Array.prototype.forEach.call(nodeList('.hm-ad[data-placement], .hm-native[data-placement]'), function (node) {
            if (node.getAttribute && node.getAttribute('data-placement') === code) found = true;
        });
        return found;
    }

    function placementViewportAllowed(settings) {
        var width = Number(window.innerWidth || document.documentElement && document.documentElement.clientWidth || 0);
        var minWidth = Number(settings.minViewportWidth || 0);
        var maxWidth = Number(settings.maxViewportWidth || 0);
        if (minWidth > 0 && width > 0 && width < minWidth) return false;
        if (maxWidth > 0 && width > maxWidth) return false;
        return true;
    }

    function contentMountRoot() {
        if (!document.querySelector) return null;
        return document.querySelector('article') || document.querySelector('main');
    }

    function mountAutoPlacementElement(element, settings) {
        var target = String(settings.autoMountTarget || 'body_end').toLowerCase();
        var content = contentMountRoot();
        if (target === 'article_mid' && content) {
            var children = content.children || [];
            var midpoint = Math.floor(children.length / 2);
            if (children.length && content.insertBefore) {
                content.insertBefore(element, children[midpoint] || null);
                return;
            }
            if (content.appendChild) {
                content.appendChild(element);
                return;
            }
        }
        if (target === 'article_end' && content && content.appendChild) {
            content.appendChild(element);
            return;
        }
        document.body.appendChild(element);
    }

    function autoMountPlacementElements(config) {
        if (!document.createElement || !document.body || !document.body.appendChild) return;
        (config.placements || []).forEach(function (placement) {
            var settings = placementFormatSettings(placement);
            if (!placement || !placement.enabled || placement.status !== 'active' || settings.autoMount !== true) return;
            if (!placementViewportAllowed(settings) || placementElementExists(placement.code)) return;
            var element = document.createElement('div');
            element.className = placement.type === 'NATIVE' ? 'hm-native hm-auto-placement' : 'hm-ad hm-auto-placement';
            element.setAttribute('data-placement', placement.code);
            element.setAttribute('data-hm-auto-mounted', '1');
            element.setAttribute('data-hm-auto-mount-target', String(settings.autoMountTarget || 'body_end'));
            mountAutoPlacementElement(element, settings);
            applyPlacementPresetPresentation(element, placement, settings);
        });
    }

    function applyStickyPosition(element, placement, settings) {
        if (!element || !element.style || !placement) return;
        var position = String(settings.position || '').toLowerCase();
        var style = element.style;

        if (placement.type === 'VIDEO' && position === 'bottom_right') {
            style.position = 'fixed';
            style.zIndex = '2147483000';
            style.right = '16px';
            style.bottom = '16px';
            style.left = '';
            style.top = '';
            style.transform = '';
            return;
        }

        if (placement.type !== 'STICKY') return;
        position = position || 'bottom';
        style.position = 'fixed';
        style.zIndex = '2147483000';
        style.top = '';
        style.right = '';
        style.bottom = '';
        style.left = '';
        style.transform = '';
        if (position === 'right') {
            style.right = '0';
            style.top = '50%';
            style.transform = 'translateY(-50%)';
        } else if (position === 'left') {
            style.left = '0';
            style.top = '50%';
            style.transform = 'translateY(-50%)';
        } else {
            style.left = '50%';
            style.transform = 'translateX(-50%)';
            style[position === 'top' ? 'top' : 'bottom'] = '0';
        }
    }

    function ensurePlacementCloseControl(element, settings) {
        if (!element || !element.appendChild || !document.createElement || !settings || settings.closeable !== true) return;
        if (element.getAttribute && element.getAttribute('data-hm-placement-dismissed') === '1') return;
        if (element.querySelector && element.querySelector('[data-hm-placement-close="1"]')) return;
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = '×';
        button.setAttribute('aria-label', 'Close advertisement');
        button.setAttribute('data-hm-placement-close', '1');
        button.style.cssText = 'position:absolute;top:4px;right:4px;z-index:2147483001;min-width:28px;min-height:28px;padding:0 7px;border:0;border-radius:999px;background:rgba(0,0,0,.72);color:#fff;font:20px/28px sans-serif;cursor:pointer;';
        button.addEventListener('click', function (event) {
            if (event && event.preventDefault) event.preventDefault();
            if (event && event.stopPropagation) event.stopPropagation();
            if (element.setAttribute) element.setAttribute('data-hm-placement-dismissed', '1');
            if (element.style) element.style.display = 'none';
        });
        element.appendChild(button);
    }

    function applyPlacementPresetPresentation(element, placement, settings) {
        settings = settings || placementFormatSettings(placement);
        applyStickyPosition(element, placement, settings);
        ensurePlacementCloseControl(element, settings);
    }

`;

export function applyPlacementPresetTransform(input) {
    let source = String(input);

    if (!source.includes('function autoMountPlacementElements(config)')) {
        if (!source.includes(ELIGIBLE_ANCHOR)) {
            throw new Error('Unable to locate eligibleElements anchor for placement preset runtime');
        }
        source = source.replace(ELIGIBLE_ANCHOR, `${HELPERS}    function eligibleElements(config) {\n        autoMountPlacementElements(config);\n        var nodes = [];`);
    }

    if (!source.includes('applyPlacementPresetPresentation(element, placement, formatSettings);')) {
        if (!source.includes(STICKY_BLOCK)) {
            throw new Error('Unable to locate sticky positioning block for placement preset runtime');
        }
        source = source.replace(STICKY_BLOCK, `                            applyPlacementPresetPresentation(element, placement, formatSettings);`);
    }

    if (!source.includes('applyPlacementPresetPresentation(item.element, item.placement, placementFormatSettings(item.placement));')) {
        if (!source.includes(NATIVE_ONLY_ANCHOR)) {
            throw new Error('Unable to locate Direct JS nativeOnly anchor for placement preset runtime');
        }
        source = source.replace(NATIVE_ONLY_ANCHOR, `${NATIVE_ONLY_ANCHOR}\n            applyPlacementPresetPresentation(item.element, item.placement, placementFormatSettings(item.placement));`);
    }

    return source;
}
