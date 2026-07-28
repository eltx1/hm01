import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { transformWithEsbuild } from 'vite';

const root = resolve(import.meta.dirname, '..');
const profilePath = resolve(root, process.env.PREBID_BUILD_PROFILE || 'prebid-builds/horus-default.json');
const profile = JSON.parse(readFileSync(profilePath, 'utf8'));
const temporaryRoot = mkdtempSync(resolve(tmpdir(), 'horus-prebid-'));
const sourceDirectory = process.env.PREBID_SOURCE_DIR
    ? resolve(process.env.PREBID_SOURCE_DIR)
    : resolve(temporaryRoot, 'Prebid.js');

function run(command, args, cwd) {
    execFileSync(command, args, {
        cwd,
        stdio: 'inherit',
        env: { ...process.env, CI: 'true' },
    });
}

function outputPath(relativePath) {
    const path = resolve(root, relativePath);
    mkdirSync(dirname(path), { recursive: true });
    return path;
}

try {
    if (!process.env.PREBID_SOURCE_DIR) {
        run('git', [
            'clone', '--depth', '1', '--branch', profile.sourceReference,
            profile.sourceRepository, sourceDirectory,
        ], root);
    }

    if (!existsSync(resolve(sourceDirectory, 'gulpfile.js'))) {
        throw new Error(`Prebid source directory is invalid: ${sourceDirectory}`);
    }

    if (process.env.PREBID_USE_EXISTING_NODE_MODULES !== '1') {
        run('npm', ['ci', '--no-audit', '--no-fund'], sourceDirectory);
    }

    run('npx', [
        '--no-install', 'gulp', 'build',
        `--modules=${profile.modules.join(',')}`,
    ], sourceDirectory);

    const candidates = [
        resolve(sourceDirectory, 'build/dist/prebid.js'),
        resolve(sourceDirectory, 'build/dist/prebid.min.js'),
        resolve(sourceDirectory, 'build/dist/prebid.js.js'),
    ];
    const compiled = candidates.find(existsSync);
    if (!compiled) {
        throw new Error(`Prebid build finished without a recognized output file. Checked: ${candidates.join(', ')}`);
    }

    const source = readFileSync(compiled, 'utf8');
    const minifiedResult = await transformWithEsbuild(source, 'horus-prebid.js', {
        minify: true,
        target: 'es2018',
        legalComments: 'none',
    });
    const readableBytes = Buffer.from(source.endsWith('\n') ? source : source + '\n');
    const minifiedBytes = Buffer.from(minifiedResult.code.trim() + '\n');
    writeFileSync(outputPath(profile.output.asset), readableBytes);
    writeFileSync(outputPath(profile.output.minified), minifiedBytes);

    const checksum = createHash('sha256').update(minifiedBytes).digest('hex');
    const manifest = {
        name: profile.name,
        version: profile.version,
        prebidVersion: profile.prebidVersion,
        sourceRepository: profile.sourceRepository,
        sourceReference: profile.sourceReference,
        modules: profile.modules,
        assetUrl: '/' + profile.output.minified.replace(/^public\//, ''),
        checksum,
        bytes: minifiedBytes.length,
        readableBytes: readableBytes.length,
        builtAt: new Date().toISOString(),
    };
    writeFileSync(outputPath(profile.output.manifest), JSON.stringify(manifest, null, 2) + '\n');
    console.log(`Built ${profile.version}: ${minifiedBytes.length} bytes, sha256 ${checksum}`);
} finally {
    if (!process.env.PREBID_SOURCE_DIR && process.env.PREBID_KEEP_SOURCE !== '1') {
        rmSync(temporaryRoot, { recursive: true, force: true });
    }
}
