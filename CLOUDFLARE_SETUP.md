# Cloudflare and CDN Setup

The CDN is a public static origin for the Horus Loader, Prebid bundles, and
published site configurations. Laravel must not sit in the ad-request path.

## Origin layout

Point cdn.horusmedia.net at a dedicated public directory containing:

~~~text
hm-loader.js
hm-loader.min.js
assets/prebid/horus-prebid.js
assets/prebid/horus-prebid.min.js
assets/prebid/horus-prebid.sha256
configs/{PUBLIC_SITE_KEY}/production.json
configs/{PUBLIC_SITE_KEY}/production.vN.json
~~~

Keep the application source, .env, vendor/, credentials, logs, and private
contract files outside this document root.

## DNS and TLS

- Use a proxied HTTPS DNS record only when the chosen Cloudflare plan and cache behavior are understood.
- Enable a valid certificate before enabling HSTS.
- Use Full (strict) TLS between Cloudflare and the origin.
- Do not enable email forwarding or wildcard routes that expose private origins.
- Restrict origin access where the hosting provider supports it.

## Response headers

Apply these headers to the CDN origin:

| Resource | Cache-Control |
|---|---|
| hm-loader.js | public, max-age=300, stale-while-revalidate=86400 |
| versioned loader and Prebid assets | public, max-age=31536000, immutable |
| production.vN.json | public, max-age=31536000, immutable |
| production.json | public, max-age=30, must-revalidate, stale-while-revalidate=60 |
| preview.json and test.json | no-cache |

Also return:

~~~text
Access-Control-Allow-Origin: *
X-Content-Type-Options: nosniff
~~~

Serve JSON with application/json; charset=utf-8 and JavaScript with an
appropriate JavaScript content type. Do not attach cookies or private headers
to public CDN responses.

## Cache rules

1. Cache immutable, versioned assets aggressively.
2. Keep the current production.json alias short-lived.
3. Purge only the affected site-key path after publication or emergency pause.
4. Do not cache Laravel dashboard or authentication responses on the CDN.
5. Verify that a paused configuration reaches the browser within the documented TTL before onboarding a publisher.

## Smoke test

For each pilot site, verify from an external network:

~~~bash
curl -I https://cdn.horusmedia.net/hm-loader.min.js
curl -I https://cdn.horusmedia.net/configs/PUBLIC_SITE_KEY/production.json
curl -sS https://cdn.horusmedia.net/configs/PUBLIC_SITE_KEY/production.json | jq .
~~~

Confirm no secrets, private IDs, credentials, revenue shares, or internal notes
appear in the public payload.
