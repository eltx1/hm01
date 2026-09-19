const ELIGIBLE_ANCHOR = `    function eligibleElements(config) {\n        var nodes = [];`;
const STICKY_BLOCK = `                            if (formatSettings.position && element.style && placement.type === 'STICKY') {\n                                element.style.position = 'fixed'; element.style.zIndex = '2147483000'; element.style.left = '50%'; element.style.transform = 'translateX(-50%)';\n                                element.style[formatSettings.position === 'top' ? 'top' : 'bottom'] = '0';\n                            }`;
const NATIVE_ONLY_ANCHOR = `        nativeOnly.forEach(function (item) {\n            ensureElementId(item.element, config, item.placement);`;
const DIRECT_CONTAINER_ANCHOR = `        setCandidateAttributes(container, recipe.attributes || tag.attributes || {});\n        if (entry.element.appendChild) entry.element.appendChild(container);`;

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

        // Publisher-controlled hook wins when a site/app wants to identify its
        // primary content region explicitly without adding an ad container.
        return document.querySelector('[data-hm-content-root]')
            // Editorial/CMS conventions.
            || document.querySelector('[itemprop="articleBody"]')
            || document.querySelector('.entry-content')
            || document.querySelector('.post-content')
            || document.querySelector('.article-content')
            || document.querySelector('.page-content')
            || document.querySelector('.content-area')
            // Framework- and app-style primary-content conventions. These make
            // automatic in-content placements work on video, tools, galleries,
            // feeds and SPA pages too; WordPress is not required.
            || document.querySelector('[role="main"]')
            || document.querySelector('.site-main')
            || document.querySelector('.main-content')
            || document.querySelector('#primary')
            || document.querySelector('#main')
            || document.querySelector('#content')
            || document.querySelector('article')
            || document.querySelector('main');
    }

    function contentMountChildren(content) {
        if (!content || !content.children) return [];
        return Array.prototype.filter.call(content.children, function (child) {
            var tag = String(child && child.tagName || '').toUpperCase();
            if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'LINK', 'META'].indexOf(tag) !== -1) return false;
            if (child.getAttribute && child.getAttribute('data-hm-auto-mounted') === '1') return false;
            return true;
        });
    }

    function mountAutoPlacementElement(element, settings) {
        var target = String(settings.autoMountTarget || 'body_end').toLowerCase();
        var content = contentMountRoot();
        var middleTarget = target === 'content_mid' || target === 'article_mid';
        var endTarget = target === 'content_end' || target === 'article_end';

        if (middleTarget && content) {
            var children = contentMountChildren(content);
            // With one primary block (for example a video player), "middle"
            // has no safe split point; append after it instead of placing the ad
            // before the only piece of content.
            if (children.length > 1 && content.insertBefore) {
                var midpoint = Math.floor(children.length / 2);
                content.insertBefore(element, children[midpoint] || null);
                return;
            }
            if (content.appendChild) {
                content.appendChild(element);
                return;
            }
        }
        if (endTarget && content && content.appendChild) {
            content.appendChild(element);
            return;
        }

        // Do not guess a "middle" by splitting the raw body: that can place ads
        // inside headers, navigation or application chrome. If no primary content
        // root is discoverable, fall back safely to the document end.
        document.body.appendChild(element);
    }

    function autoMountPlacementElements(config) {
        if (!document.createElement || !document.body || !document.body.appendChild) return;
        (config.placements || []).forEach(function (placement) {
            var settings = placementFormatSettings(placement);
            if (!placement || !placement.enabled || placement.status !== 'active' || settings.autoMount !== true) return;
            if (!placementViewportAllowed(settings) || placementElementExists(placement.code)) return;
            var floatingVideo = placement.type === 'VIDEO' && String(settings.position || '').toLowerCase() === 'bottom_right';
            if (floatingVideo && settings.singleActiveVideo !== false && document.querySelector && document.querySelector('[data-hm-floating-video-active="1"]')) return;
            var element = document.createElement('div');
            element.className = placement.type === 'NATIVE' ? 'hm-native hm-auto-placement' : 'hm-ad hm-auto-placement';
            element.setAttribute('data-placement', placement.code);
            element.setAttribute('data-hm-auto-mounted', '1');
            element.setAttribute('data-hm-auto-mount-target', String(settings.autoMountTarget || 'body_end'));
            if (floatingVideo) element.setAttribute('data-hm-floating-video-active', '1');
            mountAutoPlacementElement(element, settings);
            applyPlacementPresetPresentation(element, placement, settings);
        });
        installFloatingVideoClearance(config);
    }

    function installFloatingVideoClearance(config) {
        var bottomCodes = (config.placements || []).filter(function (placement) {
            var settings = placementFormatSettings(placement);
            return placement.type === 'STICKY' && placement.enabled && placement.status === 'active'
                && String(settings.position || 'bottom').toLowerCase() === 'bottom';
        }).map(function (placement) { return placement.code; });
        Array.prototype.forEach.call(nodeList('[data-hm-floating-video-active="1"]'), function (floating) {
            if (!floating.getBoundingClientRect) return;
            var tracker = floating.__hmClearance;
            if (!tracker) {
                tracker = floating.__hmClearance = { anchors: [], frame: null, stopped: false };
                tracker.update = function () {
                    tracker.frame = null;
                    if (floating.isConnected === false || floating.getAttribute('data-hm-placement-dismissed') === '1') {
                        tracker.stopped = true;
                        if (tracker.resize) tracker.resize.disconnect();
                        if (tracker.mutations) tracker.mutations.disconnect();
                        if (window.removeEventListener) window.removeEventListener('resize', tracker.schedule);
                        return;
                    }
                    var bounds = floating.getBoundingClientRect();
                    var viewportHeight = Number(window.innerHeight || document.documentElement.clientHeight || 0);
                    var occupied = 0;
                    tracker.anchors.forEach(function (anchor) {
                        if (anchor.isConnected === false || anchor.getAttribute('data-hm-placement-dismissed') === '1' || !placementRendered(anchor)) return;
                        var rect = anchor.getBoundingClientRect();
                        if (rect.width <= 0 || rect.height <= 0 || rect.bottom <= 0 || rect.top >= viewportHeight) return;
                        if (rect.right <= bounds.left || rect.left >= bounds.right) return;
                        occupied = Math.max(occupied, viewportHeight - rect.top);
                    });
                    var bottom = occupied > 0 ? String(Math.ceil(occupied) + 16) + 'px' : 'calc(16px + env(safe-area-inset-bottom, 0px))';
                    if (!floating.style.getPropertyValue || floating.style.getPropertyValue('bottom') !== bottom) {
                        setImportantStyle(floating.style, 'bottom', bottom);
                    }
                };
                tracker.schedule = function () {
                    if (tracker.stopped || tracker.frame !== null) return;
                    tracker.frame = window.requestAnimationFrame ? window.requestAnimationFrame(tracker.update) : window.setTimeout(tracker.update, 16);
                };
                if (typeof window.ResizeObserver === 'function') tracker.resize = new window.ResizeObserver(tracker.schedule);
                if (typeof window.MutationObserver === 'function') {
                    tracker.mutations = new window.MutationObserver(tracker.schedule);
                    tracker.mutations.observe(floating, { attributes: true, attributeFilter: ['data-hm-placement-dismissed'] });
                }
                if (window.addEventListener) window.addEventListener('resize', tracker.schedule);
            }
            if (tracker.stopped) return;
            Array.prototype.forEach.call(nodeList('.hm-ad[data-placement], .hm-native[data-placement]'), function (anchor) {
                if (bottomCodes.indexOf(anchor.getAttribute('data-placement')) === -1 || tracker.anchors.indexOf(anchor) !== -1) return;
                tracker.anchors.push(anchor);
                if (tracker.resize) tracker.resize.observe(anchor);
                if (tracker.mutations) tracker.mutations.observe(anchor, {
                    attributes: true, attributeFilter: ['data-hm-status', 'data-hm-placement-dismissed', 'style', 'class'], childList: true, subtree: true
                });
            });
            tracker.update();
        });
    }

    function setImportantStyle(style, name, value) {
        if (!style) return;
        if (style.setProperty) style.setProperty(name, value, 'important');
        else style[name.replace(/-([a-z])/g, function (_, letter) { return letter.toUpperCase(); })] = value;
    }

    function resetPositionStyle(style, name) {
        if (!style) return;
        setImportantStyle(style, name, name === 'transform' ? 'none' : 'auto');
    }

    function applyStickyPosition(element, placement, settings) {
        if (!element || !element.style || !placement) return;
        var position = String(settings.position || '').toLowerCase();
        var style = element.style;

        if (placement.type === 'VIDEO' && position === 'bottom_right') {
            setImportantStyle(style, 'position', 'fixed');
            setImportantStyle(style, 'z-index', '2147483000');
            setImportantStyle(style, 'right', '16px');
            setImportantStyle(style, 'bottom', 'calc(16px + env(safe-area-inset-bottom, 0px))');
            resetPositionStyle(style, 'left');
            resetPositionStyle(style, 'top');
            resetPositionStyle(style, 'transform');
            setImportantStyle(style, 'margin', '0');
            setImportantStyle(style, 'width', 'min(400px, calc(100vw - 32px))');
            setImportantStyle(style, 'max-width', 'calc(100vw - 32px)');
            setImportantStyle(style, 'aspect-ratio', '16 / 9');
            setImportantStyle(style, 'box-sizing', 'border-box');
            setImportantStyle(style, 'background', '#000');
            setImportantStyle(style, 'box-shadow', '0 12px 36px rgba(0,0,0,.38)');
            return;
        }

        if (placement.type !== 'STICKY') return;
        position = position || 'bottom';
        setImportantStyle(style, 'position', 'fixed');
        setImportantStyle(style, 'z-index', '2147483000');
        resetPositionStyle(style, 'top');
        resetPositionStyle(style, 'right');
        resetPositionStyle(style, 'bottom');
        resetPositionStyle(style, 'left');
        resetPositionStyle(style, 'transform');
        setImportantStyle(style, 'margin', '0');
        setImportantStyle(style, 'max-width', '100vw');
        if (position === 'right') {
            setImportantStyle(style, 'right', '0');
            setImportantStyle(style, 'top', '50%');
            setImportantStyle(style, 'transform', 'translateY(-50%)');
        } else if (position === 'left') {
            setImportantStyle(style, 'left', '0');
            setImportantStyle(style, 'top', '50%');
            setImportantStyle(style, 'transform', 'translateY(-50%)');
        } else {
            setImportantStyle(style, 'left', '50%');
            setImportantStyle(style, 'transform', 'translateX(-50%)');
            setImportantStyle(style, position === 'top' ? 'top' : 'bottom', '0');
        }
    }

    function placementIsInContent(placement, settings) {
        settings = settings || placementFormatSettings(placement);
        var target = String(settings.autoMountTarget || '').toLowerCase();
        var contentPosition = String(settings.contentPosition || '').toLowerCase();
        var surfaceMount = String(settings.surface && settings.surface.mount || '').toLowerCase();
        var formatCode = String(placement && placement.format && placement.format.code || '').toLowerCase();
        var contentTargets = ['content_mid', 'article_mid', 'content_end', 'article_end'];

        return formatCode === 'display_in_article'
            || contentTargets.indexOf(target) !== -1
            || contentTargets.indexOf(contentPosition) !== -1
            || contentTargets.indexOf(surfaceMount) !== -1;
    }

    function applyContentAlignment(element, placement, settings) {
        if (!element || !element.style || !placementIsInContent(placement, settings)) return;

        // The placement owns the available content width while the provider
        // creative can keep its declared fixed width (or 100% for fluid/native).
        // Centering at the Horus wrapper avoids relying on publisher theme CSS.
        var style = element.style;
        setImportantStyle(style, 'display', 'flex');
        setImportantStyle(style, 'flex-direction', 'column');
        setImportantStyle(style, 'align-items', 'center');
        setImportantStyle(style, 'justify-content', 'flex-start');
        setImportantStyle(style, 'width', '100%');
        setImportantStyle(style, 'max-width', '100%');
        setImportantStyle(style, 'box-sizing', 'border-box');
        setImportantStyle(style, 'margin-left', 'auto');
        setImportantStyle(style, 'margin-right', 'auto');
        setImportantStyle(style, 'text-align', 'center');
    }

    function alignDirectContentContainer(container, entry) {
        if (!container || !container.style || !entry || !placementIsInContent(entry.placement, placementFormatSettings(entry.placement))) return;
        var style = container.style;

        // GPT chooses the final fixed/fluid width asynchronously. Keep the
        // provider-owned child centered even after that late width mutation.
        setImportantStyle(style, 'align-self', 'center');
        setImportantStyle(style, 'margin-left', 'auto');
        setImportantStyle(style, 'margin-right', 'auto');
        setImportantStyle(style, 'max-width', '100%');
        setImportantStyle(style, 'box-sizing', 'border-box');
    }

    function placementRendered(element) {
        if (!element || !element.getAttribute) return false;
        return String(element.getAttribute('data-hm-status') || '').toLowerCase() === 'rendered';
    }

    function destroyPlacementMedia(element) {
        if (!element) return;
        var nodes = [element];
        if (element.querySelectorAll) {
            Array.prototype.forEach.call(element.querySelectorAll('[data-hm-video-direct="1"], [data-hm-isolated-direct="1"], video, iframe'), function (node) {
                if (nodes.indexOf(node) === -1) nodes.push(node);
            });
        }
        nodes.forEach(function (node) {
            try { if (typeof node.__hmDestroy === 'function') node.__hmDestroy('dismissed'); } catch (error) {}
            try { if (node.pause) node.pause(); } catch (error) {}
            if (String(node.tagName || '').toLowerCase() === 'iframe') {
                try { node.src = 'about:blank'; } catch (error) {}
            }
        });
        nodes.slice(1).forEach(function (node) {
            try { if (node.parentNode && node.parentNode.removeChild) node.parentNode.removeChild(node); } catch (error) {}
        });
    }

    function syncPlacementCloseControl(element, button) {
        if (!button || !button.style) return;
        var visible = placementRendered(element);
        setImportantStyle(button.style, 'display', visible ? 'block' : 'none');
        if (button.setAttribute) button.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function ensurePlacementCloseControl(element, settings) {
        if (!element || !element.appendChild || !document.createElement || !settings || settings.closeable !== true) return;
        if (element.getAttribute && element.getAttribute('data-hm-placement-dismissed') === '1') return;
        if (element.querySelector && element.querySelector('[data-hm-placement-close="1"]')) return;
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = '×';
        button.setAttribute('aria-label', 'Close advertisement');
        button.setAttribute('aria-hidden', 'true');
        button.setAttribute('data-hm-placement-close', '1');
        button.style.cssText = 'display:none;position:absolute;top:4px;right:4px;z-index:2147483001;width:28px;height:28px;min-width:28px;min-height:28px;max-width:28px;max-height:28px;box-sizing:border-box;padding:0;border:0;border-radius:999px;background:rgba(0,0,0,.72);color:#fff;font:20px/28px sans-serif;overflow:hidden;cursor:pointer;';
        setImportantStyle(button.style, 'display', 'none');
        setImportantStyle(button.style, 'position', 'absolute');
        setImportantStyle(button.style, 'top', settings.closeOutside === true ? '-32px' : '4px');
        setImportantStyle(button.style, 'right', '4px');
        setImportantStyle(button.style, 'z-index', '2147483001');
        setImportantStyle(button.style, 'left', 'auto');
        setImportantStyle(button.style, 'bottom', 'auto');
        setImportantStyle(button.style, 'transform', 'none');
        setImportantStyle(button.style, 'margin', '0');
        setImportantStyle(button.style, 'box-sizing', 'border-box');
        setImportantStyle(button.style, 'width', '28px');
        setImportantStyle(button.style, 'height', '28px');
        setImportantStyle(button.style, 'min-width', '28px');
        setImportantStyle(button.style, 'min-height', '28px');
        setImportantStyle(button.style, 'max-width', '28px');
        setImportantStyle(button.style, 'max-height', '28px');
        setImportantStyle(button.style, 'padding', '0');
        setImportantStyle(button.style, 'line-height', '28px');
        setImportantStyle(button.style, 'overflow', 'hidden');
        button.addEventListener('click', function (event) {
            if (event && event.preventDefault) event.preventDefault();
            if (event && event.stopPropagation) event.stopPropagation();
            if (button.__hmPlacementObserver && button.__hmPlacementObserver.disconnect) button.__hmPlacementObserver.disconnect();
            if (element.setAttribute) element.setAttribute('data-hm-placement-dismissed', '1');
            destroyPlacementMedia(element);
            if (element.style) setImportantStyle(element.style, 'display', 'none');
        });
        element.appendChild(button);
        syncPlacementCloseControl(element, button);
        if (typeof window.MutationObserver === 'function') {
            var observer = new window.MutationObserver(function () { syncPlacementCloseControl(element, button); });
            observer.observe(element, { attributes: true, attributeFilter: ['data-hm-status'] });
            button.__hmPlacementObserver = observer;
        }
    }

    function attachDirectResponsiveMapping(container, entry) {
        if (!container || !container.getAttribute || !container.setAttribute || !entry || !entry.placement) return;
        if (container.getAttribute('data-hm-gpt-direct') !== '1') return;
        var mappings = entry.placement.responsiveMappings;
        if (!Array.isArray(mappings) || !mappings.length) return;
        try { container.setAttribute('data-hm-gpt-size-map', JSON.stringify(mappings)); }
        catch (error) { /* fail safely: the GPT runtime will use its declared-size fallback */ }
    }

    function applyPlacementPresetPresentation(element, placement, settings) {
        settings = settings || placementFormatSettings(placement);
        applyContentAlignment(element, placement, settings);
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

    if (!source.includes('attachDirectResponsiveMapping(container, entry);')) {
        if (!source.includes(DIRECT_CONTAINER_ANCHOR)) {
            throw new Error('Unable to locate Direct Demand container anchor for responsive GPT mapping');
        }
        source = source.replace(DIRECT_CONTAINER_ANCHOR, `        setCandidateAttributes(container, recipe.attributes || tag.attributes || {});\n        attachDirectResponsiveMapping(container, entry);\n        alignDirectContentContainer(container, entry);\n        if (entry.element.appendChild) entry.element.appendChild(container);`);
    }

    return source;
}
