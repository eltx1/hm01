// Fetch approved Quick Monetize static bytes, never execute them before admission.
const MARKER = 'function prepareTrustedDirectAssets(config, controls)';
const HELPER = String.raw`
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
                    addPreparationHint('preload', 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', 'script');
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
    // AFTER the existing host, site, control, gate validity and privacy boundary.
    return source.replace(point, '        prepareTrustedDirectAssets(config, controls);\n' + point);
}
