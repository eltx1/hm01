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
    // Task 15 partitions all three renderer families explicitly. The decisive
    // GAM invariant is still physical adUnitPath ownership, and the no-GAM
    // branch completes standalone/native work before the first loadGpt call.
    const standalonePartition = loaderSource.indexOf("var standaloneItems = items.filter(function (item) { return item.placement.renderer === 'PREBID_STANDALONE'; });");
    const nativePartition = loaderSource.indexOf("var nativeOnly = items.filter(function (item) { return !item.placement.adUnitPath && item.placement.renderer !== 'PREBID_STANDALONE'; });", standalonePartition);
    const gamPartition = loaderSource.indexOf('var gamItems = items.filter(function (item) { return Boolean(item.placement.adUnitPath); });', nativePartition);
    const noGamReturn = loaderSource.indexOf('if (!gamItems.length) return Promise.all([nativePromise, standalonePromise])', gamPartition);
    const loadGpt = loaderSource.indexOf('return loadGpt(config)', gamPartition);

    assert.ok(standalonePartition >= 0, 'Loader must identify standalone Prebid placements explicitly.');
    assert.ok(nativePartition > standalonePartition, 'Direct/native placements must remain outside the GAM partition.');
    assert.ok(gamPartition > nativePartition, 'Loader must partition GAM placements by physical adUnitPath.');
    assert.ok(noGamReturn > gamPartition, 'A page with no GAM-owned placements must short-circuit before GPT initialization.');
    assert.ok(loadGpt > noGamReturn, 'GPT must load only after confirming at least one GAM-owned placement.');
});

test('standalone Prebid is not silently routed through the legacy GAM bridge path', () => {
    // GAM bridge targeting remains confined to requestEntries/GPT. Standalone
    // direct rendering is a separate function selected by renderer ownership.
    assert.match(loaderSource, /function requestEntries\(config, googletag, pubads, entries\)/);
    assert.match(loaderSource, /pbjs\.setTargetingForGPTAsync/);
    assert.match(loaderSource, /requestGam\(config, pubads, entries\)/);
    assert.match(loaderSource, /function requestStandaloneEntry\(config, entry\)/);
    assert.match(loaderSource, /pbjs\.renderAd\(iframe\.contentWindow\.document, winner\.adId\)/);
    assert.doesNotMatch(loaderSource, /setTargetingForGPTAsync[^}]+requestStandaloneEntry/s);
});
