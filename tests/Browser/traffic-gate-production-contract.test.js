import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';

const gateSource = await readFile(new URL('../../public/assets/traffic-gate/horus-traffic-gate.js', import.meta.url), 'utf8');
const loaderBase = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const loader = applyTrafficGateTransform(loaderBase);
const configSource = await readFile(new URL('../../config/traffic_gate.php', import.meta.url), 'utf8');
const realBrowserSuite = await readFile(new URL('./traffic-gate.playwright.spec.js', import.meta.url), 'utf8');
const snapshotBuilder = await readFile(new URL('../../app/Services/StaticDelivery/StaticDeliverySnapshotBuilder.php', import.meta.url), 'utf8');

const ALWAYS_PASS_INVISIBLE = '1x00000000000000000000BB';
const ALWAYS_FAIL_INVISIBLE = '2x00000000000000000000BB';

test('controlled rollout remains globally disabled by default', () => {
    assert.match(configSource, /'enabled'\s*=>\s*false/);
});

test('current official Invisible deterministic keys are test-only and never ship in gate or Loader assets', () => {
    assert.ok(realBrowserSuite.includes(ALWAYS_PASS_INVISIBLE));
    assert.ok(realBrowserSuite.includes(ALWAYS_FAIL_INVISIBLE));
    assert.equal(gateSource.includes(ALWAYS_PASS_INVISIBLE), false);
    assert.equal(gateSource.includes(ALWAYS_FAIL_INVISIBLE), false);
    assert.equal(loader.includes(ALWAYS_PASS_INVISIBLE), false);
    assert.equal(loader.includes(ALWAYS_FAIL_INVISIBLE), false);
});

test('central pre-monetization predicate remains ahead of GAM, Prebid bridge/standalone, Direct JS and scan ownership', () => {
    assert.match(loader, /function trafficGateAllowsMonetization\(\)/);
    assert.match(loader, /function canRequestAds\(config\)[\s\S]*if \(!trafficGateAllowsMonetization\(\)\) return false;/);
    assert.match(loader, /function scan\(config\) \{\s*if \(!canRequestAds\(config\)\) return Promise\.resolve\(\[\]\);/);
    assert.match(loader, /function requestEntries\(config, googletag, pubads, entries\)/, 'GAM + Prebid GAM bridge path must remain in the centrally gated Loader');
    assert.match(loader, /function (?:requestStandaloneEntry|runStandaloneEntry)\(/, 'standalone Prebid path must remain in the centrally gated Loader');
    assert.match(loader, /function (?:loadDirectScript|runDirectInitialization|directCandidates)\(/, 'Direct JS path must remain in the centrally gated Loader');
});

test('BALANCED initial stall keeps the bound gate channel alive so a late valid PASS can still win before activity soft-allow', () => {
    assert.ok(loader.includes("enterBalancedRecovery('INITIAL_WAIT_STALL', true)"));
    assert.match(loader, /if \(type === 'HORUS_TRAFFIC_GATE_PASS'\) \{\s*trafficGateAllow\(TRAFFIC_GATE_STATES\.passed, 'PASS'\);/);
    assert.match(loader, /function trafficGateMessageListener\(event\)[\s\S]*event\.source !== gate\.iframe\.contentWindow/);
});

test('terminal decisions clean up the frame and make duplicate late messages inert', () => {
    assert.match(loader, /function trafficGateAllow[\s\S]*trafficGateCleanup\(\);[\s\S]*settleTrafficGateDecision/);
    assert.match(loader, /function trafficGateBlock[\s\S]*trafficGateCleanup\(\);[\s\S]*settleTrafficGateDecision/);
    assert.match(loader, /if \(!gate\.iframe \|\| !gate\.settings \|\| !event\) return;/);
    assert.match(gateSource, /function finish\(type, nextState, extra = \{\}\) \{\s*if \(terminal\) \{\s*return;/);
});

test('gate outcome path has no Horus beacon, analytics, reporting or per-view control-plane request', () => {
    assert.equal(/sendBeacon\s*\(/.test(gateSource), false);
    assert.equal(/XMLHttpRequest/.test(gateSource), false);
    assert.equal(/analytics|reporting|pass[_-]?beacon|fail[_-]?beacon|activity[_-]?beacon/i.test(gateSource), false);
    const fetchCalls = [...gateSource.matchAll(/fetch\(([^\n]+)/g)].map(match => match[1]);
    assert.equal(fetchCalls.length, 1, 'static gate should have only its same-origin Site configuration fetch');
    assert.ok(fetchCalls[0].includes('/configs/'));
});

test('Cloudflare Pages contract excludes dedicated Traffic Gate static paths from any future Functions invocation', () => {
    assert.ok(snapshotBuilder.includes("'_routes.json' => $this->routes()"));
    assert.ok(snapshotBuilder.includes("'/traffic-gate/*'"));
    assert.ok(snapshotBuilder.includes("'/assets/traffic-gate/*'"));
});
