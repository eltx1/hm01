import { readFile, writeFile } from 'node:fs/promises';
import { transformWithEsbuild } from 'vite';

const sourcePath = new URL('../public/assets/hm-loader.js', import.meta.url);
const outputPath = new URL('../public/assets/hm-loader.min.js', import.meta.url);
const source = await readFile(sourcePath, 'utf8');
const result = await transformWithEsbuild(source, 'hm-loader.js', {
    minify: true,
    target: 'es2018',
    legalComments: 'none',
});
await writeFile(outputPath, `${result.code.trim()}\n`, 'utf8');
console.log(`Built ${outputPath.pathname}`);
