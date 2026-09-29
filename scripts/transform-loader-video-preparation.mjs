// Record actual inline layout before gate/SDK readiness. This is geometry only:
// no script execution, media fetch, ad request, persistent state or authorization.
const MARKER = 'function prepareInlineVideoHistory(config)';
const RUNTIME = String.raw`
    function stopInlineVideoPreparation() {
        var prep = state.inlineVideoPreparation;
        if (!prep) return;
        state.inlineVideoPreparation = null;
        prep.stopped = true;
        if (prep.observer) prep.observer.disconnect();
        if (prep.resize) prep.resize.disconnect();
        if (prep.frame !== null) {
            if (window.cancelAnimationFrame) window.cancelAnimationFrame(prep.frame);
            else window.clearTimeout(prep.frame);
        }
        window.clearTimeout(prep.expiry);
        if (window.removeEventListener) {
            window.removeEventListener('scroll', prep.schedule, true);
            window.removeEventListener('resize', prep.schedule);
            window.removeEventListener('pagehide', prep.stop);
        }
        if (document.removeEventListener) document.removeEventListener('visibilitychange', prep.schedule);
        if (window.visualViewport && window.visualViewport.removeEventListener) {
            window.visualViewport.removeEventListener('resize', prep.schedule);
            window.visualViewport.removeEventListener('scroll', prep.schedule);
        }
        prep.records.slice().forEach(function (record) { record.release(); });
    }

    function prepareInlineVideoHistory(config) {
        var controls = normalizeControls(config && config.controls || {});
        if (config && (!hostAllowed(currentHostname(), config.allowedHostnames)
            || config.status !== 'active' || config.immediatePause || controls.adServingDisabled || controls.directJsDisabled)) {
            stopInlineVideoPreparation(); return;
        }
        var prep = state.inlineVideoPreparation;
        if (!config && prep) return;
        // Refetch and release handoff can parse a new object for the SAME
        // snapshot. Retain only layout history in that case; a real revision
        // invalidates it even if the caller mutates the original object.
        var snapshot = config ? JSON.stringify(config) : null;
        if (prep && prep.snapshot && prep.snapshot !== snapshot) { stopInlineVideoPreparation(); prep = null; }
        var eligible = config ? Object.create(null) : null;
        (config && config.placements || []).forEach(function (placement) {
            var settings = placementFormatSettings(placement);
            if (placement && placement.type === 'VIDEO' && placement.renderer === 'DIRECT_JS'
                && placement.enabled && placement.status === 'active' && placementViewportAllowed(settings)
                && (settings.position === 'inline_to_bottom_right' || settings.floatingPosition === 'bottom_right')) {
                eligible[String(placement.code)] = placement;
            }
        });
        if (config && !Object.keys(eligible).length) { stopInlineVideoPreparation(); return; }
        if (!document.querySelectorAll) return;
        if (prep) { prep.config = config; prep.snapshot = snapshot; prep.eligible = eligible; prep.discover(); return; }
        prep = state.inlineVideoPreparation = { config: config, snapshot: snapshot, eligible: eligible, records: [], frame: null, stopped: false, discovering: false };
        prep.stop = function () { if (state.inlineVideoPreparation === prep) stopInlineVideoPreparation(); };
        function geometry(node) {
            if (!node || !node.getBoundingClientRect || node.isConnected === false || document.visibilityState === 'hidden') return null;
            var rect = node.getBoundingClientRect(), visual = window.visualViewport;
            var top = Number(visual && visual.offsetTop || 0), left = Number(visual && visual.offsetLeft || 0);
            var bottom = top + Number(visual && visual.height || window.innerHeight || 0);
            var right = left + Number(visual && visual.width || window.innerWidth || 0);
            if (!(rect.width > 0 && rect.height >= 0 && bottom > top && right > left)) return null;
            // A collapsed insertion point can have scroll history without a
            // reserved rectangle. This is anchor geometry, not ad viewability.
            var anchor = rect.height <= 1;
            var measuredHeight = anchor ? 1 : rect.height;
            var clip = { top: Math.max(top, rect.top), bottom: Math.min(bottom, anchor ? rect.top + 1 : rect.bottom), left: Math.max(left, rect.left), right: Math.min(right, rect.right) };
            if (window.getComputedStyle) {
                for (var parent = node, depth = 0; parent && parent.nodeType === 1 && depth < 64; parent = parent.parentElement, depth++) {
                    var css = window.getComputedStyle(parent);
                    if (css.display === 'none' || css.visibility === 'hidden' || css.opacity === '0') return null;
                    if (parent !== node && parent !== document.body && parent !== document.documentElement && parent.getBoundingClientRect) {
                        var bounds = parent.getBoundingClientRect();
                        if (/(auto|scroll|hidden|clip)/.test(css.overflowX)) { clip.left = Math.max(clip.left, bounds.left); clip.right = Math.min(clip.right, bounds.right); }
                        if (/(auto|scroll|hidden|clip)/.test(css.overflowY)) { clip.top = Math.max(clip.top, bounds.top); clip.bottom = Math.min(clip.bottom, bounds.bottom); }
                    }
                }
            }
            return { anchor: anchor, ratio: Math.min(1, Math.max(0, clip.right - clip.left) * Math.max(0, clip.bottom - clip.top) / (rect.width * measuredHeight)) };
        }
        function recordNode(node, code) {
            var record = { node: node, wasInlineVisible: false, anchorWasVisible: false, scrolled: false, released: false,
                scrollY: Number(window.scrollY || window.pageYOffset || 0), scrollX: Number(window.scrollX || window.pageXOffset || 0) };
            // Preparation never changes publisher layout or reserves empty ad
            // space. The live player owns its dimensions after admission.
            record.sample = function () {
                if (record.released) return;
                var placement = prep.eligible && prep.eligible[code];
                if (node.isConnected === false || node.getAttribute('data-hm-placement-dismissed') === '1'
                    || node.getAttribute('data-placement') !== code || prep.eligible && !placement) { record.release(); return; }
                var knownEmbed = !prep.config && code === 'quick_video_floating';
                if ((record.wasInlineVisible || record.anchorWasVisible) && (Number(window.scrollY || window.pageYOffset || 0) !== record.scrollY
                    || Number(window.scrollX || window.pageXOffset || 0) !== record.scrollX)) record.scrolled = true;
                var measurement = geometry(node);
                if (measurement && measurement.ratio >= 0.5) {
                    if (!measurement.anchor) record.wasInlineVisible = true;
                    else if (knownEmbed || placement) record.anchorWasVisible = true;
                }
            };
            record.release = function () {
                if (record.released) return;
                record.released = true;
                if (node.__hmInlineVideoHistory === record) delete node.__hmInlineVideoHistory;
                if (prep.resize && prep.resize.unobserve) prep.resize.unobserve(node);
                var index = prep.records.indexOf(record);
                if (index !== -1) prep.records.splice(index, 1);
            };
            record.take = function () {
                record.sample();
                var value = record.released || !prep.config || !prep.eligible[code] || state.inlineVideoPreparation !== prep || prep.stopped ? null
                    : { wasInlineVisible: record.wasInlineVisible, scrolled: record.scrolled };
                if (value && record.anchorWasVisible) value.anchorWasVisible = true;
                record.release();
                if (!prep.records.length && prep.config) prep.stop();
                return value;
            };
            node.__hmInlineVideoHistory = record;
            prep.records.push(record);
            if (prep.resize) prep.resize.observe(node);
            record.sample();
        }
        prep.discover = function () {
            if (prep.stopped || prep.discovering) return;
            prep.discovering = true;
            try {
                prep.records.slice().forEach(function (record) { record.sample(); });
                Array.prototype.forEach.call(nodeList('.hm-ad[data-placement], .hm-native[data-placement]'), function (node) {
                    var code = node.getAttribute('data-placement');
                    if (!code || prep.eligible && !prep.eligible[code] || prep.records.length >= 32 || !node.getBoundingClientRect || node.__hmInlineVideoHistory
                        || node.getAttribute('data-hm-placement-dismissed') === '1'
                        || node.getAttribute('data-hm-video-floating-state') === 'floating'
                        || node.querySelector && node.querySelector('[data-hm-video-runtime-state]')) return;
                    recordNode(node, code);
                });
            } finally { prep.discovering = false; }
        };
        prep.schedule = function (event) {
            if (prep.stopped) return;
            if (event && event.type === 'scroll') prep.records.forEach(function (r) { if (r.wasInlineVisible || r.anchorWasVisible) r.scrolled = true; });
            if (prep.frame !== null) return;
            prep.frame = window.requestAnimationFrame ? window.requestAnimationFrame(function () { prep.frame = null; prep.discover(); })
                : window.setTimeout(function () { prep.frame = null; prep.discover(); }, 16);
        };
        if (typeof window.ResizeObserver === 'function') prep.resize = new window.ResizeObserver(prep.schedule);
        if (typeof window.MutationObserver === 'function' && document.documentElement) {
            prep.observer = new window.MutationObserver(prep.schedule);
            prep.observer.observe(document.documentElement, { childList: true, subtree: true });
        }
        if (window.addEventListener) {
            window.addEventListener('scroll', prep.schedule, { passive: true, capture: true });
            window.addEventListener('resize', prep.schedule, { passive: true });
            window.addEventListener('pagehide', prep.stop);
        }
        if (document.addEventListener) document.addEventListener('visibilitychange', prep.schedule);
        if (window.visualViewport && window.visualViewport.addEventListener) {
            window.visualViewport.addEventListener('scroll', prep.schedule, { passive: true });
            window.visualViewport.addEventListener('resize', prep.schedule, { passive: true });
        }
        prep.expiry = window.setTimeout(prep.stop, 120000);
        prep.discover();
    }

`;

