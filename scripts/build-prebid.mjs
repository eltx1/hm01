import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { transformWithEsbuild } from 'vite';

const manifestPath = new URL('../resources/prebid/horus-build.json', import.meta.url);
const outputDir = new URL('../public/assets/prebid/', import.meta.url);
const sourcePath = new URL('horus-prebid.js', outputDir);
const minifiedPath = new URL('horus-prebid.min.js', outputDir);
const checksumPath = new URL('horus-prebid.sha256', outputDir);
const buildMetadataPath = new URL('horus-prebid.build.json', outputDir);
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
const endpoint = process.env.HORUS_PREBID_DOWNLOAD_URL || 'https://js-download.prebid.org/download';

function normalizedBuild(value) {
    const modules = Array.isArray(value?.modules)
        ? [...new Set(value.modules.map(String))].sort()
        : [];
    return { version: String(value?.version || ''), modules };
}

function buildFingerprint(value) {
    return createHash('sha256').update(JSON.stringify(normalizedBuild(value))).digest('hex');
}

const expectedBuild = normalizedBuild(manifest);
const expectedFingerprint = buildFingerprint(expectedBuild);

const form = new URLSearchParams();
for (const moduleCode of manifest.modules) form.append('modules[]', moduleCode);
form.set('version', manifest.version);

function validSource(source) {
    return typeof source === 'string'
        && source.includes('pbjs')
        && source.length >= 50000
        && source.includes(`prebid.js v${manifest.version}`);
}

function validCommittedBuildMetadata(metadata) {
    if (!metadata || typeof metadata !== 'object') return false;
    const normalized = normalizedBuild(metadata);
    return normalized.version === expectedBuild.version
        && JSON.stringify(normalized.modules) === JSON.stringify(expectedBuild.modules)
        && String(metadata.manifestSha256 || '') === expectedFingerprint;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function downloadSource() {
    let lastError = null;
    for (let attempt = 1; attempt <= 3; attempt += 1) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'content-type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: form,
                signal: controller.signal,
            });
            if (!response.ok) throw new Error(`Prebid build download failed: ${response.status}`);
            const source = await response.text();
            if (!validSource(source)) throw new Error('Downloaded Prebid build is incomplete or does not match the pinned version');
            return source;
        } catch (error) {
            lastError = error;
            if (attempt < 3) await sleep(500 * attempt);
        } finally {
            clearTimeout(timeout);
        }
    }
    throw lastError || new Error('Prebid build download failed');
}

let source;
let sourceOrigin = 'download';
try {
    source = await downloadSource();
} catch (error) {
    // The generated, reviewed source is committed with the repository. A flaky
    // upstream builder must not make an otherwise deterministic production
    // release impossible. Fallback is accepted only when both the source and
    // its committed build metadata match the exact pinned version + module set.
    // A manifest module change therefore cannot silently reuse an older bundle.
    const [committed, metadataText] = await Promise.all([
        readFile(sourcePath, 'utf8').catch(() => ''),
        readFile(buildMetadataPath, 'utf8').catch(() => ''),
    ]);
    let metadata = null;
    try { metadata = JSON.parse(metadataText); } catch { metadata = null; }
    if (!validSource(committed) || !validCommittedBuildMetadata(metadata)) {
        throw new Error(`Prebid download failed and the committed fallback does not match the pinned version/module manifest: ${error?.message || error}`);
    }
    source = committed;
    sourceOrigin = 'committed fallback';
    console.warn(`Prebid builder unavailable; using validated committed ${manifest.version} source for manifest ${expectedFingerprint}`);
}

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
await writeFile(buildMetadataPath, `${JSON.stringify({
    version: expectedBuild.version,
    modules: expectedBuild.modules,
    manifestSha256: expectedFingerprint,
}, null, 2)}\n`, 'utf8');
console.log(`Built Prebid.js ${manifest.version} with ${manifest.modules.length} modules from ${sourceOrigin}`);
