import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const isolatedSource = await readFile(new URL('../../public/assets/hm-isolated-direct.js', import.meta.url), 'utf8');
const gptSource = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');

function container(attributes, id = '') {
    const frames = [];
    const childNodes = [];
    return {
        id,
        frames,
        childNodes,
        innerHTML: '',
        shadowRoot: null,
        style: {},
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        appendChild(frame) {
            childNodes.push(frame);
            frames.push(frame);
            queueMicrotask(() => frame.onload?.());
            return frame;
        },
    };
}

function iframe() {
    const attributes = {};
    const sandboxValues = [];
    return {
        tagName: 'IFRAME',
        attributes,
        style: {},
        srcdoc: '',
        onload: null,
        onerror: null,
        sandbox: { add(value) { sandboxValues.push(String(value)); } },
        sandboxValues,
        setAttribute(name, value) { attributes[name] = String(value); },
        getAttribute(name) { return attributes[name] ?? null; },
    };
}

function runIsolated(selectedContainer) {
    const createdFrames = [];
    const selectedContainers = Array.isArray(selectedContainer) ? selectedContainer : [selectedContainer];
    const document = {
        documentElement: {},
        querySelectorAll(query) { return query === '[data-hm-isolated-direct="1"]' ? selectedContainers : []; },
        createElement(tag) {
            assert.equal(tag, 'iframe');
            const frame = iframe();
            createdFrames.push(frame);
            return frame;
        },
    };
    class MutationObserver {
        constructor(callback) { this.callback = callback; }
        observe() {}
    }
    const sandbox = {
        document,
        MutationObserver,
        queueMicrotask,
        setTimeout,
        clearTimeout,
        console,
        atob(value) { return Buffer.from(String(value), 'base64').toString('binary'); },
    };
    sandbox.window = sandbox;
    vm.runInNewContext(isolatedSource, sandbox, { filename: 'hm-isolated-direct.js' });
    return { sandbox, createdFrames };
}

function scriptElement() {
    const attributes = {};
    return {
        tagName: 'SCRIPT',
        async: false,
        src: '',
        onerror: null,
        setAttribute(name, value) { attributes[name] = String(value); },
        getAttribute(name) { return attributes[name] ?? null; },
    };
}

function runGpt(selectedContainer, options = {}) {
    const selectedContainers = Array.isArray(selectedContainer) ? selectedContainer : [selectedContainer];
    const definitions = [];
    const displayCalls = [];
    const destroyedSlots = [];
    const listeners = [];
    const appendedScripts = [];
    let enableServicesCalls = 0;

    const pubads = {
        addEventListener(name, callback) {
            if (name === 'slotRenderEnded') listeners.push(callback);
        },
        removeEventListener(name, callback) {
            if (name !== 'slotRenderEnded') return;
            const index = listeners.indexOf(callback);
            if (index >= 0) listeners.splice(index, 1);
        },
    };
    const googletag = {
        apiReady: true,
        cmd: { push(callback) { callback(); } },
        defineSlot(path, slotSizes, id) {
            const slot = {
                path,
                sizes: slotSizes,
                id,
                service: null,
                addService(service) { slot.service = service; return slot; },
            };
            definitions.push(slot);
            return slot;
        },
        pubads() { return pubads; },
        enableServices() { enableServicesCalls += 1; },
        display(id) { displayCalls.push(id); },
        destroySlots(slots) { destroyedSlots.push(...slots); return true; },
    };

    const head = {
        appendChild(script) { appendedScripts.push(script); return script; },
    };
    const width = options.width ?? 1024;
    const height = options.height ?? 900;
    const document = {
        documentElement: { clientWidth: width, clientHeight: height },
        head,
        body: { appendChild(script) { appendedScripts.push(script); return script; } },
        querySelectorAll(query) { return query === '[data-hm-gpt-direct="1"]' ? selectedContainers : []; },
        getElementsByTagName(tag) {
            if (tag === 'script') return appendedScripts;
            if (tag === 'head') return [head];
            return [];
        },
        createElement(tag) {
            assert.equal(tag, 'script');
            return scriptElement();
        },
    };
    class MutationObserver {
        constructor(callback) { this.callback = callback; }
        observe() {}
    }
    const sandbox = { document, MutationObserver, console, innerWidth: width, innerHeight: height };
    if (options.ready !== false) sandbox.googletag = googletag;
    sandbox.window = sandbox;
    vm.runInNewContext(gptSource, sandbox, { filename: 'hm-gpt-direct.js' });

    return {
        sandbox,
        definitions,
        displayCalls,
        destroyedSlots,
        appendedScripts,
        get enableServicesCalls() { return enableServicesCalls; },
        emit(slot, event) {
            listeners.slice().forEach((callback) => callback({ slot, isEmpty: false, size: null, ...event }));
        },
    };
}

