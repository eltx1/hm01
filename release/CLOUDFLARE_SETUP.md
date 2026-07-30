# Cloudflare Setup

## DNS and TLS

- Proxy `app.horusmedia.net` and `cdn.horusmedia.net` through Cloudflare after origin SSL is valid.
- Use Full (strict) origin TLS, Always Use HTTPS, and TLS 1.2 or newer.
- Keep `APP_TRUSTED_HOSTS=app.horusmedia.net`. Use `TRUSTED_PROXIES=*` only when the origin is firewalled or otherwise reachable exclusively through trusted proxies.
- Enable HSTS first without preload; add preload only after every required subdomain is permanently HTTPS.

## Cache policy

- Bypass cache for `app.horusmedia.net/*`, especially login, account, admin, reports, downloads, and CSRF-bearing pages.
- Cache hashed files under `cdn.horusmedia.net/assets/` and application `/build/` for one year with immutable semantics.
- Cache `hm-loader.min.js` according to release versioning; purge it when promoting or rolling back a Loader release.
- Do not long-cache `cdn.horusmedia.net/configs/control.json`; use no-store or a very short edge TTL.
- Site configuration aliases under `/configs/` should use the configured short TTL and be purged after emergency publication or rollback.

## WAF and rate controls

- Apply managed WAF rules to the application hostname.
- Challenge or limit abusive POST traffic to `/login`, password reset, TOTP challenge, upload, and administrative mutation endpoints.
- Never cache or transform credential, contract, invoice, statement, or report downloads.
- Preserve `X-Forwarded-Proto` so Laravel generates HTTPS URLs and secure cookies.

## Verification

Check application response headers, HTTPS redirects, cookie flags, Loader/config accessibility, browser CORS behavior, and that the origin does not expose `.env`, logs, storage internals, directory listings, or the project root.
