import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
const script = readFileSync(new URL('../../public/assets/dashboard-theme.js', import.meta.url), 'utf8');
function setup(saved, denied = false) {
    const handlers = {}, windowHandlers = {}, clicks = {};
    const button = { hidden: true, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; }, addEventListener(k, v) { clicks[k] = v; } };
    const document = { documentElement: { dataset: {} }, querySelectorAll: () => [button], addEventListener(k, v) { handlers[k] = v; } };
    const window = { addEventListener(k, v) { windowHandlers[k] = v; } };
    Object.defineProperty(window, 'localStorage', { get() { if (denied) throw Error('denied'); return { getItem: () => saved, setItem(k, v) { saved = v; } }; } });
    vm.runInNewContext(script, { document, window });
    handlers.DOMContentLoaded();
    return { document, button, clicks, windowHandlers, saved: () => saved };
}
test('dashboard theme defaults to original dark and round-trips accessibly', () => {
    const s = setup('invalid');
    assert.equal(s.document.documentElement.dataset.hmTheme, 'dark');
    assert.equal(s.button.hidden, false);
    s.clicks.click();
    assert.equal(s.saved(), 'light');
    assert.equal(s.button.attrs['aria-pressed'], 'true');
    assert.equal(s.button.textContent, 'Dark Mode');
    s.clicks.click();
    assert.equal(s.document.documentElement.dataset.hmTheme, 'dark');
});
test('denied localStorage never blocks theme switching and storage events synchronize tabs', () => {
    const s = setup(null, true);
    s.clicks.click();
    assert.equal(s.document.documentElement.dataset.hmTheme, 'light');
    s.windowHandlers.storage({ key: 'hm:dashboard:theme', newValue: 'dark' });
    assert.equal(s.document.documentElement.dataset.hmTheme, 'dark');
});
test('saved light choice applies before DOMContentLoaded', () => {
    assert.equal(setup('light').document.documentElement.dataset.hmTheme, 'light');
});
