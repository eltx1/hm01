from pathlib import Path
import re
r=Path('.')
def edit(path,old,new,count=1):
 p=r/path;s=p.read_text();assert s.count(old)==count,(path,old[:80],s.count(old));p.write_text(s.replace(old,new))
helper=r'''
    // Local bounded timing only: no tokens, URLs, identifiers, storage or network.
    function installStartupTrace() {
        try {
            if (window.__HORUS_STARTUP_TRACE_V1__) return;
            var records = [], sequence = 0, services = [], slots = new WeakMap();
            var allowed = ('H init|H prep|H boot|H wait DOM|H dom|H cfg start|H cfg ok|H cfg error|H ctl start|H ctl ok|H ctl error|H privacy start|H privacy ok|H privacy reject|Horus start|H blocked|H handoff|H slot start|G sdk start|G sdk ok|G sdk error|G api|G req|G res|G render|G empty|G load|V req|V start|V error|CF start|CF frame|CF cfg|CF sdk|CF challenge|CF token|CF verify|CF pass|CF reject|CF error|CF timeout').split('|');
            var origin = Date.now();
            function emit(code, index) {
                try {
                    if (records.length >= 160 || allowed.indexOf(code) === -1) return;
                    var value = window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now() - origin;
                    var row = { ms: Math.max(0, Math.round(value)), code: code };
                    if (Number.isInteger(index) && index >= 0 && index <= 10000) row.n = index;
                    records.push(row);
                    if (window.console && typeof window.console.log === 'function') window.console.log('[HM +' + row.ms + 'ms] ' + code + (row.n === undefined ? '' : ' #' + row.n));
                } catch (error) { /* Logging must never affect serving. */ }
            }
            function watchSlot(slot, pubads) {
                try {
                    if (!slot || !pubads || !pubads.addEventListener) return;
                    if (!slots.has(slot)) slots.set(slot, ++sequence);
                    if (services.indexOf(pubads) !== -1 || services.length >= 4) return;
                    services.push(pubads);
                    emit('G api');
                    [['slotRequested', 'G req'], ['slotResponseReceived', 'G res'], ['slotRenderEnded', 'G render'], ['slotOnload', 'G load']].forEach(function (pair) {
                        try {
                            pubads.addEventListener(pair[0], function (event) {
                                try {
                                    if (!event || !event.slot || !slots.has(event.slot)) return;
                                    emit(pair[0] === 'slotRenderEnded' && event.isEmpty ? 'G empty' : pair[1], slots.get(event.slot));
                                } catch (error) {}
                            });
                        } catch (error) {}
                    });
                } catch (error) {}
            }
            window.__HORUS_STARTUP_TRACE_V1__ = { emit: emit, watchSlot: watchSlot,
                snapshot: function () { return records.map(function (row) { return Object.assign({}, row); }); } };
            emit('H init');
            if (document.readyState === 'loading') {
                emit('H wait DOM');
                if (document.addEventListener) document.addEventListener('DOMContentLoaded', function () { emit('H dom'); }, { once: true });
            } else emit('H dom');
        } catch (error) {}
    }
    function startupTrace(code, index) {
        try { if (window.__HORUS_STARTUP_TRACE_V1__) window.__HORUS_STARTUP_TRACE_V1__.emit(code, index); } catch (error) {}
    }
    function startupTracePromise(prefix, promise) {
        startupTrace(prefix + ' start');
        promise.then(function () { startupTrace(prefix + ' ok'); }, function () { startupTrace(prefix + ' error'); });
        return promise;
    }
    function startupTraceSlot(slot, pubads) {
        try { if (window.__HORUS_STARTUP_TRACE_V1__) window.__HORUS_STARTUP_TRACE_V1__.watchSlot(slot, pubads); } catch (error) {}
    }
    installStartupTrace();
'''
edit('public/assets/hm-loader.js','    var CLICK_GUARD_STATE_VERSION = 2;',helper+'\n    var CLICK_GUARD_STATE_VERSION = 2;')
edit('public/assets/hm-loader.js','        getConfig: function () { return state.config; },','        getStartupTrace: function () {\n            try { return window.__HORUS_STARTUP_TRACE_V1__ ? window.__HORUS_STARTUP_TRACE_V1__.snapshot() : []; } catch (error) { return []; }\n        },\n        getConfig: function () { return state.config; },')
edit('public/assets/hm-loader.js','            tag.async = true;\n            tag.src = url;',"            tag.async = true;\n            tag.src = url;\n            if (marker === 'data-hm-gpt') startupTrace('G sdk start');")
edit('public/assets/hm-loader.js',"                tag.setAttribute('data-hm-loaded', '1');\n                resolve();","                tag.setAttribute('data-hm-loaded', '1');\n                if (marker === 'data-hm-gpt') startupTrace('G sdk ok');\n                resolve();")
edit('public/assets/hm-loader.js',"            tag.onerror = function () { reject(new Error(url + ' failed to load')); };","            tag.onerror = function () {\n                if (marker === 'data-hm-gpt') startupTrace('G sdk error');\n                reject(new Error(url + ' failed to load'));\n            };")
edit('public/assets/hm-loader.js','    function loadDirectScripts(config, candidate) {',"    function loadDirectScripts(config, candidate) {\n        startupTrace('H slot start');")
edit('public/assets/hm-loader.js','        window.__HM_RELEASE_DELEGATED__ = true;',"        startupTrace('H handoff');\n        window.__HM_RELEASE_DELEGATED__ = true;")
edit('public/assets/hm-loader.js','                            if (slot.addService) slot.addService(pubads);','                            if (slot.addService) {\n                                startupTraceSlot(slot, pubads);\n                                slot.addService(pubads);\n                            }')
provider_helper='''    function startupTrace(code, index) {
        try { if (window.__HORUS_STARTUP_TRACE_V1__) window.__HORUS_STARTUP_TRACE_V1__.emit(code, index); } catch (error) {}
    }
    function startupTraceSlot(slot, pubads) {
        try { if (window.__HORUS_STARTUP_TRACE_V1__) window.__HORUS_STARTUP_TRACE_V1__.watchSlot(slot, pubads); } catch (error) {}
    }
'''
for name in ['hm-gpt-direct.js','hm-video-direct.js']:
 edit('public/assets/'+name,"    'use strict';","    'use strict';\n\n"+provider_helper)
