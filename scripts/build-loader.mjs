import { readFile, writeFile } from 'node:fs/promises';
import { transformWithEsbuild } from 'vite';
import { applyTrafficGateTransform } from './transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from './transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from './transform-loader-placement-presets.mjs';
import './build-rewarded-prompt.mjs';

const sourcePath = new URL('../public/assets/hm-loader.js', import.meta.url);
const outputPath = new URL('../public/assets/hm-loader.min.js', import.meta.url);
const baseSource = await readFile(sourcePath, 'utf8');
const source = applyPlacementPresetTransform(
    applyShadowClickGuardTransform(applyTrafficGateTransform(baseSource)),
);
const result = await transformWithEsbuild(source, 'hm-loader.js', {
    minify: true,
    target: 'es2018',
    legalComments: 'none',
});
await writeFile(outputPath, `${result.code.trim()}\n`, 'utf8');
console.log(`Built ${outputPath.pathname} with Client Traffic Gate, shadow-aware Click Guard, and smart placement runtime`);
