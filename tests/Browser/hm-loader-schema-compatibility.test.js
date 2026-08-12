import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

test('permanent loader remains schema-v2 compatible during additive schema-v3 rollout', () => {
    // The permanent Loader validates site/version identity, not an exact schema
    // number. Existing immutable schema-v2 configs therefore remain readable
    // while schema-v3 adds engine metadata alongside the legacy fields.
    assert.match(loaderSource, /function validateConfig\(config, siteKey, expectedVersion\)/);
    assert.match(loaderSource, /config\.siteKey !== siteKey/);
    assert.match(loaderSource, /Number\(config\.configVersion\) !== Number\(expectedVersion\)/);
    assert.doesNotMatch(loaderSource, /schemaVersion\s*!==?\s*2/);
    assert.doesNotMatch(loaderSource, /schemaVersion\s*!==?\s*3/);
});

test('GPT stays gated by physical GAM placement ownership rather than site-level GAM metadata', () => {
    const partition = loaderSource.indexOf("var nativeOnly = items.filter(function (item) { return !item.placement.adUnitPath; });");
    const noGamReturn = loaderSource.indexOf('if (!gamItems.length) return nativePromise', partition);
    const loadGpt = loaderSource.indexOf('return loadGpt(config)', partition);

    assert.ok(partition >= 0, 'Loader must partition placements by adUnitPath.');
    assert.ok(noGamReturn > partition, 'No-GAM placements must short-circuit before GPT initialization.');
    assert.ok(loadGpt > noGamReturn, 'GPT must load only after confirming at least one GAM-owned placement.');
});

test('standalone Prebid is not silently routed through the legacy GAM bridge path', () => {
    // Task 14 intentionally does not add direct winning-bid rendering. The
    // bridge request path still works exclusively with GPT slot entries, while
    // the schema-v3 builder keeps its legacy prebid.enabled flag false for
    // STANDALONE. This source invariant prevents a premature implicit bridge.
    assert.match(loaderSource, /function requestEntries\(config, googletag, pubads, entries\)/);
    assert.match(loaderSource, /pbjs\.setTargetingForGPTAsync/);
    assert.match(loaderSource, /requestGam\(config, pubads, entries\)/);
    assert.doesNotMatch(loaderSource, /renderAd\(document/);
});
