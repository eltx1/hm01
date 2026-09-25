import test from 'node:test';
import assert from 'node:assert/strict';
import { verifyRequest } from '../../workers/siteverify/index.mjs';
const nonce = '0123456789abcdef0123456789abcdef';
const body = { token: 'test-token', pageNonce: nonce, requestId: 'd4b420a9-217b-413b-bf1d-8bf0a1825a7d' };
const env = { TURNSTILE_SECRET: 'unit-test-secret' };
function request(data = body, origin = 'https://verify.horusmedia.net') {
    return new Request('https://siteverify.horusmedia.net/verify', {
        method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json', 'CF-Connecting-IP': '192.0.2.1' }, body: JSON.stringify(data),
    });
}
const result = { success: true, hostname: 'verify.horusmedia.net', action: 'horus_ads', cdata: nonce };
const upstream = (value) => async () => Response.json(value);
test('only a validated hostname/action/page binding succeeds; secret travels only to Cloudflare', async () => {
    let calls = 0;
    const response = await verifyRequest(request(), env, async (url, options) => {
        calls++;
        assert.equal(url, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        const sent = JSON.parse(options.body);
        assert.equal(sent.secret, env.TURNSTILE_SECRET);
        assert.equal(sent.idempotency_key, body.requestId);
        assert.equal(sent.remoteip, '192.0.2.1');
        return Response.json(result);
    });
    assert.equal(calls, 1);
    assert.deepEqual(await response.json(), { success: true, pageNonce: nonce });
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    assert.equal(response.headers.get('Access-Control-Allow-Origin'), 'https://verify.horusmedia.net');
});
test('hostname, action and cdata mismatch reject otherwise successful tokens', async () => {
    for (const key of ['hostname', 'action', 'cdata']) {
        const response = await verifyRequest(request(), env, upstream({ ...result, [key]: 'wrong' }));
        assert.equal(response.status, 422);
        assert.equal((await response.json()).success, false);
    }
});
test('wrong origin, oversized and malformed requests never reach Siteverify', async () => {
    let calls = 0;
    const spy = async () => { calls++; return Response.json(result); };
    assert.equal((await verifyRequest(request(body, 'https://evil.example'), env, spy)).status, 403);
    for (const invalid of [null, {}, { ...body, token: 'x'.repeat(2100) }, { ...body, pageNonce: 'x' }, { ...body, requestId: 'bad' }]) {
        assert.equal((await verifyRequest(request(invalid), env, spy)).status, 400);
    }
    assert.equal((await verifyRequest(request({ padding: 'x'.repeat(5000) }), env, spy)).status, 413);
    assert.equal(calls, 0);
});
test('replayed/rejected tokens are terminal, transient faults retryable, never fail open', async () => {
    for (const code of ['timeout-or-duplicate', 'invalid-input-response']) {
        const response = await verifyRequest(request(), env, upstream({ success: false, 'error-codes': [code] }));
        assert.deepEqual(await response.json(), { success: false, retryable: false });
    }
    for (const fn of [upstream({ success: false, 'error-codes': ['internal-error'] }), async () => { throw new Error('network'); }, async () => new Response('bad', { status: 502 })]) {
        const response = await verifyRequest(request(), env, fn);
        assert.equal(response.status, 503);
        assert.deepEqual(await response.json(), { success: false, retryable: true });
    }
    assert.equal((await verifyRequest(request(), {}, upstream(result))).status, 503);
});
test('preflight is origin-restricted and makes no validation call', async () => {
    const response = await verifyRequest(new Request('https://siteverify.horusmedia.net/verify', { method: 'OPTIONS', headers: { Origin: 'https://verify.horusmedia.net' } }), env, () => { throw new Error('must not call'); });
    assert.equal(response.status, 204);
    assert.equal(response.headers.get('Access-Control-Allow-Methods'), 'POST');
    assert.equal(response.headers.get('Access-Control-Max-Age'), '600');
    assert.equal(response.headers.get('Access-Control-Allow-Origin'), 'https://verify.horusmedia.net');
    assert.equal(response.headers.get('Access-Control-Allow-Headers'), 'Content-Type');
    assert.equal(response.headers.get('Access-Control-Allow-Credentials'), null);
    assert.equal(response.headers.get('Vary'), 'Origin');
    const denied = await verifyRequest(new Request('https://siteverify.horusmedia.net/verify', { method: 'OPTIONS', headers: { Origin: 'https://evil.example' } }), env);
    assert.equal(denied.status, 403);
    assert.equal(denied.headers.get('Access-Control-Max-Age'), null);
    assert.equal(denied.headers.get('Access-Control-Allow-Origin'), null);
});

test('cached CORS permission never caches verification or skips the next token check', async () => {
    let calls = 0;
    const verify = async () => {
        calls++;
        return Response.json(calls === 1 ? result : { success: false, 'error-codes': ['timeout-or-duplicate'] });
    };
    const first = await verifyRequest(request(), env, verify);
    const replay = await verifyRequest(request(), env, verify);
    assert.equal(first.status, 200);
    assert.equal(replay.status, 422);
    assert.equal(calls, 2);
    for (const response of [first, replay]) {
        assert.equal(response.headers.get('Cache-Control'), 'no-store');
        assert.equal(response.headers.get('Access-Control-Max-Age'), null);
    }
});
