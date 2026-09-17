import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');

function run(renderedSize) {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/23055873217/lordai.net',
        'data-hm-gpt-sizes': '[[728,90],[950,90],[960,90],[970,90],[980,90]]',
        'data-hm-gpt-inner-id': 'gpt-passback',
    };
    const target = {
        id: 'hm-gpt-expanded-test',
        style: {},
        childNodes: [],
        innerHTML: '',
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
    };
    const listeners = [];
    const destroyed = [];
    const pubads = {
        addEventListener(name, callback) { if (name === 'slotRenderEnded') listeners.push(callback); },
        removeEventListener() {},
    };
    let slot;
    const googletag = {
        apiReady: true,
        cmd: { push(callback) { callback(); } },
        defineSlot(path, sizes, id) {
            slot = { path, sizes, id, addService() { return slot; } };
            return slot;
        },
        pubads() { return pubads; },
        enableServices() {},
        display() {},
        destroySlots(slots) { destroyed.push(...slots); return true; },
    };
    const document = {
        documentElement: { clientWidth: 1440, clientHeight: 900 },
        head: { appendChild() {} },
        querySelectorAll(query) { return query === '[data-hm-gpt-direct="1"]' ? [target] : []; },
        getElementsByTagName(tag) { return tag === 'script' ? [] : []; },
        createElement() { throw new Error('GPT is already ready'); },
    };
    class MutationObserver {
        observe() {}
    }
    const sandbox = { document, MutationObserver, console, innerWidth: 1440, innerHeight: 900, googletag };
    sandbox.window = sandbox;
    vm.runInNewContext(source, sandbox, { filename: 'hm-gpt-direct.js' });
    listeners[0]({ slot, isEmpty: false, size: renderedSize });
    return { attributes, target, destroyed, slot };
}

test('trusted GPT expansion close to a reviewed size remains rendered', () => {
    const result = run([980, 100]);
    assert.equal(result.attributes['data-hm-gpt-status'], 'rendered');
    assert.equal(result.attributes['data-hm-gpt-runtime-state'], 'rendered');
    assert.equal(result.attributes['data-hm-gpt-rendered-width'], '980');
    assert.equal(result.attributes['data-hm-gpt-rendered-height'], '100');
    assert.equal(result.target.style.width, '980px');
    assert.equal(result.target.style.height, '100px');
    assert.deepEqual(result.destroyed, []);
});

test('unrelated oversized GPT render is still rejected and destroyed', () => {
    const result = run([980, 600]);
    assert.equal(result.attributes['data-hm-gpt-status'], 'failed');
    assert.equal(result.attributes['data-hm-gpt-runtime-state'], 'failed');
    assert.equal(result.attributes['data-hm-gpt-rendered-width'], undefined);
    assert.equal(result.attributes['data-hm-gpt-rendered-height'], undefined);
    assert.deepEqual(result.destroyed, [result.slot]);
});
