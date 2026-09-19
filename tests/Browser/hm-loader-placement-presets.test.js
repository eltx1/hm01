import test from 'node:test';
import assert from 'node:assert/strict';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';

const fixture = `
(function () {
    function eligibleElements(config) {
        var nodes = [];
        return nodes;
    }
    function defineItems(config, items) {
        var nativeOnly = items || [];
        nativeOnly.forEach(function (item) {
            ensureElementId(item.element, config, item.placement);
            item.element.setAttribute('data-hm-defined', '1');
            item.element.setAttribute('data-hm-status', 'direct-demand');
        });
    }
    function directContainer(entry, candidate) {
        var tag = candidate.tag || {};
        var recipe = tag.container || {};
        var container = document.createElement('div');
        setCandidateAttributes(container, recipe.attributes || tag.attributes || {});
        if (entry.element.appendChild) entry.element.appendChild(container);
        return container;
    }
    function renderPlacement(element, placement, formatSettings) {
                            if (formatSettings.position && element.style && placement.type === 'STICKY') {
                                element.style.position = 'fixed'; element.style.zIndex = '2147483000'; element.style.left = '50%'; element.style.transform = 'translateX(-50%)';
                                element.style[formatSettings.position === 'top' ? 'top' : 'bottom'] = '0';
                            }
    }
})();
`;

