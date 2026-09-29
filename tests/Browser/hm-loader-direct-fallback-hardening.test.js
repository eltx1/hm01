import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

function failureHandlerSource() {
    const fallbackStart = loaderSource.indexOf('function runNativeFallback(config, entry)');
    const start = loaderSource.indexOf('function failed(reason)', fallbackStart);
    const end = loaderSource.indexOf('function rendered()', start);
    assert.ok(fallbackStart >= 0 && start > fallbackStart && end > start, 'Direct Demand handler boundaries must exist');
    // Use actual function boundaries, not an arbitrary character limit which
    // silently omits cleanup when another validated branch is added above it.
    return loaderSource.slice(start, end).trim();
}

function runFailure(video = null, throwingCleanup = false) {
    const calls = [];
    const attributes = {};
    const container = {
        __hmVideoPlayer: video,
        __hmDestroy(reason) {
            calls.push(['destroy', reason]);
            if (throwingCleanup) throw new Error('provider cleanup failure');
        },
        parentNode: { removeChild(node) { assert.equal(node, container); calls.push(['remove']); } },
    };
    const context = {
        settled: false, container, config: {}, candidate: { network: 'test' }, index: 0,
        entry: { element: { setAttribute(key, value) { attributes[key] = value; } } },
        log() {}, resolve(value) { calls.push(['resolve', value]); },
        tryCandidate(index) { calls.push(['fallback', index]); },
    };
    const failed = vm.runInNewContext(`(${failureHandlerSource()})`, context);
    failed('test-error');
    failed('duplicate-error');
    return { calls, attributes };
}

test('failed Direct candidate container is removed before the next provider is attempted', () => {
    const fragment = failureHandlerSource();
    const remove = fragment.indexOf('container.parentNode.removeChild(container)');
    const fallback = fragment.indexOf('tryCandidate(index + 1)');
    assert.notEqual(remove, -1, 'failed provider container must be removed');
    assert.notEqual(fallback, -1, 'fallback candidate must still be attempted');
    assert.ok(remove < fallback, 'cleanup must happen before starting the next Direct provider');
    assert.deepEqual(runFailure().calls, [['destroy', 'failed'], ['remove'], ['fallback', 1]]);
    assert.deepEqual(runFailure(null, true).calls, [['destroy', 'failed'], ['remove'], ['fallback', 1]]);
});

test('render-poll policy denial preserves healthy content without a fallback or duplicate resolution', () => {
    const result = runFailure({ adsSuppressed: true, contentMode: true, contentFailed: false, destroyed: false });
    assert.deepEqual(result.calls, [['resolve', false]]);
    assert.equal(result.attributes['data-hm-status'], 'content-only');
});

test('content-only preservation never masks broken, destroyed, or unsuppressed candidate failure', () => {
    for (const video of [
        { adsSuppressed: true, contentMode: true, contentFailed: true },
        { adsSuppressed: true, contentMode: true, destroyed: true },
        { adsSuppressed: false, contentMode: true },
        { adsSuppressed: true, contentMode: false },
    ]) {
        assert.deepEqual(runFailure(video).calls, [['destroy', 'failed'], ['remove'], ['fallback', 1]]);
    }
});
