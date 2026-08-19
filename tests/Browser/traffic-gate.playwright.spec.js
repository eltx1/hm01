import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const gateHtml = await readFile(new URL('../../public/traffic-gate/index.html', import.meta.url), 'utf8');
const gateJs = await readFile(new URL('../../public/assets/traffic-gate/horus-traffic-gate.js', import.meta.url), 'utf8');

const GATE_ORIGIN = 'https://verify.horusmedia.net';
const ADMIN_ORIGIN = 'https://app.horusmedia.net';
const PUBLISHER_A = 'https://publisher-a.example';
const PUBLISHER_B = 'https://publisher-b.example';
const SITE_A = 'HM_SITE_A';
const SITE_B = 'HM_SITE_B';
const ALWAYS_PASS_INVISIBLE = '1x00000000000000000000BB';
const ALWAYS_FAIL_INVISIBLE = '2x00000000000000000000BB';

const parentCsp = "default-src 'none'; script-src 'self'; frame-src https://verify.horusmedia.net; connect-src 'none'; img-src 'none'; style-src 'none'; base-uri 'none'; form-action 'none'; object-src 'none'; frame-ancestors 'none'";
const blockedParentCsp = "default-src 'none'; script-src 'self'; frame-src 'none'; connect-src 'none'; img-src 'none'; style-src 'none'; base-uri 'none'; form-action 'none'; object-src 'none'; frame-ancestors 'none'";
const gateCsp = "default-src 'none'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; object-src 'none'; frame-ancestors https:";

function configFor(siteKey, hostname, turnstileSiteKey = ALWAYS_PASS_INVISIBLE) {
    return {
        siteKey,
        allowedHostnames: [hostname],
        trafficGate: {
            enabled: true,
            provider: 'CLOUDFLARE_TURNSTILE_CLIENT_ONLY',
            gateOrigin: GATE_ORIGIN,
            siteKey: turnstileSiteKey,
            policy: 'BALANCED',
            readiness: 'READY',
            timings: { initialWaitMs: 1500, maxWaitMs: 6000, retryIntervalMs: 1500 },
            activityRecoveryEnabled: true,
        },
    };
}

function parentHtml() {
    return '<!doctype html><html><head><meta charset="utf-8"><script src="/parent.js" defer></script></head><body data-gate-state="BOOTING"></body></html>';
}

function parentScript() {
    return `(() => {
        const GATE_ORIGIN = ${JSON.stringify(GATE_ORIGIN)};
        const params = new URLSearchParams(location.search);
        const bytes = new Uint8Array(24);
        crypto.getRandomValues(bytes);
        const nonce = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        const frame = document.createElement('iframe');
        const record = window.__trafficGateTest = { messages: [], result: null, nonce, startedAt: null, elapsedMs: null };
        frame.src = GATE_ORIGIN + '/traffic-gate/';
        frame.setAttribute('data-gate-frame', '1');
        window.addEventListener('message', event => {
            if (event.origin !== GATE_ORIGIN || event.source !== frame.contentWindow) return;
            const message = event.data;
            if (!message || message.protocolVersion !== 1 || message.pageNonce !== nonce) return;
            record.messages.push(message);
            if (message.type === 'HORUS_TRAFFIC_GATE_PASS' || message.type === 'HORUS_TRAFFIC_GATE_ERROR' || message.type === 'HORUS_TRAFFIC_GATE_TIMEOUT' || message.type === 'HORUS_TRAFFIC_GATE_DENIED') {
                record.result = message.type;
                record.elapsedMs = performance.now() - record.startedAt;
                document.body.dataset.gateState = message.type.replace('HORUS_TRAFFIC_GATE_', '');
            }
        });
        frame.addEventListener('load', () => {
            record.startedAt = performance.now();
            const hello = {
                type: 'HORUS_TRAFFIC_GATE_HELLO',
                protocolVersion: 1,
                pageNonce: nonce,
                sitePublicKey: params.get('site') || 'admin-test'
            };
            if (params.get('test') === '1') {
                hello.testMode = true;
                hello.candidateSiteKey = params.get('candidate') || ${JSON.stringify(ALWAYS_PASS_INVISIBLE)};
            }
            frame.contentWindow.postMessage(hello, GATE_ORIGIN);
        });
        document.body.appendChild(frame);
    })();`;
}

