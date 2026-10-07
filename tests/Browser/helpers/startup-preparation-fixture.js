// Offline fixtures only. Media and IMA are deterministic; production Loader,
// Traffic Gate frame, click accounting and layout logic are not stubbed.
export function securityConfig(options = {}) {
    const url = 'https://reader.example/content.mp4';
    const code = options.quickEmbed ? 'quick_video_floating' : 'video';
    return {
        schemaVersion: 4, siteKey: 'STARTUP_RECOVERY', configVersion: 1,
        status: 'active', allowedHostnames: ['reader.example'],
        controls: { gamDisabled: true, prebidDisabled: true },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: true },
        clickGuard: { enabled: true, maxClicks: 1, windowHours: 6, blockHours: 1 },
        trafficGate: {
            enabled: true, provider: 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED', gateOrigin: 'https://verify.horusmedia.net',
            siteKey: '1x00000000000000000000BB', policy: 'BALANCED', readiness: 'READY',
            timings: { initialWaitMs: 500, maxWaitMs: 10000, retryIntervalMs: 500 },
        },
        placements: [{ code, type: 'VIDEO', enabled: true, status: 'active', renderer: 'DIRECT_JS', sizes: [[320, 180]],
            format: { settings: { autoMount: false, position: options.inlineOnly ? 'inline' : 'inline_to_bottom_right',
                floatingPosition: options.inlineOnly ? null : 'bottom_right', closeable: true, closeOutside: true, reserveSpace: true } } }],
        directDemand: { enabled: true, placements: { [code]: { enabled: true, candidates: [{ network: 'TEST', tag: {
            executionMode: 'STRUCTURED', scripts: [{ url: 'https://cdn.horusmedia.net/runtime/video/hm-video-direct.0123456789abcdef.js' }],
            container: { element: 'div', id: 'video-runtime', attributes: {
                'data-hm-video-direct': '1', 'data-hm-video-width': '320', 'data-hm-video-height': '180',
                'data-hm-vast-url': Buffer.from('https://ads.example/vast').toString('base64'),
                ...(options.content ? { 'data-hm-video-content-url': url } : {}),
            } }, initialization: { type: 'NONE' },
            render: { timeoutMs: 20000, successSelector: '[data-hm-video-status="started"], [data-hm-video-status="content-ready"]' },
        } }] } } },
    };
}

