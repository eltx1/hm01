import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
import { summarizeStartupTrace } from '../../scripts/startup-trace-summary.mjs';
const source = readFileSync(new URL('../../resources/js/startup-diagnostics.js', import.meta.url), 'utf8').replace(/^import[^\n]+\n/, '');
function page(value) {
    let click;
    const input = { value };
    const output = { textContent: '' };
    const button = { addEventListener(name, fn) { assert.equal(name, 'click'); click = fn; } };
    vm.runInNewContext(source, { summarizeStartupTrace, document: { querySelector: key => ({ '[data-startup-trace-input]': input, '[data-startup-trace-analyze]': button, '[data-startup-trace-result]': output })[key] } });
    return { input, output, click };
}
test('local diagnostic UI shows only the sanitized report', () => {
    const h = page(JSON.stringify({ schema: 1, events: [{ phase: 'CF pass', ms: 5, token: '<script>PRIVATE</script>' }] }));
    h.click();
    assert.equal(JSON.parse(h.output.textContent).cloudflare_outcome, 'CF pass');
    assert.equal(h.output.textContent.includes('PRIVATE'), false);
});
for (const value of ['not json', 'x'.repeat(65537), JSON.stringify({ schema: 2, events: [] })]) {
    test('invalid input gets a fixed safe error without echoing its contents', () => {
        const h = page(value); h.click();
        assert.equal(h.output.textContent.startsWith('Trace could not be analyzed.'), true);
    });
}
test('shared admin bundle tolerates pages without the diagnostic panel', () => {
    assert.doesNotThrow(() => vm.runInNewContext(source, { summarizeStartupTrace, document: { querySelector: () => null } }));
});
