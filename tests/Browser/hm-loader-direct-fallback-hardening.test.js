import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

test('failed Direct candidate container is removed before the next provider is attempted', () => {
    const start = loaderSource.indexOf('function failed(reason)');
    assert.notEqual(start, -1, 'Direct Demand failure handler must exist');
    const fragment = loaderSource.slice(start, start + 1200);
    const remove = fragment.indexOf('container.parentNode.removeChild(container)');
    const fallback = fragment.indexOf('tryCandidate(index + 1)');

    assert.notEqual(remove, -1, 'failed provider container must be removed');
    assert.notEqual(fallback, -1, 'fallback candidate must still be attempted');
    assert.ok(remove < fallback, 'cleanup must happen before starting the next Direct provider');
});
