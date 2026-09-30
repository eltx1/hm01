import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { transformWithEsbuild } from 'vite';
import { applyTrafficGateTransform } from './transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from './transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from './transform-loader-placement-presets.mjs';
import { applyDirectPreparationTransform } from './transform-loader-direct-preparation.mjs';
import { applyVideoPreparationTransform } from './transform-loader-video-preparation.mjs';
import './build-rewarded-prompt.mjs';

const sourcePath = new URL('../public/assets/hm-loader.js', import.meta.url);
const outputPath = new URL('../public/assets/hm-loader.min.js', import.meta.url);
const baseSource = await readFile(sourcePath, 'utf8');
const source = applyVideoPreparationTransform(applyDirectPreparationTransform(applyPlacementPresetTransform(
    applyShadowClickGuardTransform(applyTrafficGateTransform(baseSource)),
)));
// Hash every composed Loader transform before minification. The stable marker
// avoids a self-referential hash and distinguishes cached serving revisions.
const marker = "'source-inflight-1'";
if (source.split(marker).length !== 2) throw new Error('Loader build identity marker missing or duplicated');
const runtimeBuild = 'sha256:' + createHash('sha256').update(source).digest('hex');
const identifiedSource = source.replace(marker, JSON.stringify(runtimeBuild));
const result = await transformWithEsbuild(identifiedSource, 'hm-loader.js', {
    minify: true,
    target: 'es2018',
    legalComments: 'none',
});
await writeFile(outputPath, `${result.code.trim()}\n`, 'utf8');
console.log(`Built ${outputPath.pathname} with Client Traffic Gate, shadow-aware Click Guard, and smart placement runtime`);
