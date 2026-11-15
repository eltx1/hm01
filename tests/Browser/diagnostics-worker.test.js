import test from 'node:test';
import assert from 'node:assert/strict';
import {createCollector} from '../../workers/diagnostics/index.mjs';
const site = 'hm_observation_test';
const good = {schema: 1, configVersion: 7, sampleBp: 100, outcome: 'rendered', metrics: {config: 50, cloudflare_total: 700, first_ad: 1200, after_pass: 150}};
function fixture({config = {}, upstream, timeout = 1500, writer} = {}) {
    const writes = [], calls = []; let clock = 1000;
    const cfg = {siteKey: site, configVersion: 7, status: 'active', allowedHostnames: ['publisher.test'], observability: {performance: {enabled: true, sampleBp: 100}}, ...config};
    const collect = createCollector({now: () => clock, bodyTimeoutMs: timeout, upstream: upstream || (async url => {
        calls.push(url); return new Response(JSON.stringify(cfg), {headers: {'Content-Type': 'application/json'}});
    })});
    const env = {HORUS_DIAGNOSTICS: {writeDataPoint: writer || (point => writes.push(point))}};
    const request = (body = good, headers = {}, pathname = '/collect/'+site) => new Request('https://metrics.horusmedia.net'+pathname, {
        method: 'POST', headers: {'Origin': 'https://publisher.test', 'Content-Type': 'text/plain', 'User-Agent': 'PRIVATE_AGENT Android Chrome/150.0', 'CF-Connecting-IP': '198.51.100.5', ...headers},
        body: typeof body === 'string' ? body : JSON.stringify(body),
    });
    return {collect, env, request, writes, calls, advance: ms => clock+=ms};
}
test('records only bounded dimensions and durations, never raw browser or authorization data', async () => {
    const h = fixture(); const response = await h.collect(h.request(), h.env);
    assert.equal(response.status, 200); assert.equal(h.writes.length, 6);
    assert.deepEqual(h.writes[2], {indexes: [site], blobs: [site, 'android', 'chrome', 'config', '7'], doubles: [50, 100]});
    const saved = JSON.stringify(h.writes);
    for (const privateValue of ['PRIVATE_AGENT', '198.51.100.5', 'https://publisher.test']) assert.equal(saved.includes(privateValue), false);
    assert.equal(h.calls[0], 'https://cdn.horusmedia.net/configs/'+site+'/production.json');
    assert.equal(response.headers.get('Access-Control-Allow-Origin'), 'https://publisher.test');
});
for (const config of [{status: 'paused'}, {immediatePause: true}, {siteKey: 'hm_other'}, {observability: {performance: {enabled: false, sampleBp: 100}}}, {observability: {performance: {enabled: true, sampleBp: 10001}}}]) {
    test('disabled, paused or mismatched configuration cannot collect', async () => {
        const h = fixture({config}); assert.equal((await h.collect(h.request(), h.env)).status, 403); assert.equal(h.writes.length, 0);
    });
}
test('unconfigured binding and unregistered origins cannot write', async () => {
    const h = fixture(); assert.equal((await h.collect(h.request(), {})).status, 503); assert.equal(h.calls.length, 0);
    assert.equal((await h.collect(h.request(good, {'Origin': 'https://other.test'}), h.env)).status, 403);
    assert.equal((await h.collect(h.request(good, {'Origin': 'https://publisher.test/private'}), h.env)).status, 403);
    assert.equal(h.writes.length, 0);
});
for (const body of [{...good, token: 'PRIVATE'}, {...good, metrics: {url: 'PRIVATE'}}, {...good, metrics: {first_ad: -1}}, {...good, metrics: {first_ad: 60001}}, {...good, metrics: {}}, {schema: 2, metrics: {}, outcome: 'incomplete'}]) {
    test('rejects unexpected private fields and invalid measurements', async () => {
        const h = fixture(); assert.equal((await h.collect(h.request(body), h.env)).status, 400); assert.equal(h.writes.length, 0);
    });
}
test('registry failure never falls back around CDN protection', async () => {
    let calls = 0; const h = fixture({upstream: async () => {calls++; return new Response('Forbidden', {status: 403});}});
    assert.equal((await h.collect(h.request(), h.env)).status, 503); assert.equal(calls, 1); assert.equal(h.writes.length, 0);
});
test('oversized and stalled requests are bounded before recording', async () => {
    const h = fixture({timeout: 10});
    assert.equal((await h.collect(h.request('x'.repeat(2049)), h.env)).status, 413);
    const stalled = new Request('https://metrics.horusmedia.net/collect/'+site, {method: 'POST', headers: {Origin: 'https://publisher.test', 'Content-Type': 'text/plain'}, body: new ReadableStream({start() {}}), duplex: 'half'});
    assert.equal((await h.collect(stalled, h.env)).status, 408); assert.equal(h.writes.length, 0);
});
test('per-isolate rate bound and cache avoid unbounded repeated registry lookups', async () => {
    const h = fixture();
    for (let i=0;i<10;i++) assert.equal((await h.collect(h.request(),h.env)).status,200);
    assert.equal((await h.collect(h.request(),h.env)).status,429); assert.equal(h.calls.length,1);
    h.advance(60001); assert.equal((await h.collect(h.request(),h.env)).status,200); assert.equal(h.calls.length,2);
});
test('write failure stays an observational service failure and discloses no raw exception', async () => {
    const h = fixture({writer: () => {throw Error('PRIVATE_ACCOUNT_DATA');}});
    const r = await h.collect(h.request(),h.env); assert.equal(r.status,503); assert.equal((await r.text()).includes('PRIVATE_ACCOUNT_DATA'),false);
});

test('old configuration or sample policy cannot be combined with a new version', async () => {
 const h=fixture();
 assert.equal((await h.collect(h.request({...good,configVersion:6}),h.env)).status,409);
 assert.equal((await h.collect(h.request({...good,sampleBp:10}),h.env)).status,409);
 assert.equal(h.writes.length,0);
});
