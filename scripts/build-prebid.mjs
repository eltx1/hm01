import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { transformWithEsbuild } from 'vite';

const manifestPath = new URL('../resources/prebid/horus-build.json', import.meta.url);
const outputDir = new URL('../public/assets/prebid/', import.meta.url);
const sourcePath = new URL('horus-prebid.js', outputDir);
const minifiedPath = new URL('horus-prebid.min.js', outputDir);
const checksumPath = new URL('horus-prebid.sha256', outputDir);
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
const endpoint = process.env.HORUS_PREBID_DOWNLOAD_URL || 'https://js-download.prebid.org/download';

const form = new URLSearchParams();
for (const moduleCode of manifest.modules) form.append('modules[]', moduleCode);
form.set('version', manifest.version);

const response = await fetch(endpoint, {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: form,
});
if (!response.ok) throw new Error(`Prebid build download failed: ${response.status}`);
const source = await response.text();
if (!source.includes('pbjs') || source.length < 50000) throw new Error('Downloaded Prebid build is incomplete');

await mkdir(outputDir, { recursive: true });
await writeFile(sourcePath, source, 'utf8');
const minified = await transformWithEsbuild(source, 'horus-prebid.js', {
    minify: true,
    target: 'es2018',
    legalComments: 'none',
});
await writeFile(minifiedPath, `${minified.code.trim()}\n`, 'utf8');
const checksum = createHash('sha256').update(minified.code).digest('hex');
await writeFile(checksumPath, `${checksum}\n`, 'utf8');
console.log(`Built Prebid.js ${manifest.version} with ${manifest.modules.length} modules`);
