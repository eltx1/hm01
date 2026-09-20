# Server-verified advertising gate

The verification iframe remains on `https://verify.horusmedia.net/traffic-gate/`.
Only that origin can make browser requests to `https://siteverify.horusmedia.net/verify`.
The existing invisible widget runs with action `horus_ads` and the document's
cryptographically random nonce in `cData`. The Worker accepts a token only when
Cloudflare returns success, hostname `verify.horusmedia.net`, action `horus_ads`,
and the matching nonce. The hostname is the iframe hostname, not the publisher.
Publisher eligibility is independently checked against the static site configuration
before rendering the widget. Tokens and IP addresses are not persisted or logged.

The widget callback alone cannot release ads. Protocol v2 requires the bound
iframe's server-verified result, exact origin/source and page nonce. Old protocol
messages cannot release the new loader. All enabled policy presets require
verification; the legacy BALANCED/PERMISSIVE names no longer imply soft allow.
A disabled gate or explicit emergency disable retains its existing administrative
meaning. An enabled but invalid gate configuration suppresses ads rather than
silently behaving as disabled.

The default total deadline is 10 seconds (configurable 2–15 seconds). Existing
explicit timing overrides remain unchanged. The deadline starts before iframe
creation. Temporary verification failures receive at most one retry using the
same token and UUID idempotency key. Upstream attempts have a 3.5 second timeout,
client verification attempts 4 seconds, always subordinate to the total deadline.
Widget errors retain one bounded reset. Timer expiry and visitor interaction never
authorize advertising. Terminal failure removes the invisible iframe and releases
resources; article access remains unchanged. No visitor ban, cookie, localStorage
pass, or persistent bot classification is created. Refresh/scan in the same document
cannot start another challenge or ads after failure.

## Deployment order

1. Merge/deploy the infrastructure-only Worker PR first. The deployment script's
   default is read-only; `--apply` is required for writes. It reuses the existing
   invisible `Horus Ad Traffic Gate` widget and moves its existing secret directly
   from Cloudflare's widget API into a `secret_text` binding in memory. No rotation,
   plaintext secret file, secret output, or user-pasted secret is needed.
2. `siteverify.horusmedia.net` uses a Worker Custom Domain. Domain/service collision
   checks refuse overwrites. The existing zone/WAF, CDN and verify Pages project are
   not reconfigured. Workers.dev and preview URLs are disabled. The dedicated
   workflow records the actor/commit and redacted operation summary.
3. Verify Worker/domain and secret-binding readback before merging loader changes.
4. Release the Laravel resolver, new iframe code/CSP and new loader together.
   Static sync calls `traffic-gate:refresh-configs --apply`, which republishes only
   changed contracts for active publishers through the audited existing publisher.
   Without `--apply` it is a dry-run. Unchanged contracts do not create versions.
5. Verify static manifest/asset parity, live gate CSP and rejected-token behavior.
   A real successful challenge must be checked in a normal publisher browser;
   synthetic browser fixtures are not evidence of live Cloudflare acceptance.

The API token used by the existing production environment must permit widget read,
Workers script edit, Workers domain routing and zone read. Permission failures stop
deployment; never change WAF rules or create a verification bypass to fix them.
Do not activate the new client contract if Worker deployment is unavailable.

## Boundaries

Turnstile is one traffic-quality signal, not a guarantee against IVT. The publisher
page and loader execute in a client-controlled environment. This design verifies
normal Horus serving before requests; it cannot stop a malicious party from calling
public third-party ad tags outside Horus. CORS is browser isolation, not endpoint
authentication. Click Guard, consent, authorized inventory and ad-network controls
remain independent. No ad, bid or impression request is proxied through Laravel.

Official references:
- https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- https://developers.cloudflare.com/turnstile/get-started/widget-management/api/
- https://developers.cloudflare.com/workers/configuration/routing/custom-domains/
