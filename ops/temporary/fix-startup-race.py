from pathlib import Path

p = Path('scripts/transform-loader-video-mount-history.mjs')
s = p.read_text()
def replace_once(old, new):
    global s
    assert s.count(old) == 1, old[:120]
    s = s.replace(old, new, 1)
replace_once('var records = [], epoch = 0, frame = null, stopped = false;', 'var records = [], subscriptions = [], epoch = 0, frame = null, stopped = false;')
replace_once('            records.forEach(sample);', '''            records.forEach(sample);
            // Wake a previously visited video if it passed between registration
            // and the first asynchronous IntersectionObserver delivery.
            var ready = [];
            subscriptions = subscriptions.filter(function (item) {
                if (item.cancelled) return false;
                var record = records.find(function (r) { return r.element === item.element && live(r); });
                if (record && record.seen && record.scrolled) { item.cancelled = true; ready.push(item.callback); return false; }
                return item.element.isConnected !== false;
            });
            // Remove before callbacks: a callback may synchronously read history.
            ready.forEach(function (callback) { try { callback(); } catch (error) {} });''')
replace_once('        var api = {', '''        var api = {
            whenPassed: function (element, callback) {
                if (stopped || typeof callback !== 'function' || subscriptions.length >= 64) return function () {};
                var item = { element: element, callback: callback, cancelled: false };
                subscriptions.push(item); schedule();
                return function () { item.cancelled = true; };
            },''')
replace_once('records = []; state.videoMountHistory = null;', 'records = []; subscriptions = []; state.videoMountHistory = null;')
replace_once('    installVideoMountHistory();\n`;', '''    function watchPendingVideoPass(entry, observer, callback) {
        var settings = entry && entry.placement && entry.placement.format && entry.placement.format.settings || {};
        var history = state.videoMountHistory;
        if (!entry || !entry.placement || entry.placement.type !== 'VIDEO' || settings.position !== 'inline_to_bottom_right'
            || !history || typeof history.whenPassed !== 'function') return;
        var disconnect = observer.disconnect.bind(observer);
        var cancel = history.whenPassed(entry.element, function () {
            if (pendingFloatingVideoWasVisited(entry)) callback();
        });
        observer.disconnect = function () { cancel(); disconnect(); };
    }

    installVideoMountHistory();
`;''')
old = "    source = source.slice(0, at) + source.slice(at).replace(lazy, lazy + '        if (pendingFloatingVideoWasVisited(entry)) return runNativeFallback(config, entry);\\n');"
replace_once(old, '''    const end = source.indexOf('    function candidateRank(', start);
    if (end < at) throw new Error('Direct lazy boundary changed');
    var direct = source.slice(start, end);
    direct = direct.replace(lazy, lazy + '        if (pendingFloatingVideoWasVisited(entry)) return runNativeFallback(config, entry);\\n');
    direct = direct.replace('            var observer = new window.IntersectionObserver', `            var begun = false;
            function begin() {
                if (begun) return;
                begun = true;
                observer.disconnect();
                if (state.directObservers) delete state.directObservers[key];
                runNativeFallback(config, entry);
            }
            var observer = new window.IntersectionObserver`);
    direct = direct.replace(`                if (!nearViewport) return;
                observer.disconnect();
                if (state.directObservers) delete state.directObservers[key];
                runNativeFallback(config, entry);`, `                if (nearViewport || pendingFloatingVideoWasVisited(entry)) begin();`);
    direct = direct.replace('            observer.observe(entry.element);', '            watchPendingVideoPass(entry, observer, begin);\\n            observer.observe(entry.element);');
    source = source.slice(0, start) + direct + source.slice(end);''')
p.write_text(s)
p = Path('tests/Browser/helpers/video-startup-cases.mjs')
s = p.read_text()
s = s.replace('export async function openStartup(page, options = {}) {', 'export async function openStartup(page, options = {}) {\n    page.setDefaultTimeout(7000);')
old = "this.frame.srcdoc = '<body>Offline IMA boundary</body>';"
assert old in s
s = s.replace(old, "this.frame.src = 'https://reader.example/offline-ima';")
old = "        if (u.origin === READER && u.pathname === '/content.mp4')"
assert old in s
s = s.replace(old, "        if (u.origin === READER && u.pathname === '/offline-ima') return route.fulfill({ contentType: 'text/html', body: '<body>Offline IMA boundary</body>' });\n" + old)
p.write_text(s)
p = Path('tests/Browser/hm-loader-video-mount-history.test.js')
p.write_text(p.read_text() + '''

test('pending lazy work wakes once even when the slot passes before first intersection delivery', () => {
    const h = harness(); let calls = 0;
    h.api.whenPassed(h.nodes[0], () => { calls++; h.api.read(h.nodes[0], false); });
    h.event('scroll', 1800); h.event('scroll', 1900);
    assert.equal(calls, 1); assert.equal(h.network(), 0);
});
test('cancelled lazy work cannot start on a later scroll', () => {
    const h = harness(); let calls = 0;
    const cancel = h.api.whenPassed(h.nodes[0], () => calls++);
    cancel(); h.event('scroll', 1800); assert.equal(calls, 0);
});
''')
