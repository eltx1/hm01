import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { resolve } from 'node:path';
import { transformWithEsbuild } from 'vite';

const root = resolve(new URL('..', import.meta.url).pathname);
const sourceDir = resolve(process.env.PREBID_SOURCE_DIR || `${root}/.prebid-source`);
const version = process.env.PREBID_VERSION || '11.26.0';
const modulesFile = resolve(root, 'scripts/prebid-modules.json');
const outputDir = resolve(root, 'public/assets/prebid');
const output = resolve(outputDir, `prebid-${version}.js`);
const minifiedOutput = resolve(outputDir, `prebid-${version}.min.js`);
const manifestOutput = resolve(outputDir, `prebid-${version}.manifest.json`);

await readFile(resolve(sourceDir, 'package.json'), 'utf8');
const modules = JSON.parse(await readFile(modulesFile, 'utf8'));

await run('npm', ['exec', '--', 'gulp', 'build', `--modules=${modulesFile}`], sourceDir);

const source = await readFile(resolve(sourceDir, 'build/dist/prebid.js'), 'utf8');
const banner = `/* Horus Media custom Prebid.js ${version}; modules: ${modules.join(', ')} */\n`;
const unminified = `${banner}${source.trim()}\n`;
const minified = await transformWithEsbuild(unminified, 'prebid.js', {
    minify: true,
    target: 'es2018',
    legalComments: 'inline',
});
const minifiedContents = `${minified.code.trim()}\n`;
const checksum = createHash('sha256').update(minifiedContents).digest('hex');
let sourceCommit = null;
try {
    sourceCommit = (await capture('git', ['rev-parse', 'HEAD'], sourceDir)).trim();
} catch {
    // The source ref remains pinned by CI even when git metadata is unavailable.
}

await mkdir(outputDir, { recursive: true });
await writeFile(output, unminified, 'utf8');
await writeFile(minifiedOutput, minifiedContents, 'utf8');
await writeFile(manifestOutput, `${JSON.stringify({
    version,
    sourceRepository: 'https://github.com/prebid/Prebid.js.git',
    sourceCommit,
    modules,
    adapters: modules.filter((module) => module.endsWith('BidAdapter')).map((module) => module.replace(/BidAdapter$/, '')),
    asset: `assets/prebid/prebid-${version}.js`,
    minifiedAsset: `assets/prebid/prebid-${version}.min.js`,
    sha256: checksum,
    builtAt: new Date().toISOString(),
}, null, 2)}\n`, 'utf8');

console.log(`Built custom Prebid ${version}: ${minifiedOutput}`);
console.log(`SHA-256 ${checksum}`);

function run(command, args, cwd) {
    return new Promise((resolvePromise, reject) => {
        const child = spawn(command, args, { cwd, stdio: 'inherit', env: process.env });
        child.on('error', reject);
        child.on('exit', (code) => code === 0
            ? resolvePromise()
            : reject(new Error(`${command} exited with status ${code}`)));
    });
}

function capture(command, args, cwd) {
    return new Promise((resolvePromise, reject) => {
        const child = spawn(command, args, { cwd, env: process.env });
        let stdout = '';
        let stderr = '';
        child.stdout.on('data', (chunk) => { stdout += chunk; });
        child.stderr.on('data', (chunk) => { stderr += chunk; });
        child.on('error', reject);
        child.on('exit', (code) => code === 0
            ? resolvePromise(stdout)
            : reject(new Error(stderr || `${command} exited with status ${code}`)));
    });
}
