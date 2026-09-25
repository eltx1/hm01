import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { Miniflare } from 'miniflare';

// Exercise the actual default export and native Workers fetch, not a JS fetch stub.
const nonce = '0123456789abcdef0123456789abcdef';
const payload = { token: 'synthetic-token', pageNonce: nonce, requestId: 'd4b420a9-217b-413b-bf1d-8bf0a1825a7d' };
const valid = { success: true, hostname: 'verify.horusmedia.net', action: 'horus_ads', cdata: nonce };

test('native Workers request reaches Siteverify; redirects never forward the secret', async () => {
    let reply = () => Response.json(valid);
    const calls = [];
    const mf = new Miniflare({
        modules: true,
        script: await readFile(new URL('../../workers/siteverify/index.mjs', import.meta.url), 'utf8'),
        compatibilityDate: '2026-09-01',
        bindings: { TURNSTILE_SECRET: 'synthetic-secret' },
        outboundService: async (request) => {
            calls.push({ url: request.url, body: await request.json() });
            return reply();
        },
    });
    const verify = () => mf.dispatchFetch('https://siteverify.horusmedia.net/verify', {
        method: 'POST',
        headers: { Origin: 'https://verify.horusmedia.net', 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    try {
        const preflight = await mf.dispatchFetch('https://siteverify.horusmedia.net/verify', {
            method: 'OPTIONS', headers: { Origin: 'https://verify.horusmedia.net', 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'content-type' },
        });
        assert.equal(preflight.status, 204);
        assert.equal(preflight.headers.get('Access-Control-Max-Age'), '600');
        assert.equal(preflight.headers.get('Access-Control-Allow-Origin'), 'https://verify.horusmedia.net');
        assert.equal(calls.length, 0);
        const success = await verify();
        assert.equal(success.status, 200, 'native fetch must not throw before contacting Siteverify');
        assert.deepEqual(await success.json(), { success: true, pageNonce: nonce });
        assert.equal(success.headers.get('Cache-Control'), 'no-store');
        assert.equal(success.headers.get('Access-Control-Max-Age'), null);
        assert.equal(calls.length, 1);
        assert.equal(calls[0].url, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        assert.equal(calls[0].body.secret, 'synthetic-secret');
        assert.equal(calls[0].body.idempotency_key, payload.requestId);

        for (const status of [301, 302, 303, 307, 308]) {
            const before = calls.length;
            reply = () => new Response(null, { status, headers: { Location: 'https://untrusted.example/collect' } });
            const rejected = await verify();
            assert.equal(rejected.status, 503);
            assert.equal((await rejected.json()).success, false);
            assert.equal(calls.length, before + 1, 'must not follow the redirect');
            assert.equal(calls.at(-1).url, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        }
        for (const data of [
            { ...valid, hostname: 'wrong.example' },
            { ...valid, action: 'wrong' },
            { ...valid, cdata: 'another-page-nonce' },
            { success: false, 'error-codes': ['invalid-input-response'] },
        ]) {
            reply = () => Response.json(data);
            const rejected = await verify();
            assert.equal(rejected.status, 422);
            assert.equal((await rejected.json()).success, false);
        }
    } finally {
        await mf.dispose();
    }
});