function cloudflareStub({ slowMs = 0 } = {}) {
    return `(() => {
        const PASS = ${JSON.stringify(ALWAYS_PASS_INVISIBLE)};
        const FAIL = ${JSON.stringify(ALWAYS_FAIL_INVISIBLE)};
        let options = null;
        let widget = null;
        const run = () => {
            const challenge = document.createElement('iframe');
            challenge.src = 'https://challenges.cloudflare.com/cdn-cgi/challenge-platform/h/g/turnstile/test-frame';
            challenge.onload = () => {
                if (options.sitekey === PASS) queueMicrotask(() => options.callback('XXXX.DUMMY.TOKEN.XXXX'));
                else if (options.sitekey === FAIL) queueMicrotask(() => options['error-callback']('300001'));
                else queueMicrotask(() => options['error-callback']('110100'));
            };
            document.body.appendChild(challenge);
        };
        window.turnstile = {
            render(_container, supplied) {
                options = supplied;
                widget = 'task52-widget';
                window.__task52Turnstile = { sitekey: supplied.sitekey, responseField: supplied['response-field'], retry: supplied.retry };
                ${slowMs > 0 ? `setTimeout(run, ${slowMs});` : 'run();'}
                return widget;
            },
            reset() {
                if (options?.sitekey === FAIL) queueMicrotask(() => options['error-callback']('300001'));
                else run();
            },
            remove() { widget = null; },
        };
    })();`;
}

async function installDeterministicNetwork(page, { cloudflare = 'pass', publisherFrameAllowed = true, slowMs = 0 } = {}) {
    const requests = [];
    page.on('request', request => requests.push(request.url()));

    await page.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());

        if ([PUBLISHER_A, PUBLISHER_B, ADMIN_ORIGIN].includes(url.origin)) {
            if (url.pathname === '/parent.js') {
                return route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: parentScript() });
            }
            return route.fulfill({
                status: 200,
                contentType: 'text/html; charset=utf-8',
                headers: { 'Content-Security-Policy': publisherFrameAllowed ? parentCsp : blockedParentCsp, 'X-Frame-Options': 'DENY' },
                body: parentHtml(),
            });
        }

        if (url.origin === GATE_ORIGIN) {
            if (url.pathname === '/traffic-gate/' || url.pathname === '/traffic-gate') {
                return route.fulfill({
                    status: 200,
                    contentType: 'text/html; charset=utf-8',
                    headers: { 'Content-Security-Policy': gateCsp, 'X-Robots-Tag': 'noindex, nofollow' },
                    body: gateHtml,
                });
            }
            if (url.pathname === '/assets/traffic-gate/horus-traffic-gate.js') {
                return route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: gateJs });
            }
            if (url.pathname === `/configs/${SITE_A}/production.json`) {
                return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(configFor(SITE_A, 'publisher-a.example')) });
            }
            if (url.pathname === `/configs/${SITE_B}/production.json`) {
                return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(configFor(SITE_B, 'publisher-b.example')) });
            }
            return route.fulfill({ status: 404, body: 'not found' });
        }

        if (url.origin === 'https://challenges.cloudflare.com') {
            if (cloudflare === 'blocked') return route.abort('blockedbyclient');
            if (url.pathname === '/turnstile/v0/api.js') {
                return route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: cloudflareStub({ slowMs }) });
            }
            if (url.pathname.includes('/cdn-cgi/challenge-platform/')) {
                return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>deterministic Turnstile challenge frame</title>' });
            }
        }

        return route.abort('blockedbyclient');
    });

    return requests;
}

async function waitForResult(page, expected) {
    await expect.poll(() => page.evaluate(() => window.__trafficGateTest?.result)).toBe(expected);
}

