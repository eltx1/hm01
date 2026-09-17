import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');

function makeContainer(attributes) {
    const frames = [];
    const target = {
        id: 'hm-gpt-responsive-test',
        style: {},
        shadowRoot: null,
        frames,
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        attachShadow() {
            const root = {
                appendChild(frame) { frames.push(frame); return frame; },
            };
            target.shadowRoot = root;
            return root;
        },
    };
    return target;
}

function runAtWidth(width) {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_anchor',
        'data-hm-gpt-sizes': '[[300,50],[300,100],[320,50],[320,100],[728,90],[950,90],[960,90],[970,90],[980,90]]',
        'data-hm-gpt-inner-id': 'gpt-passback',
        'data-hm-gpt-size-map': JSON.stringify([
            { viewport: [0, 0], maxViewport: [767, 65535], sizes: [[300, 50], [300, 100], [320, 50], [320, 100]] },
            { viewport: [768, 0], maxViewport: [0, 0], sizes: [[728, 90], [950, 90], [960, 90], [970, 90], [980, 90]] },
        ]),
    };
    const target = makeContainer(attributes);
    const document = {
        documentElement: { clientWidth: width, clientHeight: 900 },
        querySelectorAll(query) { return query === '[data-hm-gpt-direct="1"]' ? [target] : []; },
        createElement(tag) {
            assert.equal(tag, 'iframe');
            const frameAttributes = {};
            return {
                style: {},
                srcdoc: '',
                setAttribute(name, value) { frameAttributes[name] = String(value); },
                getAttribute(name) { return frameAttributes[name] ?? null; },
            };
        },
    };
    class MutationObserver {
        constructor(callback) { this.callback = callback; }
        observe() {}
    }
    const sandbox = { document, MutationObserver, console, innerWidth: width, innerHeight: 900 };
    sandbox.window = sandbox;
    vm.runInNewContext(source, sandbox, { filename: 'hm-gpt-direct.js' });
    return { target, attributes, frame: target.frames[0] };
}

test('trusted GPT runtime exposes only mobile-mapped sizes to GPT on a mobile viewport', () => {
    const { attributes, frame } = runAtWidth(390);
    assert.ok(frame);
    assert.equal(frame.getAttribute('width'), '300');
    assert.equal(frame.getAttribute('height'), '50');
    assert.deepEqual(
        JSON.parse(attributes['data-hm-gpt-eligible-sizes']),
        [[300, 50], [300, 100], [320, 50], [320, 100]],
    );
    assert.match(frame.srcdoc, /\[\[300,50\],\[300,100\],\[320,50\],\[320,100\]\]/);
    assert.doesNotMatch(frame.srcdoc, /980,90/);
});

test('trusted GPT runtime exposes only desktop-mapped sizes to GPT on a desktop viewport', () => {
    const { attributes, frame } = runAtWidth(1440);
    assert.ok(frame);
    assert.equal(frame.getAttribute('width'), '728');
    assert.equal(frame.getAttribute('height'), '90');
    assert.deepEqual(
        JSON.parse(attributes['data-hm-gpt-eligible-sizes']),
        [[728, 90], [950, 90], [960, 90], [970, 90], [980, 90]],
    );
    assert.doesNotMatch(frame.srcdoc, /300,50/);
    assert.match(frame.srcdoc, /980,90/);
});
