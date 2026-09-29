from pathlib import Path
r=Path('.')
p=r/'public/assets/hm-loader.js';s=p.read_text()
anchor='    function maybeDelegateRelease(config, script) {'
assert s.count(anchor)==1
helper='''    function isCurrentCanonicalLoaderAlias(selected, script) {
        // StaticDeliverySnapshotBuilder publishes these two aliases from the
        // SAME compiled byte string. A stale database version label cannot
        // select an older release through either alias. Keep genuine pinned,
        // custom-origin and query-specific release handoffs unchanged.
        try {
            var current = new URL(String(script && script.src || ''));
            var target = new URL(String(selected.assetUrl || ''));
            var origin = 'https://cdn.horusmedia.net';
            var aliases = ['/hm-loader.js', '/assets/hm-loader.min.js'];
            return current.origin === origin && target.origin === origin
                && !current.username && !current.password && !target.username && !target.password
                && aliases.indexOf(current.pathname) !== -1 && aliases.indexOf(target.pathname) !== -1
                && !target.search && !target.hash;
        } catch (error) { return false; }
    }

'''
s=s.replace(anchor,helper+anchor)
old="        if (!selected.assetUrl || !selected.version || selected.version === VERSION) return null;"
assert s.count(old)==1
s=s.replace(old,old+"\n        if (isCurrentCanonicalLoaderAlias(selected, script)) return null;")
p.write_text(s)
p=r/'tests/Browser/hm-loader.test.js';s=p.read_text()
# These existing four tests cover real handoff, including failure and refresh.
# Use an actual distinct release path rather than an alias of the running file.
s=s.replace("assetUrl: 'https://cdn.horusmedia.net/assets/hm-loader.min.js'", "assetUrl: 'https://cdn.horusmedia.net/releases/hm-loader.1.3.0.min.js'")
old="/\\/assets\\/hm-loader\\.min\\.js(?:\\?|$)/"
assert s.count(old)==1
s=s.replace(old,"/\\/(?:assets\\/hm-loader|releases\\/hm-loader\\.1\\.3\\.0)\\.min\\.js(?:\\?|$)/")
s=s.replace('    runtimeSource = loaderSource,','    runtimeSource = loaderSource,\n    scriptUrl = \'https://cdn.horusmedia.net/hm-loader.js\',')
s=s.replace("        src: 'https://cdn.horusmedia.net/hm-loader.js',",'        src: scriptUrl,')
s+='''
for (const [mode, runtimeSource] of [['source', loaderSource], ['built', builtLoaderSource]]) {
    for (const current of ['/hm-loader.js', '/assets/hm-loader.min.js']) {
        for (const target of ['/hm-loader.js', '/assets/hm-loader.min.js']) {
            test(`${mode}: canonical aliases skip redundant Loader handoff ${current} -> ${target}`, async () => {
                const config = activeConfig({ loader: { version: '1.3.0', assetUrl: 'https://cdn.horusmedia.net' + target } });
                const { sandbox, metrics } = createHarness(config, { runtimeSource,
                    scriptUrl: 'https://cdn.horusmedia.net' + current,
                    delegatedLoader: true, deferredDelegatedLoader: true, manualDelegatedTimeout: true });
                const boot = sandbox.HorusMediaLoader.boot();
                for (let i = 0; i < 10; i++) await new Promise(resolve => setImmediate(resolve));
                assert.equal(metrics.delegatedLoads, 0, 'must not fetch the same compiled runtime again');
                assert.equal(sandbox.__HM_RELEASE_DELEGATED__, undefined);
                await boot;
                assert.equal(metrics.gptLoads, 1); assert.equal(metrics.defined.length, 1);
                assert.equal(metrics.fetches.filter(url => url.includes('/_global/control.json')).length, 1);
                assert.equal(metrics.fetches.filter(url => url.includes('/manifest.json')).length, 1);
            });
        }
    }
}
test('canonical Loader identity does not bypass inactive site or hostname controls', async () => {
    for (const overrides of [{ status: 'paused' }, { immediatePause: true }, { allowedHostnames: ['elsewhere.example'] }]) {
        const { sandbox, metrics } = createHarness(activeConfig({ ...overrides,
            loader: { version: '1.3.0', assetUrl: 'https://cdn.horusmedia.net/assets/hm-loader.min.js' } }), { runtimeSource: builtLoaderSource });
        await sandbox.HorusMediaLoader.boot(); assert.equal(metrics.gptLoads, 0);
    }
});
test('the canonical alias shortcut excludes pinned releases, custom origins and selected queries', () => {
    const begin = loaderSource.indexOf('    function isCurrentCanonicalLoaderAlias(');
    const end = loaderSource.indexOf('    function maybeDelegateRelease(', begin);
    assert.ok(begin >= 0 && end > begin);
    const scope = { URL }; vm.runInNewContext(loaderSource.slice(begin, end), scope);
    const script = { src: 'https://cdn.horusmedia.net/hm-loader.js' };
    for (const assetUrl of [
        'https://cdn.horusmedia.net/releases/hm-loader.1.3.0.min.js',
        'https://cdn.horusmedia.net/assets/loader/hm-loader.abcdef0123456789.min.js',
        'https://custom.example/assets/hm-loader.min.js',
        'https://cdn.horusmedia.net/assets/hm-loader.min.js?release=pinned',
        'https://user:password@cdn.horusmedia.net/assets/hm-loader.min.js',
        'http://cdn.horusmedia.net/assets/hm-loader.min.js',
    ]) assert.equal(scope.isCurrentCanonicalLoaderAlias({ assetUrl }, script), false);
    assert.equal(scope.isCurrentCanonicalLoaderAlias({ assetUrl: 'https://cdn.horusmedia.net/assets/hm-loader.min.js' }, { src: 'https://custom.example/hm-loader.js' }), false);
});
'''
p.write_text(s)
p=r/'tests/Feature/StaticTrafficGateOriginTest.php';s=p.read_text()
needle="            $snapshot->files['hm-loader.js'],\n        );"
assert s.count(needle)==1
s=s.replace(needle,needle+"\n        $this->assertSame($snapshot->files['hm-loader.js'], $snapshot->files['assets/hm-loader.min.js']);")
p.write_text(s)
p=r/'docs/AD_STARTUP_DIAGNOSTICS.md';p.write_text(p.read_text()+'''\n## Canonical alias self-handoff correction\n\nThe captured Bluekl public config v17 calls the canonical current asset version 1.3.0, while the shipped source is 2.0.0. Both canonical paths are deliberately the same compiled bytes in StaticDeliverySnapshotBuilder. The old version-label mismatch caused a second runtime evaluation and fresh control/config/privacy reads after PASS, matching the extra Loader and config requests in the supplied waterfall. The runtime now skips only that same-canonical-alias handoff. Distinct pinned paths, custom origins and selected query strings retain their original handoff and fail-closed behavior. No persisted configuration or release assignment is edited.\n\nTests bind the shortcut to the actual snapshot alias contract and preserve the existing genuine-handoff tests using a distinct release path. This removes a verified redundant wait, but does not quantify or prove the entire reported five-minute delay.\n''')
