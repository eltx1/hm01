# Cloudflare Setup

Use Full (strict) TLS for all three domains.

## app.horusmedia.net

- Bypass cache for all application routes.
- Do not cache authenticated HTML, CSRF responses, or `/up`.
- Enable managed WAF rules and bot protections conservatively.
- Preserve real visitor IP headers.
- Redirect HTTP to HTTPS.
- Add HSTS only after every required subdomain is permanently HTTPS.

## cdn.horusmedia.net

Suggested cache policy:

- Versioned loader and Prebid assets: `Cache-Control: public, max-age=31536000, immutable`.
- Stable `hm-loader.min.js`: `public, max-age=300, stale-while-revalidate=86400`.
- Versioned site JSON (`*.vN.json`): one year immutable.
- Current site JSON (`production.json`): respect the configured short TTL, normally 60 seconds.
- Enable CORS for GET/HEAD on public loader, Prebid, and JSON configuration files.
- Never place credentials, invoices, contracts, logs, database exports, or `.env` on this origin.

Purge only stable aliases during rollback; versioned assets should remain immutable.
