import test from 'node:test';
import assert from 'node:assert/strict';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';

const fixture = `
(function () {
    function eligibleElements(config) {
        var nodes = [];
        return nodes;
    }
    function renderPlacement(element, placement, formatSettings) {
                            if (formatSettings.position && element.style && placement.type === 'STICKY') {
                                element.style.position = 'fixed'; element.style.zIndex = '2147483000'; element.style.left = '50%'; element.style.transform = 'translateX(-50%)';
                                element.style[formatSettings.position === 'top' ? 'top' : 'bottom'] = '0';
                            }
    }
})();
`;

test('placement preset transform injects safe auto-mount targets and edge positioning exactly once', () => {
    const transformed = applyPlacementPresetTransform(fixture);
    assert.match(transformed, /function autoMountPlacementElements\(config\)/);
    assert.match(transformed, /function mountAutoPlacementElement\(element, settings\)/);
    assert.match(transformed, /article_mid/);
    assert.match(transformed, /article_end/);
    assert.match(transformed, /bottom_right/);
    assert.match(transformed, /style\.right = '0'/);
    assert.match(transformed, /applyStickyPosition\(element, placement, formatSettings\);/);
    assert.doesNotMatch(transformed, /formatSettings\.position === 'top' \? 'top' : 'bottom'/);

    const twice = applyPlacementPresetTransform(transformed);
    assert.equal(twice, transformed);
    assert.equal((twice.match(/function autoMountPlacementElements\(config\)/g) || []).length, 1);
});
