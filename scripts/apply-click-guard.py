from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path):
    return (ROOT / path).read_text()


def write(path, content):
    target = ROOT / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content)


def replace_once(path, old, new):
    text = read(path)
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"Expected one match in {path}, found {count}: {old[:80]!r}")
    write(path, text.replace(old, new, 1))


# 1. Persistence model.
replace_once(
    'app/Models/SiteConfig.php',
    "        'privacy_settings', 'gpt_settings', 'supply_chain_settings', 'observability_settings',\n",
    "        'privacy_settings', 'gpt_settings', 'supply_chain_settings', 'observability_settings', 'click_guard_settings',\n",
)
replace_once(
    'app/Models/SiteConfig.php',
    "            'supply_chain_settings' => 'array', 'observability_settings' => 'array',\n",
    "            'supply_chain_settings' => 'array', 'observability_settings' => 'array',\n            'click_guard_settings' => 'array',\n",
)

migration = r'''<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_configs', function (Blueprint $table): void {
            $table->json('click_guard_settings')->nullable()->after('observability_settings');
        });
    }

    public function down(): void
    {
        Schema::table('site_configs', function (Blueprint $table): void {
            $table->dropColumn('click_guard_settings');
        });
    }
};
'''
write('database/migrations/2026_08_09_000000_add_click_guard_settings_to_site_configs.php', migration)

# 2. Controller validation and persistence. Fields are optional at the HTTP contract
# level so older clients remain compatible; the Delivery Settings UI always submits them.
replace_once(
    'app/Http/Controllers/Admin/SiteConfigController.php',
    "            'single_request_mode' => ['sometimes', 'boolean'],\n            'cache_ttl_seconds' => ['required', 'integer', 'between:0,86400'],\n",
    "            'single_request_mode' => ['sometimes', 'boolean'],\n            'click_guard_enabled' => ['sometimes', 'boolean'],\n            'click_guard_max_clicks' => ['sometimes', 'integer', 'between:1,50'],\n            'click_guard_window_hours' => ['sometimes', 'integer', 'between:1,168'],\n            'click_guard_block_hours' => ['sometimes', 'integer', 'between:1,720'],\n            'cache_ttl_seconds' => ['required', 'integer', 'between:0,86400'],\n",
)
replace_once(
    'app/Http/Controllers/Admin/SiteConfigController.php',
    "            $before = $config->toArray();\n            $config->update([\n",
    "            $before = $config->toArray();\n            $existingClickGuard = $config->click_guard_settings ?? [];\n            $config->update([\n",
)
replace_once(
    'app/Http/Controllers/Admin/SiteConfigController.php',
    "                'observability_settings' => $data['observability_settings'],\n",
    "                'observability_settings' => $data['observability_settings'],\n                'click_guard_settings' => [\n                    'enabled' => $request->has('click_guard_enabled')\n                        ? $request->boolean('click_guard_enabled')\n                        : (bool) data_get($existingClickGuard, 'enabled', false),\n                    'maxClicks' => (int) ($data['click_guard_max_clicks'] ?? data_get($existingClickGuard, 'maxClicks', 3)),\n                    'windowHours' => (int) ($data['click_guard_window_hours'] ?? data_get($existingClickGuard, 'windowHours', 6)),\n                    'blockHours' => (int) ($data['click_guard_block_hours'] ?? data_get($existingClickGuard, 'blockHours', 12)),\n                ],\n",
)

# 3. Static public configuration.
replace_once(
    'app/Services/Inventory/SiteConfigurationBuilder.php',
    "            'houseAdTesting' => (bool) $config->house_ad_testing,\n            'allowedHostnames' => $this->hostnames($site),\n",
    "            'houseAdTesting' => (bool) $config->house_ad_testing,\n            'clickGuard' => $this->clickGuard($config->click_guard_settings),\n            'allowedHostnames' => $this->hostnames($site),\n",
)
replace_once(
    'app/Services/Inventory/SiteConfigurationBuilder.php',
    "    private function placement(\n",
    r'''    private function clickGuard(?array $settings): array
    {
        $settings ??= [];
        $enabled = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return [
            'enabled' => $enabled ?? false,
            'maxClicks' => $this->boundedInteger($settings['maxClicks'] ?? null, 3, 1, 50),
            'windowHours' => $this->boundedInteger($settings['windowHours'] ?? null, 6, 1, 168),
            'blockHours' => $this->boundedInteger($settings['blockHours'] ?? null, 12, 1, 720),
        ];
    }

    private function boundedInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false) {
            return $default;
        }

        return max($minimum, min($maximum, $validated));
    }

    private function placement(
''',
)

# 4. Delivery Settings UI.
replace_once(
    'resources/views/admin/inventory/index.blade.php',
    "        <label><input type=\"hidden\" name=\"single_request_mode\" value=\"0\"><input type=\"checkbox\" name=\"single_request_mode\" value=\"1\" @checked($site->siteConfig?->single_request_mode ?? true)> GPT single-request mode</label>\n",
    "        <label><input type=\"hidden\" name=\"single_request_mode\" value=\"0\"><input type=\"checkbox\" name=\"single_request_mode\" value=\"1\" @checked($site->siteConfig?->single_request_mode ?? true)> GPT single-request mode</label>\n        @php($clickGuard = $site->siteConfig?->click_guard_settings ?? [])\n        <div>\n            <p class=\"eyebrow\">Click protection</p>\n            <p class=\"muted\">Temporarily stops new advertising requests in this browser after repeated detected interactions with Horus-managed advertising iframes. Detection is heuristic and browser-dependent.</p>\n            <label><input type=\"hidden\" name=\"click_guard_enabled\" value=\"0\"><input type=\"checkbox\" name=\"click_guard_enabled\" value=\"1\" @checked((bool) old('click_guard_enabled', $clickGuard['enabled'] ?? false))> Enable Click Protection</label>\n            <label>Maximum detected ad clicks<input class=\"hm-input\" type=\"number\" min=\"1\" max=\"50\" name=\"click_guard_max_clicks\" value=\"{{ old('click_guard_max_clicks', $clickGuard['maxClicks'] ?? 3) }}\" required></label>\n            <label>Within<input class=\"hm-input\" type=\"number\" min=\"1\" max=\"168\" name=\"click_guard_window_hours\" value=\"{{ old('click_guard_window_hours', $clickGuard['windowHours'] ?? 6) }}\" required><span class=\"muted\">Hours</span></label>\n            <label>Block ads for<input class=\"hm-input\" type=\"number\" min=\"1\" max=\"720\" name=\"click_guard_block_hours\" value=\"{{ old('click_guard_block_hours', $clickGuard['blockHours'] ?? 12) }}\" required><span class=\"muted\">Hours</span></label>\n        </div>\n",
)

