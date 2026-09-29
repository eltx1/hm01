// Fetch approved Quick Monetize static bytes, never execute them before admission.
const MARKER = 'function prepareTrustedDirectAssets(config, controls)';
const HELPER = String.raw`
    function startTrustedDirectDependencies(config, candidate) {
        // Called only when a candidate starts, after admission and its own lazy
        // eligibility. Preparing bytes before admission remains a separate path.
        if (!candidate || !directJsServingAllowed(config)
            || !hostAllowed(currentHostname(), config.allowedHostnames)) return;
        var tag = candidate.tag || {};
        if (candidate.gamManaged || String(tag.executionMode || 'STRUCTURED') !== 'STRUCTURED') return;
        var attrs = tag.container && tag.container.attributes || tag.attributes || {};
        var specs = directScriptSpecs(tag);
        if (String(attrs['data-hm-gpt-direct'] || '') !== '1' || specs.length !== 1) return;
        // Preserve ordered/custom provider initialization without reordering it.
        if (String(tag.initialization && tag.initialization.type || 'NONE').toUpperCase() !== 'NONE'
            || specs[0].async === false || specs[0].defer) return;
        try {
            var runtime = new URL(String(specs[0].url || ''));
            if (runtime.protocol !== 'https:' || runtime.hostname !== 'cdn.horusmedia.net'
                || runtime.username || runtime.password || runtime.port || runtime.search || runtime.hash
                || !/^\/runtime\/gpt\/hm-gpt-direct\.[a-f0-9]{16}\.js$/.test(runtime.pathname)) return;
        } catch (error) { return; }
        var url = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
        // Never substitute a standard SDK for manually selected limited ads.
        if (config.gpt && config.gpt.url && config.gpt.url !== url) return;
        if (window.googletag && (window.googletag.apiReady || window.googletag.pubadsReady)) return;
        if (state.gptPromise) return;
        var existing = Array.prototype.some.call(nodeList('script[src]'), function (script) {
            try {
                var src = new URL(script.src || script.getAttribute('src'), window.location.href);
                return src.pathname === '/tag/js/gpt.js' &&
                    ['securepubads.g.doubleclick.net', 'pagead2.googlesyndication.com'].indexOf(src.hostname) !== -1;
            } catch (error) { return false; }
        });
        if (existing) return;
        ensureGoogletagQueue();
        // Start GPT and its internal dependency alongside the Horus adapter.
        // Do not await this promise, video readiness, or another placement.
        // The adapter retains slot creation; GAM reuses this same promise.
        state.gptPromise = loadExternalScript('script[data-hm-gpt="1"]', 'data-hm-gpt', url, 'anonymous')
            .then(function () { return ensureGoogletagQueue(); });
        state.gptPromise.catch(function (error) {
            // Handle optional overlap failure without rejecting sibling Direct
            // candidates; the original rejection remains available to GAM.
            log(config, 'Independent GPT dependency failed', error);
        });
        // Retain the existing Direct marker while sharing the GAM owner marker.
        var owned = document.querySelector && document.querySelector('script[data-hm-gpt="1"]');
        if (owned && owned.setAttribute) owned.setAttribute('data-hm-gpt-library', '1');
    }

    function prepareTrustedDirectAssets(config, controls) {
        if (controls.directJsDisabled) return;
        var demand = config.directDemand || config.nativeDemand || {};
        if (!demand.enabled || !demand.placements) return;
        var prepared = {};
        (config.placements || []).forEach(function (placement) {
            if (!placement || !placement.enabled || placement.status !== 'active'
                || placement.adUnitPath || placement.renderer !== 'DIRECT_JS') return;
            var entry = demand.placements[placement.code];
            if (!entry || !entry.enabled || !Array.isArray(entry.candidates)) return;
            entry.candidates.slice(0, 20).some(function (candidate) {
                var tag = candidate && candidate.tag;
                if (!tag || candidate.gamManaged || String(tag.executionMode || 'STRUCTURED') !== 'STRUCTURED') return false;
                var attrs = tag.container && tag.container.attributes || tag.attributes || {};
                var kind = String(attrs['data-hm-gpt-direct'] || '') === '1' ? 'gpt'
                    : String(attrs['data-hm-video-direct'] || '') === '1' ? 'video' : null;
                if (!kind) return false;
                if (prepared[kind]) return true;
                var approvedUrl = null;
                directScriptSpecs(tag).slice(0, 5).some(function (spec) {
                    try {
                        var url = new URL(String(spec.url || ''));
                        var path = new RegExp('^/runtime/' + kind + '/hm-' + kind + '-direct\\.[a-f0-9]{16}\\.js$');
                        if (url.protocol !== 'https:' || url.hostname !== 'cdn.horusmedia.net'
                            || url.username || url.password || url.port || url.search || url.hash || !path.test(url.pathname)) return false;
                        approvedUrl = url.href;
                        return true;
                    } catch (error) { return false; }
                });
                if (!approvedUrl) return false;
                prepared[kind] = true;
                addPreparationHint('preload', approvedUrl, 'script');
                if (kind === 'gpt' && !(window.googletag && (window.googletag.apiReady || window.googletag.pubadsReady))) {
                    // The GPT adapter consumes an anonymous-CORS script, not a classic no-CORS preload.
                    addPreparationHint('preload', 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', 'script', 'anonymous');
                }
                if (kind === 'video' && !(window.google && window.google.ima && window.google.ima.AdsLoader)) {
                    addPreparationHint('preload', 'https://imasdk.googleapis.com/js/sdkloader/ima3.js', 'script');
                }
                return true;
            });
        });
    }

`;

export function applyDirectPreparationTransform(input) {
    let source = String(input);
    if (source.includes(MARKER)) return source;
    const declaration = '    function prepareStaticConnections(config, privacyReady) {';
    const point = '        if (controls.gamDisabled || (window.googletag && (window.googletag.apiReady || window.googletag.pubadsReady))) return;';
    if (!source.includes(declaration) || !source.includes(point)) {
        throw new Error('Trusted Direct preparation requires the reviewed Traffic Gate preparation boundary');
    }
    source = source.replace(declaration, () => HELPER + declaration);
    const start = '    function loadDirectScripts(config, candidate) {';
    if (!source.includes(start)) throw new Error('Trusted Direct startup requires the actual candidate boundary');
    source = source.replace(start, start + '\n        try { startTrustedDirectDependencies(config, candidate); }\n        catch (error) { log(config, \'Independent dependency preparation failed\', error); }');
    // AFTER the existing host, site, control, gate validity and privacy boundary.
    return source.replace(point, '        prepareTrustedDirectAssets(config, controls);\n' + point);
}