test('Publisher A passes with strict Publisher CSP and never needs direct Cloudflare CSP permission', async ({ page }) => {
    const requests = await installDeterministicNetwork(page);
    const response = await page.goto(`${PUBLISHER_A}/?site=${SITE_A}`);
    await waitForResult(page, 'HORUS_TRAFFIC_GATE_PASS');

    const csp = response.headers()['content-security-policy'];
    expect(csp).toContain('frame-src https://verify.horusmedia.net');
    expect(csp).not.toContain('challenges.cloudflare.com');
    expect(requests.some(url => url.startsWith(`${GATE_ORIGIN}/traffic-gate/`))).toBe(true);
    expect(requests.some(url => url.startsWith('https://challenges.cloudflare.com/turnstile/'))).toBe(true);
    expect(requests.some(url => url.startsWith(ADMIN_ORIGIN))).toBe(false);
    expect(requests.some(url => /horus.*(?:analytics|report|beacon)|\/api\//i.test(url))).toBe(false);

    const elapsed = await page.evaluate(() => window.__trafficGateTest.elapsedMs);
    expect(elapsed).toBeLessThan(1200);
});

test('Publisher A cannot impersonate Publisher B Site configuration; mismatched origin is DENIED before Cloudflare loads', async ({ page }) => {
    const requests = await installDeterministicNetwork(page);
    await page.goto(`${PUBLISHER_A}/?site=${SITE_B}`);
    await waitForResult(page, 'HORUS_TRAFFIC_GATE_DENIED');
    expect(requests.some(url => url.startsWith('https://challenges.cloudflare.com/'))).toBe(false);
});

test('Publisher B independently passes its own Site configuration', async ({ page }) => {
    await installDeterministicNetwork(page);
    await page.goto(`${PUBLISHER_B}/?site=${SITE_B}`);
    await waitForResult(page, 'HORUS_TRAFFIC_GATE_PASS');
});

test('Admin test origin accepts the current official Invisible always-pass key without Siteverify', async ({ page }) => {
    const requests = await installDeterministicNetwork(page);
    await page.goto(`${ADMIN_ORIGIN}/?test=1&candidate=${ALWAYS_PASS_INVISIBLE}`);
    await waitForResult(page, 'HORUS_TRAFFIC_GATE_PASS');
    expect(requests.some(url => /siteverify/i.test(url))).toBe(false);
    expect(requests.some(url => /\/api\/.*traffic|analytics|report|beacon/i.test(url))).toBe(false);
});

test('current official Invisible always-fail key produces bounded CLIENT ERROR behavior after one retry', async ({ page }) => {
    await installDeterministicNetwork(page);
    await page.goto(`${ADMIN_ORIGIN}/?test=1&candidate=${ALWAYS_FAIL_INVISIBLE}`);
    await waitForResult(page, 'HORUS_TRAFFIC_GATE_ERROR');
});

test('blocked Turnstile resource is a technical ERROR, not a PASS or visitor classification', async ({ page }) => {
    await installDeterministicNetwork(page, { cloudflare: 'blocked' });
    await page.goto(`${PUBLISHER_A}/?site=${SITE_A}`);
    await waitForResult(page, 'HORUS_TRAFFIC_GATE_ERROR');
});

test('slow Turnstile resource can still PASS without waiting for fallback timers after the result arrives', async ({ page }) => {
    await installDeterministicNetwork(page, { slowMs: 700 });
    await page.goto(`${PUBLISHER_A}/?site=${SITE_A}`);
    await waitForResult(page, 'HORUS_TRAFFIC_GATE_PASS');
    const elapsed = await page.evaluate(() => window.__trafficGateTest.elapsedMs);
    expect(elapsed).toBeGreaterThanOrEqual(600);
    expect(elapsed).toBeLessThan(1500);
});

test('a Publisher CSP that blocks the Horus gate frame prevents the cross-origin gate from starting', async ({ page }) => {
    const requests = await installDeterministicNetwork(page, { publisherFrameAllowed: false });
    await page.goto(`${PUBLISHER_A}/?site=${SITE_A}`);
    await page.waitForTimeout(400);
    expect(await page.evaluate(() => window.__trafficGateTest?.result)).toBeNull();
    expect(requests.some(url => url.startsWith('https://challenges.cloudflare.com/'))).toBe(false);
});