function legacyLoaderWouldTreatGptAsRendered(target, attributes) {
    if (attributes['data-hm-gpt-status'] === 'rendered') return true;
    if (target.childNodes && target.childNodes.length) return true;
    return Boolean(typeof target.innerHTML === 'string' && target.innerHTML.replace(/\s/g, '') !== '');
}

function chunkedAttributes(baseAttribute, value, chunkSize = 1800) {
    const encoded = Buffer.from(value, 'utf8').toString('base64');
    const parts = [];
    for (let offset = 0; offset < encoded.length; offset += chunkSize) parts.push(encoded.slice(offset, offset + chunkSize));
    const attributes = { [`${baseAttribute}-parts`]: String(parts.length) };
    parts.forEach((part, index) => { attributes[`${baseAttribute}-${index}`] = part; });
    return attributes;
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

test('isolated Direct Demand runtime preserves placement dimensions and sandboxing', async () => {
    const html = '<script src="https://ads.example.com/ad.js"></script><div id="ad"></div>';
    const csp = "default-src 'none'; script-src https://ads.example.com; connect-src https://ads.example.com; object-src 'none';";
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-html': Buffer.from(html, 'utf8').toString('base64'),
        'data-hm-isolated-csp': Buffer.from(csp, 'utf8').toString('base64'),
        'data-hm-isolated-width': '728',
        'data-hm-isolated-height': '90',
    };
    const target = container(attributes, 'isolated-zone');
    const { createdFrames } = runIsolated(target);
    await tick();

    assert.equal(createdFrames.length, 1);
    const frame = createdFrames[0];
    assert.equal(frame.getAttribute('width'), '728');
    assert.equal(frame.getAttribute('height'), '90');
    assert.deepEqual(frame.sandboxValues, ['allow-scripts']);
    assert.match(frame.srcdoc, /Content-Security-Policy/);
    assert.ok(frame.srcdoc.includes(csp));
    assert.ok(frame.srcdoc.includes(html));
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'loaded');
    assert.equal(attributes['data-hm-isolated-status'], 'requested');
});

test('isolated Direct Demand runtime reassembles payloads larger than public attribute limits', async () => {
    const marker = 'LONG-PROVIDER-PAYLOAD-';
    const html = '<script src="https://ads.example.com/ad.js"></script><div id="ad">' + marker.repeat(180) + '</div>';
    const csp = "default-src 'none'; script-src https://ads.example.com; connect-src https://ads.example.com; object-src 'none';";
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-width': '300',
        'data-hm-isolated-height': '250',
        ...chunkedAttributes('data-hm-isolated-html', html),
        ...chunkedAttributes('data-hm-isolated-csp', csp),
    };
    const target = container(attributes, 'chunked-isolated-zone');
    const { createdFrames } = runIsolated(target);
    await tick();

    assert.ok(Object.keys(attributes).filter((key) => key.startsWith('data-hm-isolated-html-')).length > 2);
    assert.ok(Object.values(attributes).every((value) => String(value).length <= 2000));
    assert.equal(createdFrames.length, 1);
    assert.ok(createdFrames[0].srcdoc.includes(html));
    assert.ok(createdFrames[0].srcdoc.includes(csp));
    assert.deepEqual(createdFrames[0].sandboxValues, ['allow-scripts']);
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'loaded');
});

