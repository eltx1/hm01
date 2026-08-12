import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

test('standalone renderer is strictly gated to STANDALONE delivery mode', () => {
    const standaloneRequest = loaderSource.indexOf('function requestStandaloneEntry(config, entry)');
    const modeGate = loaderSource.indexOf("prebid.deliveryMode !== 'STANDALONE'", standaloneRequest);
    const renderDispatch = loaderSource.indexOf('standaloneRenderFrame(config, entry, pbjs, winner)', modeGate);
    const renderHelper = loaderSource.indexOf('function standaloneRenderFrame(config, entry, pbjs, winner)');
    const renderCall = loaderSource.indexOf('pbjs.renderAd(iframe.contentWindow.document, winner.adId)', renderHelper);

    assert.ok(standaloneRequest >= 0, 'Standalone request path must exist.');
    assert.ok(modeGate > standaloneRequest, 'Standalone request path must fail closed outside STANDALONE mode.');
    assert.ok(renderDispatch > modeGate, 'Winner dispatch must remain behind the standalone mode gate.');
    assert.ok(renderHelper >= 0 && renderCall > renderHelper, 'The isolated standalone helper must own renderAd.');
});

test('GAM bridge keeps targeting then GAM refresh behavior', () => {
    const bridgeRequest = loaderSource.indexOf('function requestEntries(config, googletag, pubads, entries)');
    const targeting = loaderSource.indexOf('pbjs.setTargetingForGPTAsync(codes)', bridgeRequest);
    const gamRequest = loaderSource.indexOf('requestGam(config, pubads, entries)', targeting);

    assert.ok(bridgeRequest >= 0, 'GAM bridge request path must remain present.');
    assert.ok(targeting > bridgeRequest, 'GAM bridge must set Prebid targeting for GPT.');
    assert.ok(gamRequest > targeting, 'GAM request must occur only after Prebid targeting.');
});

test('pure standalone placements complete before GPT initialization', () => {
    const defineItems = loaderSource.indexOf('function defineItems(config, items)');
    const partition = loaderSource.indexOf("item.placement.renderer === 'PREBID_STANDALONE'", defineItems);
    const noGamReturn = loaderSource.indexOf('if (!gamItems.length)', partition);
    const loadGpt = loaderSource.indexOf('return loadGpt(config)', noGamReturn);

    assert.ok(partition >= 0, 'Standalone placements must be partitioned explicitly.');
    assert.ok(noGamReturn > partition, 'No-GAM pages must return through independent engines before GPT.');
    assert.ok(loadGpt > noGamReturn, 'GPT may initialize only after a real GAM placement exists.');
});

test('repeated containers use a Loader instance sequence rather than the GAM slot count', () => {
    const ensureId = loaderSource.indexOf('function ensureElementId(element, config, placement)');
    const nextFunction = loaderSource.indexOf('function nativeDefinition', ensureId);
    const implementation = loaderSource.slice(ensureId, nextFunction);

    assert.match(implementation, /state\.elementSequence/);
    assert.doesNotMatch(implementation, /Object\.keys\(state\.slots\)\.length/);
    assert.match(loaderSource, /state\.elementSequence = 0;/);
});