edit('public/assets/hm-gpt-direct.js','            slot.addService(pubads);','            startupTraceSlot(slot, pubads);\n            slot.addService(pubads);',2)
edit('public/assets/hm-gpt-direct.js','        script.async = true;\n        script.src = GPT_URL;',"        script.async = true;\n        script.src = GPT_URL;\n        startupTrace('G sdk start');\n        script.onload = function () { startupTrace('G sdk ok'); };")
edit('public/assets/hm-gpt-direct.js','        script.onerror = function () {\n            state.libraryInjected = false;',"        script.onerror = function () {\n            startupTrace('G sdk error');\n            state.libraryInjected = false;")
edit('public/assets/hm-video-direct.js',"        container.setAttribute('data-hm-video-status', value);","        if (value === 'started') startupTrace('V start');\n        container.setAttribute('data-hm-video-status', value);")
edit('public/assets/hm-video-direct.js','            player.adsLoader.requestAds(request);',"            startupTrace('V req');\n            player.adsLoader.requestAds(request);",2)
edit('public/assets/hm-video-direct.js','        var surface = player.container;\n        while (surface',"        startupTrace('V error', Number(code || vastCode) || 0);\n        var surface = player.container;\n        while (surface")
t='scripts/transform-loader-traffic-gate.mjs'
edit(t,'Promise.all([fetchGlobalControl(script, force), fetchConfig(script, siteKey, force)])',"Promise.all([startupTracePromise('H ctl', fetchGlobalControl(script, force)), startupTracePromise('H cfg', fetchConfig(script, siteKey, force))])")
edit(t,'        var generation = nextPreparationGeneration();\n        var preparation =',"        startupTrace('H prep');\n        var generation = nextPreparationGeneration();\n        var preparation =")
edit(t,'            resume: null\n','            resume: null,\n            helloSent: false\n')
edit(t,'        trafficGateSetState(nextState, reason);',"        if (nextState === TRAFFIC_GATE_STATES.passed) startupTrace('CF pass');\n        trafficGateSetState(nextState, reason);")
edit(t,'    function trafficGateBlock(reason) {',"    function trafficGateBlock(reason) {\n        startupTrace('CF reject');")
edit(t,'        gate.retryAtDomReady = retryAtDomReady !== false;',"        startupTrace(stateName === TRAFFIC_GATE_STATES.timeout ? 'CF timeout' : 'CF error');\n        gate.retryAtDomReady = retryAtDomReady !== false;")
edit(t,'        if (message.protocolVersion !== TRAFFIC_GATE_PROTOCOL_VERSION) return;\n        if (message.pageNonce',"        if (message.protocolVersion !== TRAFFIC_GATE_PROTOCOL_VERSION) return;\n        if (message.type === 'HORUS_TRAFFIC_GATE_BOOT') {\n            trafficGateSendHello(gate.iframe);\n            return;\n        }\n        if (message.pageNonce")
edit(t,"        if (type === 'HORUS_TRAFFIC_GATE_READY') return;","        if (type === 'HORUS_TRAFFIC_GATE_PROGRESS') {\n            var stage = { config: 'CF cfg', sdk: 'CF sdk', challenge: 'CF challenge', token: 'CF token', verify: 'CF verify' }[message.stage];\n            if (stage) startupTrace(stage);\n            return;\n        }\n        if (type === 'HORUS_TRAFFIC_GATE_READY') return;")
edit(t,'        gate.started = true;\n        gate.startedAt',"        startupTrace('CF start');\n        gate.started = true;\n        gate.startedAt")
p=r/t;s=p.read_text();old=re.search(r'        iframe.onload = function \(\) \{\n            var current = trafficGateRuntimeState\(\);[\s\S]*?\n        \};',s);assert old
s=s[:old.start()]+'''        // Readiness can precede load; older frames retain the onload fallback.
        iframe.onload = function () { trafficGateSendHello(iframe); };'''+s[old.end():];p.write_text(s)
