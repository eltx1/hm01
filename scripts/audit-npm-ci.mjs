import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';

const ADVISORY = 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf';
const EXPIRES_UTC = '2026-12-01T00:00:00.000Z';
const ONLY_DERIVED_PACKAGES = new Set([
    'picomatch', 'fdir', 'tinyglobby', 'vite', 'laravel-vite-plugin', 'vite-plugin-full-reload',
]);
const LOCKED_PICOMATCH = new Map([
    ['node_modules/picomatch', '4.0.5'],
    ['node_modules/vite-plugin-full-reload/node_modules/picomatch', '2.3.2'],
]);

function fail(reason) {
    throw new Error('Dependency audit refused: ' + reason);
}

/**
 * Narrow, temporary risk acceptance: GHSA-fc5q-39xc-vjpf has no patched
 * npm release as of 2026-11-18. All affected packages are trusted-build-only.
 * Every other advisory, changed dependency graph and expiry fails closed.
 */
export function evaluateFullAudit(report, lock, now = new Date()) {
    if (!report || !report.vulnerabilities || !report.metadata?.vulnerabilities
        || !lock?.packages || typeof report.vulnerabilities !== 'object') {
        fail('missing validated npm audit/lockfile metadata');
    }
    const vulnerabilities = report.vulnerabilities;
    const names = Object.keys(vulnerabilities);
    if (names.length === 0) return 'clean';
    if (now.getTime() >= Date.parse(EXPIRES_UTC)) fail('temporary exception expired');
    if (names.length !== ONLY_DERIVED_PACKAGES.size
        || names.some(name => !ONLY_DERIVED_PACKAGES.has(name))) {
        fail('unexpected affected package/advisory');
    }
    const checked = new Set();
    let advisoryFound = false;
    function inspect(name, visiting = new Set()) {
        if (checked.has(name)) return;
        if (visiting.has(name)) fail('cyclic or unverified vulnerability chain');
        const item = vulnerabilities[name];
        if (!item || !Array.isArray(item.via) || item.via.length === 0
            || !Array.isArray(item.nodes) || item.nodes.length === 0) {
            fail('missing vulnerability chain or package nodes: ' + name);
        }
        const next = new Set(visiting);
        next.add(name);
        for (const dependency of item.via) {
            if (typeof dependency === 'string') {
                if (!ONLY_DERIVED_PACKAGES.has(dependency)) fail('unexpected dependency: ' + dependency);
                inspect(dependency, next);
            } else if (dependency && typeof dependency === 'object') {
                if (dependency.name !== 'picomatch' || dependency.url !== ADVISORY
                    || dependency.severity !== 'high') {
                    fail('unrecognized upstream security advisory');
                }
                advisoryFound = true;
            } else {
                fail('unrecognized npm advisory shape');
            }
        }
        for (const node of item.nodes) {
            const record = lock.packages[node];
            if (typeof node !== 'string' || !node.startsWith('node_modules/')
                || record?.dev !== true) {
                fail('security issue is not confined to locked dev dependencies');
            }
            if (name === 'picomatch' && LOCKED_PICOMATCH.get(node) !== record.version) {
                fail('picomatch location/version changed');
            }
        }
        checked.add(name);
    }
    for (const name of names) inspect(name);
    if (!advisoryFound) fail('expected upstream advisory not present');
    const actualPicomatch = vulnerabilities.picomatch.nodes;
    if (actualPicomatch.length !== LOCKED_PICOMATCH.size
        || actualPicomatch.some(node => !LOCKED_PICOMATCH.has(node))) {
        fail('the vulnerable dependency locations have changed');
    }
    if (report.metadata.vulnerabilities.total !== names.length) {
        fail('audit counts do not match the reviewed exception');
    }
    return 'temporary-dev-only-GHSA-fc5q-39xc-vjpf';
}

function auditedReport(omitDev) {
    // Retry a registry transport failure only, never an actionable advisory.
    for (const registry of [null, 'https://registry.yarnpkg.com']) {
        const args = ['audit', '--package-lock-only', '--audit-level=moderate', '--json'];
        if (omitDev) args.push('--omit=dev');
        if (registry) args.push('--registry=' + registry);
        const result = spawnSync('npm', args, {encoding: 'utf8', maxBuffer: 8 * 1024 * 1024});
        let parsed;
        try { parsed = JSON.parse(result.stdout); } catch { /* Non-JSON transport error. */ }
        if (result.error || ![0, 1].includes(result.status) || parsed?.error
            || !parsed?.vulnerabilities || !parsed?.metadata?.vulnerabilities) {
            continue;
        }
        return {parsed, status: result.status};
    }
    fail('npm audit transport/format failure in both registries');
}

function main() {
    const lock = JSON.parse(readFileSync('package-lock.json', 'utf8'));
    const production = auditedReport(true);
    if (production.status !== 0 || Object.keys(production.parsed.vulnerabilities).length !== 0) {
        fail('production dependency audit must pass without exceptions');
    }
    const full = auditedReport(false);
    const decision = evaluateFullAudit(full.parsed, lock);
    if (decision === 'clean' && full.status !== 0) fail('unexpected nonzero audit status');
    if (decision !== 'clean' && full.status !== 1) fail('unexpected npm audit status');
    console.log('Production dependency audit clean.');
    if (decision !== 'clean') {
        console.warn('Time-limited DEV-ONLY exception for GHSA-fc5q-39xc-vjpf; expires ' + EXPIRES_UTC);
        console.warn('All other known vulnerabilities remain blocking. No published fix exists yet.');
    } else {
        console.log('Full dependency audit clean; no exception needed.');
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try { main(); } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
