# Cloudflare Pages Setup

`cdn.horusmedia.net` must be a static Cloudflare Pages Direct Upload project,
not a Hostinger origin. Add GitHub Secrets `CLOUDFLARE_ACCOUNT_ID` and
`CLOUDFLARE_API_TOKEN` (least-privilege Pages Edit), plus repository variable
`CLOUDFLARE_PAGES_PROJECT` when the project is not `horus-media-cdn`.

The control plane stores only a private `env:` or `file:` reference to its
least-privilege GitHub delivery token. The scheduler batches outbox items,
commits a sanitized `edge-delivery` tree, and dispatches Wrangler CI. Operations
must show `DEPLOYED`; `UPLOADING` proves submission only.

The static tree contains `_headers`, loader/Prebid assets, global control,
manifests, aliases, immutable JSON, `404.html`, and no `functions/` or
`_worker.js`. Static asset requests are free/unlimited only when no Function is
invoked; Pages builds/files/Functions remain limited.

Verify:

```bash
curl -I https://cdn.horusmedia.net/hm-loader.js
curl -I https://cdn.horusmedia.net/configs/PUBLIC_SITE_KEY/manifest.json
```

Browser Network evidence must show zero publisher requests to
`app.horusmedia.net`; GAM, bidder, and native requests go directly to providers.
