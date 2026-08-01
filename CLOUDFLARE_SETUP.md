# Cloudflare Pages Static Edge Setup

`cdn.horusmedia.net` is a Cloudflare Pages static project. Laravel on Hostinger
is the control plane and is never an origin for publisher runtime requests.

Cloudflare documents that Pages static asset requests are free and unlimited
when no Function is invoked. This does not make Pages generally unlimited:
builds, files, file size, projects, Functions, and storage have separate limits.
Review the current official [pricing](https://developers.cloudflare.com/pages/functions/pricing/)
and [limits](https://developers.cloudflare.com/pages/platform/limits/) before
changing the configured safety budget.

## Runtime layout

```text
hm-loader.js
assets/loader/hm-loader.HASH.min.js
assets/prebid/horus-prebid.HASH.min.js
configs/_global/control.json
configs/{SITE_KEY}/manifest.json
configs/{SITE_KEY}/production.json
configs/{SITE_KEY}/production.vN.HASH.json
configs/{SITE_KEY}/test.json
configs/{SITE_KEY}/preview.json
delivery-manifest.json
_headers
404.html
```

There must be no `functions/` directory or `_worker.js`. CORS, MIME, cache, and
noindex policy comes from `_headers`, not code execution.

## Create the Pages project

1. Create the Direct Upload Pages project named `horus-media-cdn` (or set the
   repository variable `CLOUDFLARE_PAGES_PROJECT`).
2. Add `cdn.horusmedia.net` as its custom domain and complete Cloudflare's DNS
   verification. Do not point the hostname at Hostinger.
3. Add GitHub Actions secrets:
   - `CLOUDFLARE_ACCOUNT_ID`
   - `CLOUDFLARE_API_TOKEN` with least-privilege Pages Edit access
4. Protect `main` and restrict workflow/secret administration.

The workflow follows Cloudflare's documented [Direct Upload with CI](https://developers.cloudflare.com/pages/how-to/use-direct-upload-with-continuous-integration/)
pattern and uses `cloudflare/wrangler-action`. Pull requests and normal `main`
pushes build and validate only. A production upload occurs after the control
plane creates an authorized `repository_dispatch`, or an owner explicitly runs
the workflow with `deploy=true`. Missing secrets skip the live step explicitly.

## Configure the Hostinger control plane

Use the static delivery values in `.env.production.example`. Store a fine-grained
GitHub token only in the private Hostinger environment or a non-public file and
reference it with `HORUS_EDGE_GITHUB_TOKEN_REFERENCE=env:HORUS_EDGE_GITHUB_TOKEN`
or `file:/private/path/token`. The token needs only this repository's Contents
read/write and Actions read access plus permission to create repository dispatches.
It must never enter MySQL, logs, static files, CI artifacts, or browser code.

The first batch creates the sanitized orphan `edge-delivery` branch. Subsequent
batches update only allowlisted static paths and remove obsolete managed files.
Cloudflare credentials never exist on Hostinger.

## Operations

- Normal changes wait for the configured batch window.
- Emergency pause is urgent and bypasses that delay.
- Repeated changes for one site/environment collapse to the newest version.
- A batch stays `UPLOADING` until its Pages workflow succeeds.
- Failed batches retain database payloads and can be retried from Operations.
- `HORUS_STATIC_DELIVERY_MONTHLY_BUDGET`, emergency reserve, retention, warning,
  and hard file limit are safety configuration, not claims about provider limits.

## Verification

```bash
curl -I https://cdn.horusmedia.net/hm-loader.js
curl -I https://cdn.horusmedia.net/configs/PUBLIC_SITE_KEY/manifest.json
curl -sS https://cdn.horusmedia.net/configs/PUBLIC_SITE_KEY/production.json | jq .
```

In browser Network tools, filter for `horusmedia.net`: every publisher runtime
request must use `cdn.horusmedia.net`; there must be zero requests to
`app.horusmedia.net`. GPT, GAM, bidder, and approved native requests go directly
to their providers. Confirm no cookies and the expected `_headers` cache/CORS
values. Record the batch ID, manifest hash, workflow run, Pages URL, and custom
domain response as go-live evidence.
