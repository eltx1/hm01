# Live video serving-policy enforcement

## Contract

The Loader binds `__hmVideoCanRequestAds` to each structured Horus video recipe.
The callback uses the current Loader's `directJsServingAllowed` decision, including
Traffic Gate, privacy/CMP, Click Guard and serving controls, plus active placement
and config identity. The player never duplicates click thresholds, trusts a cached
PASS or obtains authorization from a DOM attribute or an event payload. Missing,
throwing and non-boolean grants fail closed. Directly injecting the runtime without
the Loader is not a supported authorization path.

This is a same-page coordination contract, not a security boundary against arbitrary
JavaScript already executing in a trusted publisher page. Turnstile verification
remains server-side and unchanged.

## Enforcement points

- Before the IMA library is used and after delayed library readiness.
- Before every VAST request and immediately before dispatch.
- At delayed AdsManager delivery and before any viewability-deferred start.
- At every VMAP/Ad Rules `AD_BREAK_READY`, with automatic breaks disabled through
  the per-AdsLoader `setAutoPlayAdBreaks(false)` setting.
- Before signaling content completion to IMA (which can request a post-roll).
- At policy-change, focus/visibility restoration and content-progress callbacks.

A block cancels pending starts/timers and destroys this player's SDK manager and
loader without calling `contentComplete`. Callback generation checks prevent old
manager/load/error/completion events from resurrecting an ad or granting a reward.
The existing Click Guard storage event path also notifies already mounted players.
A notification is only a request to re-evaluate, never permission to serve.

VMAP ad responses can be prefetched internally by IMA. A request that was already
sent before the block cannot be retracted. The implementation prevents later
Horus dispatches, stops the remaining scheduler on observed revocation and gates
playback; it does not claim that previously sent SDK requests never happened.

## Content and presentation

Content failure is not an authorization failure. Absent/broken content still allows
VAST whenever the central policy allows advertising. Conversely, a security block
suppresses ads even when the content video is healthy. Healthy content can continue
without ads; ad-only or failed-content surfaces close cleanly. The suppression is
latched for that player so an expired block or a forged notification cannot restart
old pending ads. A new legitimate page/session can be admitted normally.

Inline/floating layout, original video/iframe continuity and bottom-sticky clearance
are unchanged. No publisher installation-code, Cloudflare rule, thresholds, secrets,
GAM classification, database or reporting changes are needed.

## Diagnostics and tests

Local attributes `data-hm-video-policy-state="blocked"` and
`data-hm-video-policy-stage` distinguish policy denial from VAST no-fill/errors.
No raw click, impression or video-progress data is sent to Laravel.

The browser policy matrix composes the same three Loader transforms as production,
uses the actual cross-origin Traffic Gate HTML/JavaScript and actual Click Guard
accounting, and substitutes only offline Siteverify/Turnstile/IMA/media boundaries.
It runs in all existing Chromium/WebKit desktop/mobile projects. Coverage includes
initial rejection/timeout, forged notifications, real same-page probable clicks,
cross-tab storage, late SDK/manager/viewability races, manual and scheduled breaks,
valid content continuation, content failure, transformed publisher ancestors and
single-request floating continuity. Existing sticky/close regressions also use the
full Loader composition. There are no live ad auctions or billable test impressions.