test('isolated Direct Demand runtime rejects incomplete chunked payloads', () => {
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-html-parts': '2',
        'data-hm-isolated-html-0': Buffer.from('<div>', 'utf8').toString('base64'),
        'data-hm-isolated-csp': Buffer.from("default-src 'none'; script-src https://ads.example.com;", 'utf8').toString('base64'),
    };
    const target = container(attributes, 'broken-isolated-zone');
    const { createdFrames } = runIsolated(target);

    assert.equal(createdFrames.length, 0);
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'invalid');
});

test('Google GPT direct runtime executes the slot in the publisher document and waits for slotRenderEnded', () => {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250],[320,100]]',
        'data-hm-gpt-inner-id': 'div-gpt-ad-lordai-header',
    };
    const target = container(attributes, 'hm-gpt-placement-1');
    const runtime = runGpt(target);

    assert.equal(runtime.definitions.length, 1);
    assert.equal(runtime.definitions[0].path, '/1234567/lordai_header');
    assert.deepEqual(JSON.parse(JSON.stringify(runtime.definitions[0].sizes)), [[300, 250], [320, 100]]);
    assert.equal(runtime.definitions[0].id, 'hm-gpt-placement-1');
    assert.deepEqual(runtime.displayCalls, ['hm-gpt-placement-1']);
    assert.equal(runtime.enableServicesCalls, 1);
    assert.equal(attributes['data-hm-gpt-document-context'], 'publisher');
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'requested');
    assert.equal(attributes['data-hm-gpt-status'], undefined);
    assert.equal(target.shadowRoot, null);
    assert.equal(target.childNodes.length, 0);
    assert.equal(legacyLoaderWouldTreatGptAsRendered(target, attributes), false);
    assert.doesNotMatch(gptSource, /srcdoc/i);
    assert.doesNotMatch(gptSource, /setForceSafeFrame/);
    assert.doesNotMatch(gptSource, /attachShadow/);

    runtime.emit(runtime.definitions[0], { isEmpty: false, size: [320, 100] });

    assert.equal(attributes['data-hm-gpt-status'], 'rendered');
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'rendered');
    assert.equal(attributes['data-hm-gpt-rendered-width'], '320');
    assert.equal(attributes['data-hm-gpt-rendered-height'], '100');
    assert.equal(target.style.width, '320px');
    assert.equal(target.style.height, '100px');
    assert.equal(legacyLoaderWouldTreatGptAsRendered(target, attributes), true);
});

test('Google GPT no-fill remains a failure path and destroys the empty slot', () => {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/no_fill',
        'data-hm-gpt-sizes': '[[320,50],[320,100]]',
        'data-hm-gpt-inner-id': 'provider-no-fill',
    };
    const target = container(attributes, 'hm-gpt-placement-empty');
    const runtime = runGpt(target);
    const slot = runtime.definitions[0];

    runtime.emit(slot, { isEmpty: true, size: null });

    assert.equal(attributes['data-hm-gpt-status'], 'empty');
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'empty');
    assert.deepEqual(runtime.destroyedSlots, [slot]);
    assert.equal(legacyLoaderWouldTreatGptAsRendered(target, attributes), false);
});

