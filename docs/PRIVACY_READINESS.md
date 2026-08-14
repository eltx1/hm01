# Privacy readiness and live evidence

Privacy Readiness distinguishes a structurally valid runtime policy from a
current browser observation. Neither state is a legal opinion or certification.

## States and evidence

The centralized readiness service returns `READY`, `WARNING`, `BLOCKED`,
`UNKNOWN`, `STALE`, or `NOT_APPLICABLE`, plus machine-readable finding codes.
Site 360 and Monetization Center display separate sections for configuration,
the live test, TCF, GPP, GPC, Prebid, Google CMP operator evidence, and provider
privacy capabilities.

`CONFIGURED` means the stored runtime policy is structurally valid.
`LIVE_VERIFIED` means a current, bounded one-shot report was accepted from an
authorized publisher hostname and its required APIs/gate checks passed. Live
evidence becomes `STALE` after `PRIVACY_PROBE_STALE_DAYS` (30 days by default).

The policy explicitly records whether TCF, GPP, or Google CMP evidence is
required. Horus does not infer a legal requirement from geography, a CMP ID, or
the mere presence of configuration.

## Explicit live diagnostic

An authorized Horus Admin chooses **Run Live Privacy Test** in Site 360. Horus
creates a cryptographically random token that is:

- stored only as a SHA-256 hash;
- bound to one site, one environment, and that site's authorized hostnames;
- valid for approximately 10 minutes by default;
- accepted for one report; and
- protected by Admin and public endpoint rate limits.

The Admin opens the generated publisher URL. The Loader removes the token from
the visible URL before config retrieval or ad initialization, waits for the
existing privacy gate, and sends at most one report. The request uses
`credentials: "omit"`, narrow origin CORS, and no session cookie. The endpoint
accepts only JSON matching the fixed schema and rejects oversized bodies,
unknown fields, expired tokens, replay, origin/hostname mismatch, and use on
another site.

This one-shot POST is the sole documented privacy exception to the normal
no-browser-telemetry rule. It is not an ad-serving endpoint. Normal visitors do
not receive a token and the Loader performs no Laravel diagnostic request.

## Data boundary

Stored evidence is limited to site/environment, result state, Loader/config
versions, hostname, API detected/responded flags, bounded CMP event metadata,
GPP section identifiers, GPC detection, configured timeout action, Prebid
privacy-module/config flags, privacy-gate result, server observation time, and a
result hash.

The payload and storage schema do not accept complete TC or GPP strings, user
IDs, cookies, fingerprints, browsing history, impressions, bidder responses,
clicks, or ad delivery events. Ordinary web-server request handling may still
observe network metadata under the platform's normal security/logging policy;
the privacy evidence record does not derive or persist an identifier from it.

## Google CMP operator evidence

When a site's runtime policy explicitly requires Google-related CMP evidence,
an Admin may record the CMP name, TCF CMP ID, platform, verification date, and
operator status. Evidence is `VERIFIED`, `NOT_VERIFIED`, `STALE`, or
`NOT_APPLICABLE`. Horus does not bundle or scrape Google's certified-CMP list and
does not label a site “Google compliant” merely because an ID was entered.

The default evidence age is controlled by
`GOOGLE_CMP_EVIDENCE_STALE_DAYS` (90 days). Operators must compare against the
current official source when they verify or renew evidence.

## Provider privacy capabilities

Bidder and Direct JS demand definitions contain only narrow privacy capability
metadata: TCF, GPP, GPC, consent-before-request, storage, and user-sync. Existing
providers default to `UNKNOWN`. An Admin may record `SUPPORTED` or
`NOT_SUPPORTED` only from operator/current official evidence. This is not a
general provider compatibility matrix.

## Official implementation references

- [Prebid consent management — TCF](https://docs.prebid.org/dev-docs/modules/consentManagementTcf.html)
- [Prebid consent management — GPP](https://docs.prebid.org/dev-docs/modules/consentManagementGpp.html)
- [Prebid activity controls](https://docs.prebid.org/dev-docs/activity-controls.html)
- [Prebid Storage Control module](https://docs.prebid.org/dev-docs/modules/storageControl.html)
- [Google publisher CMP requirements](https://support.google.com/adsense/answer/13554116)

These sources describe technical requirements and vendor programs. They do not
make Horus configuration or evidence a legal-compliance determination.
