const ORIGIN = 'https://verify.horusmedia.net';
const ACTION = 'horus_ads';
const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

export async function verifyRequest(request, env, upstream = fetch) {
    const headers = {
        'Content-Type': 'application/json',
        'Cache-Control': 'no-store',
        'X-Content-Type-Options': 'nosniff',
        'Vary': 'Origin',
    };
    const respond = (status, data) => new Response(JSON.stringify(data), { status, headers });
    if (new URL(request.url).pathname !== '/verify') return respond(404, { success: false });
    if (request.headers.get('Origin') !== ORIGIN) return respond(403, { success: false });
    headers['Access-Control-Allow-Origin'] = ORIGIN;
    if (request.method === 'OPTIONS') {
        headers['Access-Control-Allow-Methods'] = 'POST';
        headers['Access-Control-Allow-Headers'] = 'Content-Type';
        return new Response(null, { status: 204, headers });
    }
    if (request.method !== 'POST') return respond(405, { success: false });
    if (!request.headers.get('Content-Type')?.startsWith('application/json')) return respond(415, { success: false });
    // Bound the actual body, including chunked requests. Never log tokens or IPs.
    const reader = request.body?.getReader();
    if (!reader) return respond(400, { success: false });
    let bytes = 0;
    const chunks = [];
    try {
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            bytes += value.byteLength;
            if (bytes > 4096) {
                await reader.cancel();
                return respond(413, { success: false });
            }
            chunks.push(value);
        }
    } catch { return respond(400, { success: false }); }
    let body;
    try {
        const joined = new Uint8Array(bytes);
        let offset = 0;
        for (const chunk of chunks) { joined.set(chunk, offset); offset += chunk.byteLength; }
        body = JSON.parse(new TextDecoder().decode(joined));
    } catch { return respond(400, { success: false }); }
    if (!body || typeof body.token !== 'string' || !body.token || body.token.length > 2048
        || typeof body.pageNonce !== 'string' || !/^[A-Za-z0-9_-]{16,128}$/.test(body.pageNonce)
        || typeof body.requestId !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(body.requestId)) {
        return respond(400, { success: false });
    }
    if (!env.TURNSTILE_SECRET) return respond(503, { success: false, retryable: true });
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 3500);
    try {
        const response = await upstream(ENDPOINT, {
            // Workers rejects redirect:"error" before sending the request.
            // manual + the response.ok check below rejects every redirect
            // without ever forwarding the secret to another destination.
            method: 'POST', redirect: 'manual', signal: controller.signal,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                secret: env.TURNSTILE_SECRET,
                response: body.token,
                idempotency_key: body.requestId,
                ...(request.headers.get('CF-Connecting-IP') ? { remoteip: request.headers.get('CF-Connecting-IP') } : {}),
            }),
        });
        if (!response.ok) return respond(503, { success: false, retryable: true });
        const result = await response.json();
        if (result.success !== true) {
            const transient = Array.isArray(result['error-codes']) && result['error-codes'].includes('internal-error');
            return respond(transient ? 503 : 422, { success: false, retryable: transient });
        }
        if (result.hostname !== 'verify.horusmedia.net' || result.action !== ACTION || result.cdata !== body.pageNonce) {
            return respond(422, { success: false, retryable: false });
        }
        return respond(200, { success: true, pageNonce: body.pageNonce });
    } catch { return respond(503, { success: false, retryable: true }); }
    finally { clearTimeout(timer); }
}

export default { fetch: (request, env) => verifyRequest(request, env) };
