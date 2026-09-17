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
    assert.match(transformed, /article_mid/);
    assert.match(transformed, /article_end/);
    assert.match(transformed, /bottom_right/);
    assert.match(transformed, /function setImportantStyle\(style, name, value\)/);
    assert.match(transformed, /style\.setProperty\(name, value, 'important'\)/);
    assert.match(transformed, /function resetPositionStyle\(style, name\)/);
    assert.match(transformed, /name === 'transform' \? 'none' : 'auto'/);
    assert.match(transformed, /setImportantStyle\(style, 'position', 'fixed'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'z-index', '2147483000'\)/);
    assert.match(transformed, /setImportantStyle\(style, 'margin', '0'\)/);
    assert.match(transformed, /function ensurePlacementCloseControl\(element, settings\)/);
    assert.match(transformed, /data-hm-placement-close/);
    assert.match(transformed, /Close advertisement/);
    assert.match(transformed, /display:none;position:absolute/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'display', 'none'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'position', 'absolute'\)/);
    assert.match(transformed, /setImportantStyle\(button\.style, 'top', '4px'\)/);
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
    assert.equal((twice.match(/function ensurePlacementCloseControl\(element, settings\)/g) || []).length, 1);
    assert.equal((twice.match(/function attachDirectResponsiveMapping\(container, entry\)/g) || []).length, 1);
    assert.equal((twice.match(/applyPlacementPresetPresentation\(item\.element, item\.placement, placementFormatSettings\(item\.placement\)\);/g) || []).length, 1);
    assert.equal((twice.match(/attachDirectResponsiveMapping\(container, entry\);/g) || []).length, 1);
});
