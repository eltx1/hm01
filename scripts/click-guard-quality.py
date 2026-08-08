from pathlib import Path

loader = Path('public/assets/hm-loader.js')
source = loader.read_text()

old_read = '''    function readClickGuardState(config) {
        var guard = state.clickGuard;
        var settings = clickGuardSettings(config);
        var now = Date.now();
        if (!settings.enabled || !guard.storageAvailable || !window.localStorage) {
            guard.persisted = emptyClickGuardState();
            guard.blocked = false;
            return guard.persisted;
        }
        try {
            var raw = window.localStorage.getItem(guard.storageKey);
            if (!raw) {
                guard.persisted = emptyClickGuardState();
                guard.blocked = false;
                return guard.persisted;
            }
            var normalized = storageValue(config, raw, settings, now);
            guard.persisted = normalized;
            var normalizedJson = JSON.stringify(normalized);
            if (normalizedJson !== raw) window.localStorage.setItem(guard.storageKey, normalizedJson);
            return normalized;
        } catch (error) {
            guard.storageAvailable = false;
            guard.persisted = emptyClickGuardState();
            guard.blocked = false;
            log(config, 'Click Guard storage unavailable; failing open');
            return guard.persisted;
        }
    }

    function writeClickGuardState(config, value) {
        var guard = state.clickGuard;
        if (!guard.storageAvailable || !window.localStorage) return false;
        try {
            window.localStorage.setItem(guard.storageKey, JSON.stringify(value));
            guard.persisted = value;
            return true;
        } catch (error) {
            guard.storageAvailable = false;
            guard.persisted = emptyClickGuardState();
            guard.blocked = false;
            log(config, 'Click Guard storage write failed; failing open');
            return false;
        }
    }
'''
new_read = '''    function readClickGuardState(config) {
        var guard = state.clickGuard;
        var settings = clickGuardSettings(config);
        var now = Date.now();
        if (!settings.enabled || !guard.storageAvailable) {
            guard.persisted = emptyClickGuardState();
            guard.blocked = false;
            return guard.persisted;
        }
        try {
            var storage = window.localStorage;
            if (!storage) {
                guard.storageAvailable = false;
                guard.persisted = emptyClickGuardState();
                guard.blocked = false;
                return guard.persisted;
            }
            var raw = storage.getItem(guard.storageKey);
            if (!raw) {
                guard.persisted = emptyClickGuardState();
                guard.blocked = false;
                return guard.persisted;
            }
            var normalized = storageValue(config, raw, settings, now);
            guard.persisted = normalized;
            var normalizedJson = JSON.stringify(normalized);
            if (normalizedJson !== raw) storage.setItem(guard.storageKey, normalizedJson);
            return normalized;
        } catch (error) {
            guard.storageAvailable = false;
            guard.persisted = emptyClickGuardState();
            guard.blocked = false;
            log(config, 'Click Guard storage unavailable; failing open');
            return guard.persisted;
        }
    }

    function writeClickGuardState(config, value) {
        var guard = state.clickGuard;
        if (!guard.storageAvailable) return false;
        try {
            var storage = window.localStorage;
            if (!storage) {
                guard.storageAvailable = false;
                guard.persisted = emptyClickGuardState();
                guard.blocked = false;
                return false;
            }
            storage.setItem(guard.storageKey, JSON.stringify(value));
            guard.persisted = value;
            return true;
        } catch (error) {
            guard.storageAvailable = false;
            guard.persisted = emptyClickGuardState();
            guard.blocked = false;
            log(config, 'Click Guard storage write failed; failing open');
            return false;
        }
    }
'''
if source.count(old_read) != 1:
    raise SystemExit('Click Guard storage block did not match exactly once')
source = source.replace(old_read, new_read, 1)

old_clicks = '''        if (Array.isArray(value.clicks)) {
            clean.clicks = value.clicks.map(Number).filter(function (timestamp) {
                return Number.isFinite(timestamp) && timestamp >= 0 && timestamp >= windowStart && timestamp <= latestReasonableTimestamp;
            }).sort(function (left, right) { return left - right; });
        }
'''
new_clicks = '''        if (Array.isArray(value.clicks)) {
            clean.clicks = value.clicks.map(Number).filter(function (timestamp) {
                return Number.isFinite(timestamp) && timestamp >= 0 && timestamp >= windowStart && timestamp <= latestReasonableTimestamp;
            }).sort(function (left, right) { return left - right; }).slice(-settings.maxClicks);
        }
'''
if source.count(old_clicks) != 1:
    raise SystemExit('Click timestamp normalization block did not match exactly once')
source = source.replace(old_clicks, new_clicks, 1)

old_reset = '''        _resetForTests: function () {
            clearAllRefreshTimers();
            resetClickGuardRuntime();
            state.config = null;
'''
new_reset = '''        _resetForTests: function () {
            clearAllRefreshTimers();
            resetClickGuardRuntime();
            if (state.observer && state.observer.disconnect) state.observer.disconnect();
            state.observer = null;
            if (state.scanTimer) window.clearTimeout(state.scanTimer);
            state.scanTimer = null;
            state.config = null;
'''
if source.count(old_reset) != 1:
    raise SystemExit('Loader reset block did not match exactly once')
source = source.replace(old_reset, new_reset, 1)
loader.write_text(source)

browser = Path('tests/Browser/hm-loader-click-guard.test.js')
test_source = browser.read_text()
old_signature = "function createHarness(config, { storage = memoryStorage(), containers = null } = {}) {"
new_signature = "function createHarness(config, { storage = memoryStorage(), containers = null, storageAccessThrows = false } = {}) {"
if test_source.count(old_signature) != 1:
    raise SystemExit('Browser harness signature did not match exactly once')
test_source = test_source.replace(old_signature, new_signature, 1)

old_storage = "        localStorage: storage, navigator: {}, location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },"
new_storage = "        navigator: {}, location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },"
if test_source.count(old_storage) != 1:
    raise SystemExit('Browser harness localStorage field did not match exactly once')
test_source = test_source.replace(old_storage, new_storage, 1)

old_window = "    sandbox.window = sandbox;\n    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });"
new_window = """    if (storageAccessThrows) {
        Object.defineProperty(sandbox, 'localStorage', {
            configurable: true,
            get() { throw new DOMException('Denied', 'SecurityError'); },
        });
    } else {
        sandbox.localStorage = storage;
    }
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });"""
if test_source.count(old_window) != 1:
    raise SystemExit('Browser harness sandbox finalization did not match exactly once')
test_source = test_source.replace(old_window, new_window, 1)

marker = """test('localStorage SecurityError fails open and ads continue', async () => {
    const harness = createHarness(activeConfig(), { storage: memoryStorage({}, true) });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.equal(harness.metrics.defined, 1);
});
"""
extra = marker + """
test('localStorage property access SecurityError also fails open', async () => {
    const harness = createHarness(activeConfig(), { storageAccessThrows: true });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.equal(harness.metrics.defined, 1);
});
"""
if test_source.count(marker) != 1:
    raise SystemExit('SecurityError test marker did not match exactly once')
browser.write_text(test_source.replace(marker, extra, 1))

print('Click Guard quality hardening applied.')
