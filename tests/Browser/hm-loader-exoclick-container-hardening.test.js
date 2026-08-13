import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

test('structured Direct Demand preserves the approved ExoClick ins container element', () => {
    const start = loaderSource.indexOf('function directContainer(entry, candidate)');
    assert.notEqual(start, -1, 'Direct Demand container builder must exist');

    const fragment = loaderSource.slice(start, start + 900);
    assert.match(
        fragment,
        /\['div', 'span', 'aside', 'section', 'ins'\]\.indexOf\(elementName\)/,
        'the bounded container allowlist must explicitly include ins',
    );
    assert.match(
        fragment,
        /document\.createElement\(elementName\)/,
        'the approved recipe element must be used to create the provider container',
    );
});