# 5. Horus Loader browser-local module.
replace_once(
    'public/assets/hm-loader.js',
    "        privacyDecision: null,\n        rewardedListenersInstalled: false\n    };\n",
    "        privacyDecision: null,\n        rewardedListenersInstalled: false,\n        clickGuard: null\n    };\n\n    var CLICK_GUARD_STATE_VERSION = 1;\n    var CLICK_GUARD_STORAGE_PREFIX = 'hm:click-guard:v1:';\n    var CLICK_GUARD_HOUR_MS = 60 * 60 * 1000;\n    var CLICK_GUARD_DEBOUNCE_MS = 400;\n    var CLICK_GUARD_MAX_TIMEOUT_MS = 2147483647;\n\n    function freshClickGuardRuntime() {\n        return {\n            storageKey: null, settings: null, persisted: { v: CLICK_GUARD_STATE_VERSION, clicks: [], blockedUntil: 0 },\n            storageAvailable: true, blocked: false, trackedIframes: typeof WeakSet !== 'undefined' ? new WeakSet() : null,\n            trackedIframeEntries: [], activeIframe: null, armed: false, lastClickAt: 0, listenersInstalled: false,\n            blurListener: null, storageListener: null, blockTimer: null\n        };\n    }\n\n    state.clickGuard = state.clickGuard || freshClickGuardRuntime();\n",
)