edit(t,'    function beginTrafficGate(config) {','''    function trafficGateSendHello(iframe) {
        var current = trafficGateRuntimeState();
        if (!current.iframe || current.iframe !== iframe || !iframe.contentWindow || current.helloSent) return;
        try {
            current.helloSent = true;
            startupTrace('CF frame');
            iframe.contentWindow.postMessage({
                type: 'HORUS_TRAFFIC_GATE_HELLO', protocolVersion: TRAFFIC_GATE_PROTOCOL_VERSION,
                pageNonce: current.pageNonce, sitePublicKey: current.settings.siteKey, startupTrace: true
            }, current.settings.origin);
        } catch (error) {
            trafficGateTechnicalFailure(TRAFFIC_GATE_STATES.unavailable, 'HANDSHAKE_FAILED', true);
        }
    }

    function beginTrafficGate(config) {''')
edit(t,'            installSpaSupport();\n            return scan(config);',"            startupTrace('Horus start');\n            installSpaSupport();\n            return scan(config);")
edit(t,'        var generation = nextPreparationGeneration();\n        var bootPromise',"        startupTrace('H boot');\n        var generation = nextPreparationGeneration();\n        var bootPromise")
edit(t,'            var privacyPromise = resolvePrivacy(config).then(function (decision) {',"            startupTrace('H privacy start');\n            var privacyPromise = resolvePrivacy(config).then(function (decision) {\n                startupTrace(decision.blocked ? 'H privacy reject' : 'H privacy ok');")
edit(t,"                log(config, 'Advertising remains blocked by a local serving prerequisite');","                startupTrace('H blocked');\n                log(config, 'Advertising remains blocked by a local serving prerequisite');")
g='public/assets/traffic-gate/horus-traffic-gate.js'
edit(g,'    function clearTimers() {','''    function progress(stage) {
        if (!boundParent?.startupTrace || terminal) return;
        try { post('HORUS_TRAFFIC_GATE_PROGRESS', { stage }); } catch {}
    }

    function clearTimers() {''')
edit(g,'        verificationPending = true;',"        progress('token');\n        verificationPending = true;")
edit(g,"                const response = await fetch('https://siteverify.horusmedia.net/verify', {","                progress('verify');\n                const response = await fetch('https://siteverify.horusmedia.net/verify', {")
edit(g,'        setState(STATES.challengeRunning);',"        setState(STATES.challengeRunning);\n        progress('challenge');")
edit(g,'        const configuration = loadSiteConfiguration(boundParent.sitePublicKey);',"        progress('config');\n        const configuration = loadSiteConfiguration(boundParent.sitePublicKey);")
edit(g,'        const turnstileReady = loadTurnstileScript().then(() => true, () => false);',"        progress('sdk');\n        const turnstileReady = loadTurnstileScript().then(() => true, () => false);")
edit(g,'            sitePublicKey: data.sitePublicKey,','            sitePublicKey: data.sitePublicKey,\n            startupTrace: data.startupTrace === true,')
edit(g,"    window.addEventListener('message', onParentMessage, false);","""    window.addEventListener('message', onParentMessage, false);
    // Public readiness only. The nonce-bound authorization protocol is unchanged.
    try {
        const referrer = document.referrer ? new URL(document.referrer) : null;
        const target = referrer && parsedHttpsOrigin(referrer.origin);
        if (target && window.location.origin === GATE_ORIGIN && window.parent !== window) {
            window.parent.postMessage({ type: 'HORUS_TRAFFIC_GATE_BOOT', protocolVersion: PROTOCOL_VERSION }, target.origin);
        }
    } catch { /* No referrer: preserve the existing onload handshake. */ }""")