test('placement preset transform injects safe auto-mount, hardened surface ownership, and responsive Direct GPT mapping exactly once', () => {
    const transformed = applyPlacementPresetTransform(fixture);
    assert.match(transformed, /function autoMountPlacementElements\(config\)/);
    assert.match(transformed, /function mountAutoPlacementElement\(element, settings\)/);
    assert.match(transformed, /\[itemprop="articleBody"\]/);
    assert.match(transformed, /\.entry-content/);
    assert.match(transformed, /\.post-content/);
    assert.match(transformed, /\.article-content/);
    assert.match(transformed, /data-hm-content-root/);
    assert.match(transformed, /\[role="main"\]/);
    assert.match(transformed, /\.site-main/);
    assert.match(transformed, /\.main-content/);
    assert.match(transformed, /#primary/);
    assert.match(transformed, /content_mid/);
    assert.match(transformed, /content_end/);
    assert.match(transformed, /article_mid/);
    assert.match(transformed, /article_end/);
    assert.match(transformed, /function contentMountChildren\(content\)/);
    assert.match(transformed, /Do not guess a "middle"/);
    assert.match(transformed, /bottom_right/);
    assert.match(transformed, /data-hm-floating-video-active/);
    assert.match(transformed, /settings\.singleActiveVideo !== false/);
    assert.match(transformed, /function setImportantStyle\(style, name, value\)/);
    assert.match(transformed, /style\.setProperty\(name, value, 'important'\)/);
    assert.match(transformed, /function resetPositionStyle\(style, name\)/);
    assert.match(transformed, /name === 'transform' \? 'none' : 'auto'/);
    assert.match(transformed, /setImportantStyle\(style, 'position', 'fixed'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'z-index', '2147483000'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'margin', '0'\)/);
    assert.match(transformed, /function placementIsInContent\(placement, settings\)/);
    assert.match(transformed, /settings\.contentPosition/);
    assert.match(transformed, /settings\.surface && settings\.surface\.mount/);
    assert.match(transformed, /formatCode === 'display_in_article'/);
    assert.match(transformed, /function applyContentAlignment\(element, placement, settings\)/);
    assert.match(transformed, /contentTargets\.indexOf\(target\)/);
    assert.match(transformed, /contentTargets\.indexOf\(contentPosition\)/);
    assert.match(transformed, /contentTargets\.indexOf\(surfaceMount\)/);
    assert.match(transformed, /setImportantStyle\(style, 'display', 'flex'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'flex-direction', 'column'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'align-items', 'center'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'width', '100%'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'margin-left', 'auto'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'margin-right', 'auto'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'text-align', 'center'\)/);
    assert.match(transformed, /function alignDirectContentContainer\(container, entry\)/);
    assert.match(transformed, /setImportantStyle\(style, 'align-self', 'center'\)/);
    assert.match(transformed, /alignDirectContentContainer\(container, entry\);/);
    assert.match(transformed, /function ensurePlacementCloseControl\(element, settings\)/);
    assert.match(transformed, /data-hm-placement-close/);
    assert.match(transformed, /Close advertisement/);
    assert.match(transformed, /display:none;position:absolute/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'display', 'none'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'position', 'absolute'\)/);
    assert.match(transformed, /settings\.closeOutside === true \? '-32px' : '4px'/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'right', '4px'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'left', 'auto'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'bottom', 'auto'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'transform', 'none'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'box-sizing', 'border-box'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'width', '28px'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'height', '28px'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'min-width', '28px'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'min-height', '28px'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'max-width', '28px'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'max-height', '28px'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'padding', '0'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'line-height', '28px'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'overflow', 'hidden'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'display', visible \? 'block' : 'none'\)/);
    assert.match(transformed, /function placementRendered\(element\)/);
    assert.match(transformed, /attributeFilter: \['data-hm-status'\]/);
    assert.match(transformed, /data-hm-placement-dismissed/);
    assert.match(transformed, /function destroyPlacementMedia\(element\)/);
    assert.match(transformed, /node\.__hmDestroy\('dismissed'\)/);
    assert.match(transformed, /node\.pause\(\)/);
    assert.match(transformed, /node\.src = 'about:blank'/);
    assert.match(transformed, /calc\(16px \+ env\(safe-area-inset-bottom, 0px\)\)/);
    assert.match(transformed, /min\(400px, calc\(100vw - 32px\)\)/);
    assert.match(transformed, /setImportantStyle\(style, 'aspect-ratio', '16 \/ 9'\)/);
    assert.match(transformed, /function attachDirectResponsiveMapping\(container, entry\)/);
    assert.match(transformed, /data-hm-gpt-size-map/);
    assert.match(transformed, /JSON\.stringify\(mappings\)/);
    assert.match(transformed, /applyPlacementPresetPresentation\(element, placement, formatSettings\);/);
    assert.match(transformed, /applyPlacementPresetPresentation\(item\.element, item\.placement, placementFormatSettings\(item\.placement\)\);/);
    assert.match(transformed, /mountAutoPlacementElement\(element, settings\);\n            applyPlacementPresetPresentation\(element, placement, settings\);/);
    assert.match(transformed, /attachDirectResponsiveMapping\(container, entry\);/);
    assert.doesNotMatch(transformed, /formatSettings\.position === 'top' \? 'top' : 'bottom'/);

    const twice = applyPlacementPresetTransform(transformed);
    assert.equal(twice, transformed);
    assert.equal((twice.match(/function autoMountPlacementElements\(config\)/g) || []).length, 1);
    assert.equal((twice.match(/function placementIsInContent\(placement, settings\)/g) || []).length, 1);
    assert.equal((twice.match(/function applyContentAlignment\(element, placement, settings\)/g) || []).length, 1);
    assert.equal((twice.match(/function alignDirectContentContainer\(container, entry\)/g) || []).length, 1);
    assert.equal((twice.match(/function ensurePlacementCloseControl\(element, settings\)/g) || []).length, 1);
    assert.equal((twice.match(/function destroyPlacementMedia\(element\)/g) || []).length, 1);
    assert.equal((twice.match(/function attachDirectResponsiveMapping\(container, entry\)/g) || []).length, 1);
    assert.equal((twice.match(/applyPlacementPresetPresentation\(item\.element, item\.placement, placementFormatSettings\(item\.placement\)\);/g) || []).length, 1);
    assert.equal((twice.match(/attachDirectResponsiveMapping\(container, entry\);/g) || []).length, 1);
    assert.equal((twice.match(/alignDirectContentContainer\(container, entry\);/g) || []).length, 1);
});
