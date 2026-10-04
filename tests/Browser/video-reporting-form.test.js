import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const script = fs.readFileSync(new URL('../../resources/js/site-gam-reporting.js', import.meta.url), 'utf8');
function field(value) {
    return { value, listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; } };
}
function form(prefix, accountValue) {
    const account = field(accountValue), input = field('123'), feedback = {};
    const list = { children: [], replaceChildren() { this.children = []; }, append(option) { this.children.push(option); } };
    return {account, input, list, feedback, dataset: {unitsUrl: '/units'}, querySelector(selector) {
        if (selector.includes(`name="${prefix}gam_connection_id"`)) return account;
        if (selector.includes(`name="${prefix}ad_unit"`)) return input;
        if (selector === 'datalist') return list;
        if (selector === '[data-unit-feedback]') return feedback;
        return null;
    }};
}
test('independent main and Video forms search their own accounts and datalists', async () => {
    const main = form('', 'main-account'), video = form('video_', 'video-account'), urls = [];
    const context = { URL, AbortController,
        document: {querySelectorAll: () => [main, video], createElement: () => ({})},
        window: {location: {origin: 'https://example.test'}, clearTimeout, setTimeout},
        fetch: async url => { urls.push(url); return {ok: true, json: async () => ({units: [{id: url.searchParams.get('gam_connection_id'), name: 'Selected unit', adUnitCode: 'unit'}]})}; },
    };
    vm.runInNewContext(script, context);
    main.input.listeners.focus(); video.input.listeners.focus();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(urls[0].searchParams.get('gam_connection_id'), 'main-account');
    assert.equal(urls[1].searchParams.get('gam_connection_id'), 'video-account');
    assert.equal(main.list.children[0].value, 'main-account');
    assert.equal(video.list.children[0].value, 'video-account');
    video.account.value = 'replacement-video'; video.account.listeners.change();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(video.input.value, '');
    assert.equal(video.list.children[0].value, 'replacement-video');
    assert.equal(main.input.value, '123');
    assert.equal(main.list.children[0].value, 'main-account');
});

test('Video Blade control directives remain recognizable by the Blade compiler', () => {
    const template = ['video-performance', 'video-performance-table'].map(name => fs.readFileSync(new URL(`../../resources/views/components/${name}.blade.php`, import.meta.url), 'utf8')).join('\n');
    // Laravel BladeCompiler::compileStatements starts directives with \B@.
    // A closing directive glued to a word is literal HTML, leaving invalid PHP.
    assert.doesNotMatch(template, /\w@(if|elseif|else|endif|foreach|endforeach|unless|endunless)\b/);
});