export function applyVideoPreparationTransform(input) {
    let source = String(input);
    if (source.includes(MARKER)) return source;
    const hook = '    function prepareStaticConnections(config, privacyReady) {';
    const reset = '            resetClickGuardRuntime();';
    if (!source.includes(hook) || !source.includes(reset)) throw new Error('Video preparation requires actual Loader boot and reset boundaries');
    source = source.replace(hook, () => RUNTIME + hook + '\n        try { prepareInlineVideoHistory(config); } catch (error) { stopInlineVideoPreparation(); }');
    source = source.replace(reset, '        stopInlineVideoPreparation();\n' + reset);
    // A rejected gate is authoritative; remove geometry-only preparation.
    const deny = "        trafficGateSetState(TRAFFIC_GATE_STATES.blocked, reason || 'DENIED');";
    if (!source.includes(deny)) throw new Error('Video preparation requires explicit denial boundary');
    source = source.replace(deny, '        stopInlineVideoPreparation();\n' + deny);
    // Observe already-present publisher slots while configuration is in flight.
    // Unknown zero-area slots never gain anchor eligibility before config.
    const early = '        if (!siteKey || !window.fetch || earlyBootPreparation || state.booting) return;';
    const boot = '        if (state.booting && !options.force) return state.booting;';
    if (!source.includes(early) || !source.includes(boot)) throw new Error('Missing early and ordinary boot boundaries');
    [early, boot].forEach(function (hook) {
        source = source.replace(hook, hook + '\n        try { prepareInlineVideoHistory(null); } catch (error) { stopInlineVideoPreparation(); }');
    });
    return source;
}