test('Google GPT runtime resizes to the actual declared creative and rejects undeclared render sizes', () => {
    const goodAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/mixed_shape',
        'data-hm-gpt-sizes': '[[970,90],[300,600]]',
        'data-hm-gpt-inner-id': 'provider-mixed-shape',
    };
    const good = container(goodAttributes, 'hm-gpt-placement-mixed');
    const goodRuntime = runGpt(good);
    assert.equal(good.style.width, '970px');
    assert.equal(good.style.height, '90px');
    goodRuntime.emit(goodRuntime.definitions[0], { isEmpty: false, size: [300, 600] });
    assert.equal(goodAttributes['data-hm-gpt-status'], 'rendered');
    assert.equal(good.style.width, '300px');
    assert.equal(good.style.height, '600px');

    const badAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/mixed_shape_bad',
        'data-hm-gpt-sizes': '[[970,90],[300,600]]',
        'data-hm-gpt-inner-id': 'provider-mixed-shape-bad',
    };
    const bad = container(badAttributes, 'hm-gpt-placement-mixed-invalid');
    const badRuntime = runGpt(bad);
    const badSlot = badRuntime.definitions[0];
    badRuntime.emit(badSlot, { isEmpty: false, size: [970, 600] });
    assert.equal(badAttributes['data-hm-gpt-status'], 'failed');
    assert.equal(badAttributes['data-hm-gpt-rendered-width'], undefined);
    assert.equal(badAttributes['data-hm-gpt-rendered-height'], undefined);
    assert.deepEqual(badRuntime.destroyedSlots, [badSlot]);
});

test('Google GPT runtime uses unique Horus outer ids when provider container ids repeat', () => {
    const firstAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/header_a',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'provider-reused-div',
    };
    const secondAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/header_b',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'provider-reused-div',
    };
    const first = container(firstAttributes, 'hm-gpt-placement-a');
    const second = container(secondAttributes, 'hm-gpt-placement-b');
    const runtime = runGpt([first, second]);

    assert.equal(runtime.definitions.length, 2);
    assert.equal(runtime.definitions[0].id, 'hm-gpt-placement-a');
    assert.equal(runtime.definitions[1].id, 'hm-gpt-placement-b');
    assert.notEqual(runtime.definitions[0].id, runtime.definitions[1].id);
    assert.deepEqual(runtime.displayCalls, ['hm-gpt-placement-a', 'hm-gpt-placement-b']);
});

test('Google GPT direct runtime rejects non-normalized outer or provider identifiers', () => {
    const invalidOuterAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'provider-inner',
    };
    const invalidOuter = container(invalidOuterAttributes, 'bad.id');
    const invalidInnerAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'bad.inner',
    };
    const invalidInner = container(invalidInnerAttributes, 'hm-gpt-placement-valid');
    const runtime = runGpt([invalidOuter, invalidInner]);

    assert.equal(runtime.definitions.length, 0);
    assert.equal(invalidOuterAttributes['data-hm-gpt-runtime-state'], 'invalid');
    assert.equal(invalidInnerAttributes['data-hm-gpt-runtime-state'], 'invalid');
});

test('Google GPT runtime injects the GPT library once into the publisher document when it is not already present', () => {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/library_bootstrap',
        'data-hm-gpt-sizes': '[[320,50]]',
        'data-hm-gpt-inner-id': 'provider-library-bootstrap',
    };
    const target = container(attributes, 'hm-gpt-placement-library');
    const runtime = runGpt(target, { ready: false, width: 390 });

    assert.equal(attributes['data-hm-gpt-runtime-state'], 'queued');
    assert.equal(attributes['data-hm-gpt-document-context'], 'publisher');
    assert.equal(runtime.appendedScripts.length, 1);
    assert.equal(runtime.appendedScripts[0].src, 'https://securepubads.g.doubleclick.net/tag/js/gpt.js');
    assert.equal(runtime.appendedScripts[0].async, true);
    assert.equal(runtime.appendedScripts[0].getAttribute('crossorigin'), 'anonymous');
    assert.equal(runtime.appendedScripts[0].getAttribute('data-hm-gpt-library'), '1');

    vm.runInNewContext(gptSource, runtime.sandbox, { filename: 'hm-gpt-direct-second-load.js' });
    assert.equal(runtime.appendedScripts.length, 1, 'reloading the Horus runtime must not inject GPT twice');
});
