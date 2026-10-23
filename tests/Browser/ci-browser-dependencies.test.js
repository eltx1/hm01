import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

test('CI normalizes legacy and Deb822 Ubuntu mirrors while preserving repository trust and unrelated sources', () => {
    const root = mkdtempSync(join(tmpdir(), 'horus-apt-'));
    try {
        mkdirSync(join(root, 'sources.list.d'));
        const files = {
            'sources.list': 'deb [signed-by=/usr/share/keyrings/ubuntu-archive-keyring.gpg] http://azure.archive.ubuntu.com/ubuntu noble main universe\n',
            'sources.list.d/runner.list': 'deb https://azure.archive.ubuntu.com/ubuntu/ noble-security main\n',
            'sources.list.d/runner.sources': 'Types: deb\nURIs: http://azure.archive.ubuntu.com/ubuntu\nSuites: noble noble-updates\nComponents: main universe\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n',
            'sources.list.d/vendor.list': 'deb [signed-by=/vendor.gpg] https://packages.microsoft.com/ubuntu/24.04/prod noble main\n',
            'sources.list.d/backup.list.save': 'deb http://azure.archive.ubuntu.com/ubuntu noble main\n',
        };
        for (const [name, contents] of Object.entries(files)) writeFileSync(join(root, name), contents);
        const run = () => spawnSync('bash', ['scripts/normalize-ubuntu-mirrors.sh', root], { encoding: 'utf8' });
        let result = run();
        assert.equal(result.status, 0, result.stderr);
        const expected = {
            ...files,
            'sources.list': 'deb [signed-by=/usr/share/keyrings/ubuntu-archive-keyring.gpg] https://archive.ubuntu.com/ubuntu noble main universe\n',
            'sources.list.d/runner.list': 'deb https://archive.ubuntu.com/ubuntu/ noble-security main\n',
            'sources.list.d/runner.sources': 'Types: deb\nURIs: https://archive.ubuntu.com/ubuntu\nSuites: noble noble-updates\nComponents: main universe\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n',
        };
        for (const [name, contents] of Object.entries(expected)) assert.equal(readFileSync(join(root, name), 'utf8'), contents, name);
        result = run();
        assert.equal(result.status, 0, result.stderr);
        for (const [name, contents] of Object.entries(expected)) assert.equal(readFileSync(join(root, name), 'utf8'), contents, `idempotent ${name}`);
    } finally {
        rmSync(root, { recursive: true, force: true });
    }
});

test('CI mirror normalization tolerates runners without Ubuntu source files', () => {
    const root = mkdtempSync(join(tmpdir(), 'horus-empty-apt-'));
    try {
        const result = spawnSync('bash', ['scripts/normalize-ubuntu-mirrors.sh', root], { encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
    } finally {
        rmSync(root, { recursive: true, force: true });
    }
});