click_guard_module = r'''
    function boundedInteger(value, fallback, minimum, maximum) {
        var numeric = Number(value);
        if (!Number.isFinite(numeric) || Math.floor(numeric) !== numeric) return fallback;
        return Math.max(minimum, Math.min(maximum, numeric));
    }

    function clickGuardSettings(config) {
        var selected = config && config.clickGuard || {};
        return {
            enabled: selected.enabled === true,
            maxClicks: boundedInteger(selected.maxClicks, 3, 1, 50),
            windowHours: boundedInteger(selected.windowHours, 6, 1, 168),
            blockHours: boundedInteger(selected.blockHours, 12, 1, 720)
        };
    }

    function clickGuardStorageKey(config) {
        return CLICK_GUARD_STORAGE_PREFIX + String(config && config.siteKey || '');
    }

    function emptyClickGuardState() {
        return { v: CLICK_GUARD_STATE_VERSION, clicks: [], blockedUntil: 0 };
    }

    function normalizeClickGuardState(value, settings, now) {
        var clean = emptyClickGuardState();
        if (!value || typeof value !== 'object' || Number(value.v) !== CLICK_GUARD_STATE_VERSION) return clean;
        var windowStart = now - settings.windowHours * CLICK_GUARD_HOUR_MS;
        var latestReasonableTimestamp = now + 5 * 60 * 1000;
        if (Array.isArray(value.clicks)) {
            clean.clicks = value.clicks.map(Number).filter(function (timestamp) {
                return Number.isFinite(timestamp) && timestamp >= 0 && timestamp >= windowStart && timestamp <= latestReasonableTimestamp;
            }).sort(function (left, right) { return left - right; });
        }
        var blockedUntil = Number(value.blockedUntil || 0);
        var maximumBlock = now + 720 * CLICK_GUARD_HOUR_MS + 5 * 60 * 1000;
        if (Number.isFinite(blockedUntil) && blockedUntil > now && blockedUntil <= maximumBlock) {
            clean.blockedUntil = blockedUntil;
            return clean;
        }
        if (Number.isFinite(blockedUntil) && blockedUntil > 0 && blockedUntil <= now) {
            return emptyClickGuardState();
        }
        return clean;
    }

    function storageValue(config, raw, settings, now) {
        try {
            return normalizeClickGuardState(JSON.parse(raw), settings, now);
        } catch (error) {
            return emptyClickGuardState();
        }
    }

    function readClickGuardState(config) {
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

    function clearAllRefreshTimers() {
        Object.keys(state.refreshTimers).forEach(function (key) {
            window.clearInterval(state.refreshTimers[key]);
            delete state.refreshTimers[key];
        });
    }

    function clearClickGuardBlockTimer() {
        if (!state.clickGuard.blockTimer) return;
        window.clearTimeout(state.clickGuard.blockTimer);
        state.clickGuard.blockTimer = null;
    }

    function scheduleClickGuardBlockExpiry(config, blockedUntil) {
        clearClickGuardBlockTimer();
        if (!blockedUntil || blockedUntil <= Date.now()) return;
        var delay = Math.min(CLICK_GUARD_MAX_TIMEOUT_MS, Math.max(1, blockedUntil - Date.now()));
        state.clickGuard.blockTimer = window.setTimeout(function () {
            state.clickGuard.blockTimer = null;
            var persisted = readClickGuardState(config);
            if (persisted.blockedUntil > Date.now()) {
                scheduleClickGuardBlockExpiry(config, persisted.blockedUntil);
                return;
            }
            state.clickGuard.blocked = false;
            if (canRequestAds(config)) {
                installSpaSupport();
                scan(config);
            }
        }, delay);
    }

    function applyClickGuardState(config, persisted) {
        var blocked = Boolean(persisted && persisted.blockedUntil > Date.now());
        if (blocked) {
            if (!state.clickGuard.blocked) clearAllRefreshTimers();
            state.clickGuard.blocked = true;
            scheduleClickGuardBlockExpiry(config, persisted.blockedUntil);
        } else {
            state.clickGuard.blocked = false;
            clearClickGuardBlockTimer();
        }
        return blocked;
    }

    function clickGuardBlocked(config) {
        var settings = clickGuardSettings(config);
        if (!settings.enabled) return false;
        if (!state.clickGuard.storageAvailable) return false;
        return applyClickGuardState(config, readClickGuardState(config));
    }

    function canRequestAds(config) {
        if (!config || config.status !== 'active' || config.immediatePause || servingDisabled(config)) return false;
        if (state.privacyDecision && state.privacyDecision.blocked) return false;
        return !clickGuardBlocked(config);
    }

    function managedPlacementContainers(config) {
        var active = {};
        (config && config.placements || []).forEach(function (placement) {
            if (placement && placement.enabled && placement.status === 'active') active[String(placement.code)] = true;
        });
        var result = [];
        ['.hm-ad[data-placement]', '.hm-native[data-placement]'].forEach(function (selector) {
            Array.prototype.forEach.call(nodeList(selector), function (node) {
                var code = node && node.getAttribute ? node.getAttribute('data-placement') : null;
                if (code && active[String(code)] && result.indexOf(node) === -1) result.push(node);
            });
        });
        return result;
    }

    function isIframe(node) {
        return Boolean(node && String(node.tagName || '').toLowerCase() === 'iframe');
    }

    function containerContains(container, node) {
        if (!container || !node) return false;
        if (typeof container.contains === 'function') return container.contains(node);
        var current = node;
        while (current) {
            if (current === container) return true;
            current = current.parentNode;
        }
        return false;
    }

    function isEligibleClickGuardIframe(config, iframe) {
        if (!isIframe(iframe)) return false;
        return managedPlacementContainers(config).some(function (container) { return containerContains(container, iframe); });
    }

    function disarmClickGuardIframe(iframe) {
        if (!iframe || state.clickGuard.activeIframe === iframe) {
            state.clickGuard.activeIframe = null;
            state.clickGuard.armed = false;
        }
    }

    function untrackClickGuardIframe(iframe) {
        var entries = state.clickGuard.trackedIframeEntries;
        for (var index = entries.length - 1; index >= 0; index -= 1) {
            var entry = entries[index];
            if (entry.iframe !== iframe) continue;
            if (entry.iframe.removeEventListener) {
                entry.iframe.removeEventListener(entry.enterEvent, entry.enter);
                entry.iframe.removeEventListener(entry.leaveEvent, entry.leave);
            }
            entries.splice(index, 1);
        }
        if (state.clickGuard.trackedIframes && state.clickGuard.trackedIframes.delete) state.clickGuard.trackedIframes.delete(iframe);
        disarmClickGuardIframe(iframe);
    }

    function iframeNodes(node) {
        var frames = [];
        if (isIframe(node)) frames.push(node);
        if (node && node.querySelectorAll) {
            Array.prototype.forEach.call(node.querySelectorAll('iframe'), function (iframe) {
                if (frames.indexOf(iframe) === -1) frames.push(iframe);
            });
        }
        return frames;
    }

    function trackClickGuardIframe(config, iframe) {
        if (!state.clickGuard.settings || !state.clickGuard.settings.enabled || !isEligibleClickGuardIframe(config, iframe)) return;
        if (state.clickGuard.trackedIframes && state.clickGuard.trackedIframes.has(iframe)) return;
        var usePointer = typeof window.PointerEvent === 'function';
        var enterEvent = usePointer ? 'pointerenter' : 'mouseenter';
        var leaveEvent = usePointer ? 'pointerleave' : 'mouseleave';
        var enter = function () {
            if (!canRequestAds(config) || !isEligibleClickGuardIframe(config, iframe)) return;
            state.clickGuard.activeIframe = iframe;
            state.clickGuard.armed = true;
        };
        var leave = function () { disarmClickGuardIframe(iframe); };
        if (!iframe.addEventListener) return;
        iframe.addEventListener(enterEvent, enter);
        iframe.addEventListener(leaveEvent, leave);
        if (state.clickGuard.trackedIframes) state.clickGuard.trackedIframes.add(iframe);
        state.clickGuard.trackedIframeEntries.push({ iframe: iframe, enterEvent: enterEvent, leaveEvent: leaveEvent, enter: enter, leave: leave });
    }

    function discoverClickGuardIframes(config) {
        if (!state.clickGuard.settings || !state.clickGuard.settings.enabled) return;
        managedPlacementContainers(config).forEach(function (container) {
            iframeNodes(container).forEach(function (iframe) { trackClickGuardIframe(config, iframe); });
        });
    }

    function inspectClickGuardMutations(config, mutations) {
        if (!state.clickGuard.settings || !state.clickGuard.settings.enabled || !Array.isArray(mutations) && !mutations.length) return;
        Array.prototype.forEach.call(mutations || [], function (mutation) {
            Array.prototype.forEach.call(mutation.removedNodes || [], function (node) {
                iframeNodes(node).forEach(untrackClickGuardIframe);
            });
            Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
                iframeNodes(node).forEach(function (iframe) { trackClickGuardIframe(config, iframe); });
            });
        });
    }

    function recordProbableAdClick(config) {
        var guard = state.clickGuard;
        var settings = clickGuardSettings(config);
        if (!settings.enabled || !guard.storageAvailable) return false;
        var now = Date.now();
        var persisted = readClickGuardState(config);
        if (!guard.storageAvailable || applyClickGuardState(config, persisted)) return false;
        var windowStart = now - settings.windowHours * CLICK_GUARD_HOUR_MS;
        var clicks = persisted.clicks.filter(function (timestamp) { return timestamp >= windowStart && timestamp <= now + 5 * 60 * 1000; });
        clicks.push(now);
        if (clicks.length >= settings.maxClicks) {
            var blockedState = { v: CLICK_GUARD_STATE_VERSION, clicks: [], blockedUntil: now + settings.blockHours * CLICK_GUARD_HOUR_MS };
            if (!writeClickGuardState(config, blockedState)) return false;
            applyClickGuardState(config, blockedState);
            diagnostics(config, []);
            return true;
        }
        writeClickGuardState(config, { v: CLICK_GUARD_STATE_VERSION, clicks: clicks, blockedUntil: 0 });
        diagnostics(config, []);
        return false;
    }

    function installClickGuardListeners(config) {
        if (state.clickGuard.listenersInstalled || !window.addEventListener) return;
        state.clickGuard.blurListener = function () {
            var guard = state.clickGuard;
            var iframe = guard.activeIframe;
            if (!guard.armed || !iframe || !canRequestAds(state.config)) return;
            if (document.visibilityState && document.visibilityState !== 'visible') {
                disarmClickGuardIframe(iframe);
                return;
            }
            if (!isEligibleClickGuardIframe(state.config, iframe)) {
                disarmClickGuardIframe(iframe);
                return;
            }
            var activeElement = document.activeElement;
            if (activeElement && isIframe(activeElement) && activeElement !== iframe) {
                disarmClickGuardIframe(iframe);
                return;
            }
            var now = Date.now();
            disarmClickGuardIframe(iframe);
            if (now - guard.lastClickAt < CLICK_GUARD_DEBOUNCE_MS) return;
            guard.lastClickAt = now;
            recordProbableAdClick(state.config);
        };
        state.clickGuard.storageListener = function (event) {
            if (!state.config || !state.clickGuard.settings || !state.clickGuard.settings.enabled) return;
            if (!event || event.key !== state.clickGuard.storageKey) return;
            var wasBlocked = state.clickGuard.blocked;
            var settings = clickGuardSettings(state.config);
            var persisted = emptyClickGuardState();
            if (event.newValue) persisted = storageValue(state.config, event.newValue, settings, Date.now());
            state.clickGuard.persisted = persisted;
            var blocked = applyClickGuardState(state.config, persisted);
            if (wasBlocked && !blocked && canRequestAds(state.config)) {
                installSpaSupport();
                scan(state.config);
            }
        };
        window.addEventListener('blur', state.clickGuard.blurListener);
        window.addEventListener('storage', state.clickGuard.storageListener);
        state.clickGuard.listenersInstalled = true;
    }

    function resetClickGuardRuntime() {
        var guard = state.clickGuard || freshClickGuardRuntime();
        clearClickGuardBlockTimer();
        (guard.trackedIframeEntries || []).slice().forEach(function (entry) { untrackClickGuardIframe(entry.iframe); });
        if (guard.listenersInstalled && window.removeEventListener) {
            if (guard.blurListener) window.removeEventListener('blur', guard.blurListener);
            if (guard.storageListener) window.removeEventListener('storage', guard.storageListener);
        }
        state.clickGuard = freshClickGuardRuntime();
    }

    function initializeClickGuard(config) {
        var settings = clickGuardSettings(config);
        var key = clickGuardStorageKey(config);
        if (state.clickGuard.storageKey && state.clickGuard.storageKey !== key) resetClickGuardRuntime();
        if (!settings.enabled) {
            if (state.clickGuard.listenersInstalled || state.clickGuard.trackedIframeEntries.length) resetClickGuardRuntime();
            state.clickGuard.storageKey = key;
            state.clickGuard.settings = settings;
            return false;
        }
        state.clickGuard.storageKey = key;
        state.clickGuard.settings = settings;
        state.clickGuard.storageAvailable = true;
        installClickGuardListeners(config);
        return applyClickGuardState(config, readClickGuardState(config));
    }
'''
replace_once(
    'public/assets/hm-loader.js',
    "    function ensureGoogletagQueue() {\n",
    click_guard_module + "\n    function ensureGoogletagQueue() {\n",
)

