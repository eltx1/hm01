import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from '../../scripts/transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';
import { applyDirectPreparationTransform } from '../../scripts/transform-loader-direct-preparation.mjs';
import { applyVideoPreparationTransform } from '../../scripts/transform-loader-video-preparation.mjs';

test('built Loader contains the fingerprint of every current composed transform', async () => {
    const raw = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
    const source = [applyTrafficGateTransform,applyShadowClickGuardTransform,applyPlacementPresetTransform,
        applyDirectPreparationTransform,applyVideoPreparationTransform].reduce((value,fn)=>fn(value),raw);
    const build = 'sha256:' + createHash('sha256').update(source).digest('hex');
    const compiled = await readFile(new URL('../../public/assets/hm-loader.min.js', import.meta.url), 'utf8');
    assert.equal(source.split("'source-inflight-1'").length, 2);
    assert.ok(compiled.includes(build), 'compiled distribution must match current sources');
    assert.equal(compiled.includes('source-inflight-1'), false);
    assert.ok(compiled.includes('startup-trace-2'));
});