export function publisherPage(options = {}) {
    const code = options.quickEmbed ? 'quick_video_floating' : 'video';
    return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
    html,body{margin:0;overflow-anchor:none} article{max-width:640px;margin:auto} #before{height:${options.belowFold ? 1400 : 80}px}
    [data-placement="${code}"]{width:320px;max-width:100%;${options.emptyDiv ? "" : "min-height:180px"}} #tail{height:4000px}
    ${options.transformed ? 'article{transform:translateZ(0);contain:paint;overflow:hidden}' : ''}
    </style></head><body><article><div id="before"></div><div class="hm-ad" data-placement="${code}"></div><div id="tail">Article remains readable</div></article>
    <script id="loader" type="application/json" src="https://cdn.horusmedia.net/hm-loader.js" data-site-key="STARTUP_RECOVERY" data-config-version="1"></script></body></html>`;
}

export function initializeTestMedia(options) {
    window.__HM_DISABLE_AUTOBOOT__ = true;
    window.videoMetrics = { runtimeExecutions: 0, sdkExecutions: 0, requests: 0, starts: 0, destroys: 0, completions: 0, internalRequests: 0, contentPlays: 0 };
    window.videoManagers = []; window.videoLoaders = [];
    Object.defineProperty(HTMLMediaElement.prototype, 'src', {
        configurable: true, get() { return this.__testSrc || ''; }, set(value) {
            this.__testSrc = value;
            if (options.contentFailure) queueMicrotask(() => this.dispatchEvent(new Event('error')));
        },
    });
    Object.defineProperty(HTMLMediaElement.prototype, 'currentTime', { configurable: true, get() { return this.__testTime || 0; }, set(value) { this.__testTime = value; } });
    Object.defineProperty(HTMLMediaElement.prototype, 'duration', { configurable: true, get() { return 120; } });
    HTMLMediaElement.prototype.play = function () { window.videoMetrics.contentPlays++; return Promise.resolve(); };
    HTMLMediaElement.prototype.pause = function () {};
    if (options.blockedInitially) localStorage.setItem('hm:click-guard:v2:STARTUP_RECOVERY', JSON.stringify({ v: 2, clicks: [], blockedUntil: Date.now() + 3600000 }));
}

export function imaFixture(options = {}) {
    return `(${installImaFixture.toString()})(${JSON.stringify(options)});`;
}

function installImaFixture(options) {
    const metrics = window.videoMetrics; metrics.sdkExecutions++;
    class Manager {
        constructor(video, loader) { this.events = {}; this.video = video; this.loader = loader; window.videoManagers.push(this); }
        addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
        emit(name, event = {}) { (this.events[name] || []).forEach(fn => fn(event)); }
        getCuePoints() { return options.vmap ? [0, 60, -1] : []; }
        init() {
            // IMA preloading reports the selected ad during init, before start.
            // A manager alone is not proof of an ad: keep these separate so
            // offscreen startup tests exercise the actual-ad floating gate.
            this.deliverAd = () => this.emit('loaded', { getAd: () => ({ isLinear: () => true }) });
            if (!options.vmap && !options.deferLoaded) queueMicrotask(() => this.deliverAd());
            if (options.vmap) {
                this.onProgress = () => {
                    if (this.video.currentTime >= 60 && !this.midRequested) {
                        this.midRequested = true; metrics.internalRequests++;
                        this.emit('ready');
                    }
                };
                this.video.addEventListener('timeupdate', this.onProgress);
                if (!options.deferBreak) queueMicrotask(() => this.emit('ready'));
            }
        }
        setVolume() {}
        start() { metrics.starts++; metrics.startAt = performance.now(); this.emit('pause'); this.emit('started'); }
        resize() {}
        destroy() {
            metrics.destroys++; this.destroyed = true;
            if (this.onProgress) this.video.removeEventListener('timeupdate', this.onProgress);

        }
    }
    window.google = { ima: {
        AdDisplayContainer: class {
            constructor(layer, video) {
                this.video = video;
                this.frame = document.createElement('iframe'); this.frame.dataset.testIma = '1';
                this.frame.src = 'https://creative.example/iframe';
                this.frame.style.cssText = 'width:100%;height:100%;border:0'; layer.appendChild(this.frame);
            }
            initialize() {}
            destroy() { this.frame.remove(); }
        },
        AdsLoader: class {
            constructor(display) { this.events = {}; this.display = display; this.autoPlay = true; window.videoLoaders.push(this); }
            getSettings() { return { setAutoPlayAdBreaks: value => { this.autoPlay = value; } }; }
            addEventListener(name, callback) { this.events[name] = callback; }
            requestAds() {
                metrics.requests++;
                metrics.requestAt = performance.now();
                this.deliver = () => this.events.manager({ getAdsManager: () => (this.manager = new Manager(this.display.video, this)) });
                if (options.noFill) { this.events.error({ getError: () => new Error('no-fill') }); return; }
                if (!options.deferManager) this.deliver();
            }
            destroy() { this.destroyed = true; }
            contentComplete() {
                metrics.completions++;
                if (options.vmap && !this.destroyed && this.manager && !this.manager.destroyed) {
                    metrics.internalRequests++; this.manager.emit('ready');
                }
            }
        },
        AdsRequest: class { setAdWillAutoPlay() {} setAdWillPlayMuted() {} setContinuousPlayback() {} },
        AdsRenderingSettings: class {}, AdsManagerLoadedEvent: { Type: { ADS_MANAGER_LOADED: 'manager' } },
        AdErrorEvent: { Type: { AD_ERROR: 'error' } }, ViewMode: { NORMAL: 'normal' },
        AdEvent: { Type: { LOADED: 'loaded', STARTED: 'started', ALL_ADS_COMPLETED: 'all-completed', AD_BREAK_READY: 'ready', CONTENT_PAUSE_REQUESTED: 'pause', CONTENT_RESUME_REQUESTED: 'resume' } },
    } };
}