# Harden request entry points. Function declarations are hoisted, so canRequestAds
# can safely call servingDisabled even though it appears later in the source.
replace_once(
    'public/assets/hm-loader.js',
    "    function loadGpt(config) {\n        var googletag = ensureGoogletagQueue();\n",
    "    function loadGpt(config) {\n        if (!canRequestAds(config)) return Promise.resolve(null);\n        var googletag = ensureGoogletagQueue();\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "    function loadPrebid(config) {\n        var selected = config.prebid || {};\n",
    "    function loadPrebid(config) {\n        if (!canRequestAds(config)) return Promise.resolve(null);\n        var selected = config.prebid || {};\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "    function requestGam(pubads, entries) {\n        if (!entries.length || !pubads || !pubads.refresh) return;\n",
    "    function requestGam(config, pubads, entries) {\n        if (!canRequestAds(config) || !entries.length || !pubads || !pubads.refresh) return;\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "    function requestEntries(config, googletag, pubads, entries) {\n        if (!entries.length) return Promise.resolve([]);\n",
    "    function requestEntries(config, googletag, pubads, entries) {\n        if (!canRequestAds(config) || !entries.length) return Promise.resolve([]);\n",
)
# All requestGam calls in requestEntries use the config-aware gate.
loader = read('public/assets/hm-loader.js')
loader = loader.replace('requestGam(pubads, entries)', 'requestGam(config, pubads, entries)')
write('public/assets/hm-loader.js', loader)
replace_once(
    'public/assets/hm-loader.js',
    "        return loadPrebid(config).then(function (pbjs) {\n            return new Promise(function (resolve) {\n",
    "        return loadPrebid(config).then(function (pbjs) {\n            if (!pbjs || !canRequestAds(config)) return [];\n            return new Promise(function (resolve) {\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "                function complete(timedOut) {\n                    if (finished) return;\n                    finished = true;\n                    try {\n",
    "                function complete(timedOut) {\n                    if (finished) return;\n                    finished = true;\n                    if (!canRequestAds(config)) { resolve([]); return; }\n                    try {\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "                pbjs.que.push(function () {\n                    try {\n",
    "                pbjs.que.push(function () {\n                    if (!canRequestAds(config)) { resolve([]); return; }\n                    try {\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "        }).catch(function (error) {\n            log(config, 'Prebid unavailable; continuing with GAM', error);\n            entries.forEach(function (entry) { entry.element.setAttribute('data-hm-prebid', 'failed'); });\n            if (fallback) requestGam(config, pubads, entries);\n",
    "        }).catch(function (error) {\n            log(config, 'Prebid unavailable; continuing with GAM', error);\n            entries.forEach(function (entry) { entry.element.setAttribute('data-hm-prebid', 'failed'); });\n            if (fallback && canRequestAds(config)) requestGam(config, pubads, entries);\n",
)

replace_once(
    'public/assets/hm-loader.js',
    "    function runNativeFallback(config, entry) {\n        if (!entry || !entry.native || !entry.native.enabled) return Promise.resolve(false);\n",
    "    function runNativeFallback(config, entry) {\n        if (!canRequestAds(config) || !entry || !entry.native || !entry.native.enabled) return Promise.resolve(false);\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "            function tryCandidate(index) {\n                if (index >= candidates.length) {\n",
    "            function tryCandidate(index) {\n                if (!canRequestAds(config)) { resolve(false); return; }\n                if (index >= candidates.length) {\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "        pubads.addEventListener('slotRenderEnded', function (event) {\n            var entry = null;\n",
    "        pubads.addEventListener('slotRenderEnded', function (event) {\n            if (!canRequestAds(config)) return;\n            var entry = null;\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "    function defineItems(config, items) {\n        if (!items.length) return Promise.resolve([]);\n",
    "    function defineItems(config, items) {\n        if (!canRequestAds(config) || !items.length) return Promise.resolve([]);\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "        return loadGpt(config).then(function (googletag) {\n            return new Promise(function (resolve) {\n",
    "        return loadGpt(config).then(function (googletag) {\n            if (!googletag || !canRequestAds(config)) return [];\n            return new Promise(function (resolve) {\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "                googletag.cmd.push(function () {\n                    try {\n",
    "                googletag.cmd.push(function () {\n                    if (!canRequestAds(config)) { resolve(nativeOnly); return; }\n                    try {\n",
)

old_refresh = r'''    function scheduleRefresh(config, googletag, pubads, entry) {
        var refresh = entry.placement.refresh || {};
        var prebidRefresh = config.prebid && config.prebid.delivery && config.prebid.delivery.refreshBehavior || {};
        if (prebidRefresh.enabled === false) return;
        var minimum = Math.max(30, Number(prebidRefresh.minimumIntervalSeconds || 30));
        var interval = Number(refresh.intervalSeconds || 0);
        if (!refresh.enabled || interval < minimum) return;
        var limit = Number(refresh.limit || 0);
        var key = entry.element.id;
        if (state.refreshTimers[key]) window.clearInterval(state.refreshTimers[key]);
        state.refreshTimers[key] = window.setInterval(function () {
            if (document.visibilityState && document.visibilityState !== 'visible') return;
            if (limit > 0 && entry.refreshCount >= limit) {
                window.clearInterval(state.refreshTimers[key]);
                delete state.refreshTimers[key];
                return;
            }
            entry.refreshCount += 1;
            googletag.cmd.push(function () { requestEntries(config, googletag, pubads, [entry]); });
        }, interval * 1000);
    }
'''
new_refresh = r'''    function scheduleRefresh(config, googletag, pubads, entry) {
        if (!canRequestAds(config)) return;
        var refresh = entry.placement.refresh || {};
        var prebidRefresh = config.prebid && config.prebid.delivery && config.prebid.delivery.refreshBehavior || {};
        if (prebidRefresh.enabled === false) return;
        var minimum = Math.max(30, Number(prebidRefresh.minimumIntervalSeconds || 30));
        var interval = Number(refresh.intervalSeconds || 0);
        if (!refresh.enabled || interval < minimum) return;
        var limit = Number(refresh.limit || 0);
        var key = entry.element.id;
        if (state.refreshTimers[key]) window.clearInterval(state.refreshTimers[key]);
        state.refreshTimers[key] = window.setInterval(function () {
            if (!canRequestAds(config)) {
                window.clearInterval(state.refreshTimers[key]);
                delete state.refreshTimers[key];
                return;
            }
            if (document.visibilityState && document.visibilityState !== 'visible') return;
            if (limit > 0 && entry.refreshCount >= limit) {
                window.clearInterval(state.refreshTimers[key]);
                delete state.refreshTimers[key];
                return;
            }
            entry.refreshCount += 1;
            googletag.cmd.push(function () {
                if (canRequestAds(config)) requestEntries(config, googletag, pubads, [entry]);
            });
        }, interval * 1000);
    }
'''
replace_once('public/assets/hm-loader.js', old_refresh, new_refresh)

replace_once(
    'public/assets/hm-loader.js',
    "            nativeDemandEnabled: Boolean(config.nativeDemand && config.nativeDemand.enabled),\n            nativeRendered: Object.assign({}, state.nativeRendered),\n",
    "            nativeDemandEnabled: Boolean(config.nativeDemand && config.nativeDemand.enabled),\n            nativeRendered: Object.assign({}, state.nativeRendered),\n            clickGuard: {\n                enabled: Boolean(state.clickGuard.settings && state.clickGuard.settings.enabled),\n                blocked: Boolean(state.clickGuard.blocked),\n                clicksInWindow: state.clickGuard.persisted && state.clickGuard.persisted.clicks ? state.clickGuard.persisted.clicks.length : 0,\n                blockedUntil: state.clickGuard.blocked ? state.clickGuard.persisted.blockedUntil : null\n            },\n",
)
old_scan = r'''    function scan(config) {
        if (!config || config.status !== 'active' || config.immediatePause || servingDisabled(config)) return Promise.resolve([]);
        return defineItems(config, eligibleElements(config));
    }
'''
new_scan = r'''    function scan(config) {
        if (!canRequestAds(config)) return Promise.resolve([]);
        discoverClickGuardIframes(config);
        return defineItems(config, eligibleElements(config)).then(function (defined) {
            discoverClickGuardIframes(config);
            return defined;
        });
    }
'''
replace_once('public/assets/hm-loader.js', old_scan, new_scan)

replace_once(
    'public/assets/hm-loader.js',
    "            state.observer = new window.MutationObserver(function () {\n                window.clearTimeout(state.scanTimer);\n",
    "            state.observer = new window.MutationObserver(function (mutations) {\n                inspectClickGuardMutations(state.config, mutations || []);\n                window.clearTimeout(state.scanTimer);\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "            state.observer.observe(document.documentElement, { childList: true, subtree: true });\n        }\n",
    "            state.observer.observe(document.documentElement, { childList: true, subtree: true });\n            discoverClickGuardIframes(state.config);\n        }\n",
)

replace_once(
    'public/assets/hm-loader.js',
    "            if (state.privacyDecision && state.privacyDecision.blocked) {\n                log(config, 'Privacy gate blocked advertising after the bounded CMP timeout');\n                return [];\n            }\n            if (maybeDelegateRelease(config, script)) return [];\n",
    "            if (state.privacyDecision && state.privacyDecision.blocked) {\n                log(config, 'Privacy gate blocked advertising after the bounded CMP timeout');\n                return [];\n            }\n            initializeClickGuard(config);\n            if (!canRequestAds(config)) {\n                log(config, 'Click Guard blocked future advertising requests in this browser');\n                return [];\n            }\n            if (maybeDelegateRelease(config, script)) return [];\n",
)
replace_once(
    'public/assets/hm-loader.js',
    "        _resetForTests: function () {\n            Object.keys(state.refreshTimers).forEach(function (key) { window.clearInterval(state.refreshTimers[key]); });\n            state.config = null;\n",
    "        _resetForTests: function () {\n            clearAllRefreshTimers();\n            resetClickGuardRuntime();\n            state.config = null;\n",
)

# 6. Browser test suite for Click Guard behavior.
browser_test = r'''import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const HOUR = 60 * 60 * 1000;

function eventTarget(target = {}) {
    const listeners = new Map();
    target.addEventListener = (name, callback) => {
        const selected = listeners.get(name) || [];
        selected.push(callback);
        listeners.set(name, selected);
    };
    target.removeEventListener = (name, callback) => {
        listeners.set(name, (listeners.get(name) || []).filter((item) => item !== callback));
    };
    target.dispatchEvent = (event) => {
        for (const callback of [...(listeners.get(event.type) || [])]) callback.call(target, event);
        return true;
    };
    return target;
}

function frame() {
    return eventTarget({ tagName: 'IFRAME', parentNode: null, querySelectorAll() { return []; } });
}

function placementElement(code, className = 'hm-ad') {
    const attributes = { 'data-placement': code };
    const children = [];
    return {
        tagName: 'DIV', className, id: '', parentNode: null, children,
        style: {}, childNodes: children,
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        contains(node) {
            let current = node;
            while (current) { if (current === this) return true; current = current.parentNode; }
            return false;
        },
        appendChild(node) { node.parentNode = this; children.push(node); return node; },
        querySelectorAll(selector) {
            if (selector !== 'iframe') return [];
            const found = [];
            const visit = (node) => {
                for (const child of node.children || []) {
                    if (String(child.tagName || '').toLowerCase() === 'iframe') found.push(child);
                    visit(child);
                }
            };
            visit(this);
            return found;
        },
    };
}

function activeConfig(overrides = {}) {
    return {
        siteKey: 'HM_TEST', servingMode: 'HORUS_GAM', gamNetworkCode: '123456789', configVersion: 7,
        status: 'active', immediatePause: false, debug: false, allowedHostnames: ['publisher.example'],
        clickGuard: { enabled: true, maxClicks: 3, windowHours: 6, blockHours: 12 },
        loader: { version: '2.0.0', cacheBust: 7 },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        prebid: { enabled: false, delivery: { refreshBehavior: { enabled: true, minimumIntervalSeconds: 30 } } },
        nativeDemand: { enabled: false, placements: {} }, pageTargeting: {},
        placements: [{
            code: 'article_top', name: 'Article Top', type: 'DISPLAY', status: 'active', enabled: true,
            adUnitPath: '/123456789/article_top', sizes: [[300, 250]], responsiveMappings: [], targeting: {},
            lazyLoad: { enabled: false }, refresh: { enabled: false, intervalSeconds: null, limit: null },
            collapseEmptyDiv: true, safeFrame: false, outOfPageFormat: null,
        }],
        ...overrides,
    };
}

function memoryStorage(seed = {}, throws = false) {
    const data = new Map(Object.entries(seed));
    return {
        getItem(key) { if (throws) throw new DOMException('Denied', 'SecurityError'); return data.has(key) ? data.get(key) : null; },
        setItem(key, value) { if (throws) throw new DOMException('Denied', 'SecurityError'); data.set(key, String(value)); },
        removeItem(key) { if (throws) throw new DOMException('Denied', 'SecurityError'); data.delete(key); },
        raw(key) { return data.get(key); },
    };
}

function createHarness(config, { storage = memoryStorage(), containers = null } = {}) {
    const selectedContainers = containers || [placementElement('article_top')];
    const metrics = { fetches: [], gptLoads: 0, prebidLoads: 0, nativeLoads: 0, defined: 0, displayed: 0, refreshes: 0, intervals: new Map(), clearedIntervals: [] };
    let observerCallback = null;
    let intervalId = 0;
    const scriptAttributes = { 'data-site-key': config.siteKey, 'data-config-base': 'https://cdn.horusmedia.net/configs', 'data-environment': 'production' };
    const script = {
        src: 'https://cdn.horusmedia.net/hm-loader.js', dataset: { siteKey: config.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        getAttribute(name) { return scriptAttributes[name] ?? null; }, setAttribute(name, value) { scriptAttributes[name] = String(value); },
    };
    const pubads = {
        setTargeting() { return this; }, enableLazyLoad() {}, enableSingleRequest() {}, setPrivacySettings() {}, addEventListener() {},
        refresh(entries) { metrics.refreshes += entries.length; },
    };
    const immediateQueue = { push(callback) { callback(); return 1; } };
    const googletag = {
        cmd: immediateQueue, apiReady: false, pubadsReady: false,
        pubads() { return pubads; },
        defineSlot() {
            metrics.defined += 1;
            const slot = { setTargeting() { return slot; }, defineSizeMapping() { return slot; }, setForceSafeFrame() { return slot; }, setCollapseEmptyDiv() { return slot; }, addService() { return slot; } };
            return slot;
        },
        defineOutOfPageSlot() { return null; },
        enableServices() { googletag.apiReady = true; },
        display() { metrics.displayed += 1; },
        enums: { OutOfPageFormat: {} },
    };
    class MutationObserver {
        constructor(callback) { observerCallback = callback; }
        observe() {}
        disconnect() {}
    }
    class Event { constructor(type) { this.type = type; } }
    class PointerEvent extends Event {}
    const document = eventTarget({
        currentScript: script, readyState: 'complete', visibilityState: 'visible', activeElement: null, documentElement: {},
        querySelector() { return null; },
        querySelectorAll(selector) {
            if (selector === 'script[data-site-key]') return [script];
            if (selector === '.hm-ad[data-placement]') return selectedContainers.filter((item) => item.className === 'hm-ad');
            if (selector === '.hm-native[data-placement]') return selectedContainers.filter((item) => item.className === 'hm-native');
            return [];
        },
    });
    const sandbox = eventTarget({
        console, URL, Promise, DOMException, structuredClone, queueMicrotask, Event, PointerEvent, MutationObserver, document, googletag,
        localStorage: storage, navigator: {}, location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} }, __HM_DISABLE_AUTOBOOT__: true,
        setTimeout, clearTimeout,
        setInterval(callback) { const id = ++intervalId; metrics.intervals.set(id, callback); return id; },
        clearInterval(id) { metrics.clearedIntervals.push(id); metrics.intervals.delete(id); },
        fetch: async (url) => {
            metrics.fetches.push(String(url));
            if (String(url).includes('/_global/control.json')) return { ok: true, json: async () => ({ controls: {} }) };
            if (String(url).includes('/manifest.json')) return { ok: true, json: async () => ({ siteKey: config.siteKey, environments: { production: { version: config.configVersion, path: `/configs/${config.siteKey}/production.v${config.configVersion}.${'a'.repeat(16)}.json`, sha256: 'a'.repeat(64) } } }) };
            return { ok: true, json: async () => structuredClone(config) };
        },
    });
    document.head = {
        appendChild(node) {
            const marker = node.getAttribute?.('data-hm-gpt');
            const prebid = node.getAttribute?.('data-hm-prebid');
            const native = node.getAttribute?.('data-hm-native-script');
            if (marker === '1') { metrics.gptLoads += 1; queueMicrotask(() => node.onload?.()); }
            if (prebid === '1') { metrics.prebidLoads += 1; queueMicrotask(() => node.onload?.()); }
            if (native) { metrics.nativeLoads += 1; queueMicrotask(() => node.onload?.()); }
            return node;
        },
    };
    document.createElement = (tag) => {
        const node = eventTarget({ tagName: String(tag).toUpperCase(), attributes: {}, async: false, src: '', onload: null, onerror: null, style: {}, childNodes: [], children: [] });
        node.setAttribute = (name, value) => { node.attributes[name] = String(value); };
        node.getAttribute = (name) => node.attributes[name] ?? null;
        node.appendChild = (child) => { child.parentNode = node; node.children.push(child); node.childNodes = node.children; return child; };
        return node;
    };
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });
    return {
        sandbox, document, metrics, storage, containers: selectedContainers,
        mutate(record) { observerCallback?.([record]); },
        enter(iframe) { iframe.dispatchEvent(new PointerEvent('pointerenter')); },
        leave(iframe) { iframe.dispatchEvent(new PointerEvent('pointerleave')); },
        blur() { sandbox.dispatchEvent(new Event('blur')); },
        state() { const raw = storage.raw(`hm:click-guard:v1:${config.siteKey}`); return raw ? JSON.parse(raw) : null; },
    };
}

async function bootWithFrame(config, options = {}) {
    const harness = createHarness(config, options);
    await harness.sandbox.HorusMediaLoader.boot();
    const iframe = frame();
    harness.containers[0].appendChild(iframe);
    harness.mutate({ addedNodes: [iframe], removedNodes: [] });
    return { ...harness, iframe };
}

test('disabled Click Guard preserves existing ad behavior and does not touch storage', async () => {
    const storage = memoryStorage();
    const config = activeConfig({ clickGuard: { enabled: false, maxClicks: 3, windowHours: 6, blockHours: 12 } });
    const harness = createHarness(config, { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.equal(harness.metrics.defined, 1);
    assert.equal(storage.raw('hm:click-guard:v1:HM_TEST'), undefined);
});

test('below threshold records clicks without blocking', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [now - HOUR], blockedUntil: 0 }) });
    const { sandbox, iframe, enter, blur, state } = await bootWithFrame(activeConfig(), { storage });
    enter(iframe); blur();
    assert.equal(state().clicks.length, 2);
    assert.equal(state().blockedUntil, 0);
    assert.equal((await sandbox.HorusMediaLoader.scan()).length, 0);
});

test('exact threshold creates a future block and clears the click window', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [now - 2 * HOUR, now - HOUR], blockedUntil: 0 }) });
    const { iframe, enter, blur, state } = await bootWithFrame(activeConfig(), { storage });
    enter(iframe); blur();
    assert.deepEqual(state().clicks, []);
    assert.ok(state().blockedUntil > Date.now() + 11 * HOUR);
});

test('rolling window prunes expired clicks before counting', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [now - 7 * HOUR, now - HOUR], blockedUntil: 0 }) });
    const { iframe, enter, blur, state } = await bootWithFrame(activeConfig(), { storage });
    enter(iframe); blur();
    assert.equal(state().clicks.length, 2);
    assert.equal(state().blockedUntil, 0);
    assert.ok(state().clicks.every((value) => value >= now - 6 * HOUR));
});

test('existing future block stops before GPT, Prebid, native, slot, display, and refresh initialization', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [], blockedUntil: now + HOUR }) });
    const config = activeConfig({
        prebid: { enabled: true, build: { url: 'https://cdn.horusmedia.net/prebid.js' }, delivery: { gamFallback: true } },
        nativeDemand: { enabled: true, placements: { article_top: { enabled: true, candidates: [{ network: 'TEST', tag: { scriptUrl: 'https://native.example/tag.js' } }] } } },
    });
    const harness = createHarness(config, { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 0);
    assert.equal(harness.metrics.prebidLoads, 0);
    assert.equal(harness.metrics.nativeLoads, 0);
    assert.equal(harness.metrics.defined, 0);
    assert.equal(harness.metrics.displayed, 0);
    assert.equal(harness.metrics.refreshes, 0);
    assert.equal(harness.metrics.fetches.length, 3);
});

test('expired block resets stale clicks and resumes normal advertising', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [now - HOUR], blockedUntil: now - 1000 }) });
    const harness = createHarness(activeConfig(), { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.deepEqual(harness.state(), { v: 1, clicks: [], blockedUntil: 0 });
});

test('corrupt localStorage fails open and is normalized without breaking the loader', async () => {
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': '{broken' });
    const harness = createHarness(activeConfig(), { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.deepEqual(harness.state(), { v: 1, clicks: [], blockedUntil: 0 });
});

test('localStorage SecurityError fails open and ads continue', async () => {
    const harness = createHarness(activeConfig(), { storage: memoryStorage({}, true) });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.equal(harness.metrics.defined, 1);
});

test('dynamic eligible iframe is tracked and unrelated iframe is ignored', async () => {
    const harness = createHarness(activeConfig());
    await harness.sandbox.HorusMediaLoader.boot();
    const unrelated = frame();
    harness.mutate({ addedNodes: [unrelated], removedNodes: [] });
    harness.enter(unrelated); harness.blur();
    assert.equal(harness.state(), null);

    const eligible = frame();
    harness.containers[0].appendChild(eligible);
    harness.mutate({ addedNodes: [eligible], removedNodes: [] });
    harness.enter(eligible); harness.blur();
    assert.equal(harness.state().clicks.length, 1);
});

test('window blur without an armed Horus iframe does not count', async () => {
    const harness = createHarness(activeConfig());
    await harness.sandbox.HorusMediaLoader.boot();
    harness.blur();
    assert.equal(harness.state(), null);
});

test('eligible iframe blur counts once and duplicate blur is deduplicated', async () => {
    const { iframe, enter, blur, state } = await bootWithFrame(activeConfig());
    enter(iframe); blur(); blur();
    assert.equal(state().clicks.length, 1);
});

test('mid-page threshold clears refresh timers and future scans cannot request new ads', async () => {
    const config = activeConfig({
        clickGuard: { enabled: true, maxClicks: 1, windowHours: 6, blockHours: 12 },
        placements: [{ ...activeConfig().placements[0], refresh: { enabled: true, intervalSeconds: 30, limit: 0 } }],
    });
    const harness = await bootWithFrame(config);
    assert.equal(harness.metrics.intervals.size, 1);
    const beforeDefined = harness.metrics.defined;
    harness.enter(harness.iframe); harness.blur();
    assert.equal(harness.metrics.intervals.size, 0);
    assert.ok(harness.metrics.clearedIntervals.length >= 1);

    const second = placementElement('article_top');
    harness.containers.push(second);
    await harness.sandbox.HorusMediaLoader.scan();
    assert.equal(harness.metrics.defined, beforeDefined);
});

test('storage event from another tab activates block and cancels future activity', async () => {
    const config = activeConfig({ placements: [{ ...activeConfig().placements[0], refresh: { enabled: true, intervalSeconds: 30, limit: 0 } }] });
    const harness = createHarness(config);
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.intervals.size, 1);
    const blocked = JSON.stringify({ v: 1, clicks: [], blockedUntil: Date.now() + HOUR });
    harness.sandbox.dispatchEvent({ type: 'storage', key: 'hm:click-guard:v1:HM_TEST', newValue: blocked });
    assert.equal(harness.metrics.intervals.size, 0);
    const before = harness.metrics.defined;
    await harness.sandbox.HorusMediaLoader.scan();
    assert.equal(harness.metrics.defined, before);
});

test('site-key namespacing prevents another Horus site block from leaking into this site', async () => {
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_OTHER': JSON.stringify({ v: 1, clicks: [], blockedUntil: Date.now() + HOUR }) });
    const harness = createHarness(activeConfig(), { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.equal(harness.metrics.defined, 1);
});
'''
write('tests/Browser/hm-loader-click-guard.test.js', browser_test)

# 7. Laravel feature tests.
php_test = r'''<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Models\ConfigVersion;
use App\Models\SiteConfig;
use App\Services\Inventory\SiteConfigurationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class ClickGuardConfigurationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        config(['static-delivery.batch_delay_seconds' => 0]);
    }

    public function test_default_public_configuration_is_disabled_and_safe(): void
    {
        [$site] = $this->makeSiteAndAdmin();

        $payload = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1);

        $this->assertSame([
            'enabled' => false,
            'maxClicks' => 3,
            'windowHours' => 6,
            'blockHours' => 12,
        ], $payload['clickGuard']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('click_guard_settings', $encoded);
        $this->assertStringNotContainsString('organization_id', $encoded);
    }

    public function test_delivery_settings_persist_and_publish_normalized_click_guard_configuration_for_active_site(): void
    {
        [$site, $admin] = $this->makeSiteAndAdmin();
        $site->update(['status' => SiteStatus::Active]);

        $response = $this->actingAs($admin)->put(route('admin.sites.config.update', $site), $this->validSettings([
            'click_guard_enabled' => '1',
            'click_guard_max_clicks' => '5',
            'click_guard_window_hours' => '8',
            'click_guard_block_hours' => '24',
        ]));

        $response->assertRedirect();
        $config = SiteConfig::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail();
        $this->assertSame(['enabled' => true, 'maxClicks' => 5, 'windowHours' => 8, 'blockHours' => 24], $config->click_guard_settings);
        $version = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->latest('version')->firstOrFail();
        $this->assertSame(['enabled' => true, 'maxClicks' => 5, 'windowHours' => 8, 'blockHours' => 24], $version->payload['clickGuard']);
        $this->assertDatabaseHas('static_delivery_items', ['config_version_id' => $version->id]);
    }

    public function test_click_guard_validation_rejects_out_of_bounds_values(): void
    {
        [$site, $admin] = $this->makeSiteAndAdmin();

        $response = $this->from(route('admin.sites.inventory.index', $site))->actingAs($admin)->put(
            route('admin.sites.config.update', $site),
            $this->validSettings([
                'click_guard_max_clicks' => 51,
                'click_guard_window_hours' => 169,
                'click_guard_block_hours' => 721,
            ]),
        );

        $response->assertRedirect(route('admin.sites.inventory.index', $site));
        $response->assertSessionHasErrors(['click_guard_max_clicks', 'click_guard_window_hours', 'click_guard_block_hours']);
    }

    public function test_inactive_site_saves_click_guard_without_publishing_production(): void
    {
        [$site, $admin] = $this->makeSiteAndAdmin();
        $before = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->count();

        $this->actingAs($admin)->put(route('admin.sites.config.update', $site), $this->validSettings([
            'click_guard_enabled' => '1',
        ]))->assertRedirect();

        $config = SiteConfig::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail();
        $this->assertTrue($config->click_guard_settings['enabled']);
        $this->assertSame($before, ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->count());
    }

    public function test_legacy_settings_request_keeps_existing_click_guard_values_when_fields_are_omitted(): void
    {
        [$site, $admin] = $this->makeSiteAndAdmin();
        SiteConfig::withoutGlobalScopes()->updateOrCreate(
            ['site_id' => $site->id],
            ['organization_id' => $site->organization_id, 'cache_ttl_seconds' => 60, 'click_guard_settings' => [
                'enabled' => true, 'maxClicks' => 4, 'windowHours' => 10, 'blockHours' => 18,
            ]],
        );

        $legacy = $this->validSettings();
        unset($legacy['click_guard_enabled'], $legacy['click_guard_max_clicks'], $legacy['click_guard_window_hours'], $legacy['click_guard_block_hours']);
        $this->actingAs($admin)->put(route('admin.sites.config.update', $site), $legacy)->assertRedirect();

        $this->assertSame(
            ['enabled' => true, 'maxClicks' => 4, 'windowHours' => 10, 'blockHours' => 18],
            SiteConfig::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail()->click_guard_settings,
        );
    }

    public function test_publisher_without_config_permission_cannot_change_click_guard_settings(): void
    {
        [$site] = $this->makeSiteAndAdmin();
        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher);
        $otherUser = $this->makeUser($otherOrganization, RoleName::PublisherAdmin);

        $this->actingAs($otherUser)->put(route('admin.sites.config.update', $site), $this->validSettings([
            'click_guard_enabled' => '1',
        ]))->assertForbidden();
    }

    private function validSettings(array $overrides = []): array
    {
        return array_merge([
            'cache_ttl_seconds' => 60,
            'debug_enabled' => '0',
            'house_ad_testing' => '0',
            'single_request_mode' => '1',
            'click_guard_enabled' => '0',
            'click_guard_max_clicks' => 3,
            'click_guard_window_hours' => 6,
            'click_guard_block_hours' => 12,
            'privacy_settings_json' => '{}',
            'gpt_settings_json' => '{}',
            'supply_chain_settings_json' => '{}',
            'observability_settings_json' => '{}',
        ], $overrides);
    }

    private function makeSiteAndAdmin(): array
    {
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher);
        $publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser, [
            'primary_domain' => 'click-guard.example',
        ]);

        return [$site, $admin];
    }
}
'''
write('tests/Feature/ClickGuardConfigurationTest.php', php_test)

# 8. Documentation.
docs = read('docs/INVENTORY_AND_LOADER.md')
docs = docs.replace(
    '  "houseAdTesting": false,\n  "allowedHostnames": ["publisher.example"],',
    '  "houseAdTesting": false,\n  "clickGuard": {\n    "enabled": false,\n    "maxClicks": 3,\n    "windowHours": 6,\n    "blockHours": 12\n  },\n  "allowedHostnames": ["publisher.example"],',
    1,
)
marker = '## Pausing\n'
section = r'''## Horus Click Guard

Horus Click Guard is an optional browser-local protection in the Horus Loader.
It is disabled by default. Delivery Settings publishes only these public values:

```json
{
  "clickGuard": {
    "enabled": true,
    "maxClicks": 3,
    "windowHours": 6,
    "blockHours": 12
  }
}
```

When enabled, the loader stores a minimal versioned record under
`hm:click-guard:v1:{SITE_KEY}` in the publisher origin's `localStorage`. The
record contains only click timestamps in Unix epoch milliseconds and a
`blockedUntil` timestamp. It contains no URL, creative data, user identifier,
IP address, fingerprint, page history, or Horus account/database identifier,
and it is never transmitted to Laravel.

At every relevant read/write, timestamps outside the rolling `windowHours`
window are pruned. Reaching `maxClicks` creates `blockedUntil` for
`blockHours`, clears the prior click window, cancels Horus refresh timers, and
prevents future Horus-managed GPT, Prebid, native-demand, refresh, and SPA scan
requests. Advertising already rendered before the threshold is not destroyed.
A browser that loads the page while a valid block is active stops after static
configuration/privacy/host gates and before advertising libraries initialize.
When the block expires the state is reset to a fresh click window and normal
eligibility resumes.

Probable iframe interaction is detected without reading or modifying cross-origin
iframe contents. Click Guard tracks only iframes inside active `.hm-ad` and
`.hm-native` placement containers, arms on pointer entry (with mouse fallback),
and records one probable click when the top window subsequently loses focus.
The existing SPA `MutationObserver` is reused to register dynamically inserted
or replaced ad iframes and remove listeners from removed frames; unrelated page
iframes are ignored. A small internal debounce and re-entry requirement prevent
one interaction from being counted repeatedly.

Same-origin tabs synchronize blocks through the browser's native `storage`
event. No WebSocket or backend synchronization is used. Corrupt storage is
normalized safely. If `localStorage` is unavailable or throws a security/policy
exception, Click Guard fails open and normal advertising continues.

Cross-origin iframe click detection is necessarily heuristic and
browser-dependent. Desktop pointer/focus behavior is the most reliable case;
mobile/touch interaction is inherently less reliable. Click Guard does not claim
to detect every advertising click or to be complete click-fraud detection.

'''
if marker not in docs:
    raise RuntimeError('Documentation insertion marker missing')
docs = docs.replace(marker, section + marker, 1)
write('docs/INVENTORY_AND_LOADER.md', docs)

print('Horus Click Guard source, tests, migration, UI, and documentation applied.')
