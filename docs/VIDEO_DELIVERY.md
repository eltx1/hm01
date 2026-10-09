# Video delivery contract

## Admin input and sizes

Quick Monetize's **GAM ad unit path** mode accepts a validated path such as
`/123456/video` or a supported parent/child network path with nested ad units.
Ordinary Video placements with valid HTTPS accompanying content default to
**Linear video + non-linear overlays** (`format_settings.videoAdFormat=mixed`).
Quick Monetize also offers `video_only`. Rewarded and ad-only inventory retain
their linear lifecycle. Generated mixed GAM templates omit `vad_type`; generated
video-only templates use `vad_type=linear`. Both retain `ad_type=video`.
The complete-tag mode continues accepting third-party VAST URLs.
Generated tags do not change ad-rule/network settings, disable fallback, impose a maximum ad duration, or assert
user privacy/consent values. Previously saved publisher constraints are retained.

The selectable video masters are 300×250, 320×180, 336×280, 400×225, 400×300 and
640×480. A master defines the media aspect ratio and maximum floating size,
not six simultaneous ad slots. Inline media fills the publisher container up to
640px (or an existing custom master wider than 640px); narrow columns remain
constrained by their own available width. A narrow column already filled by the
previous player will retain that same physical inline size; enlargement never
overflows the publisher column. Floating size and sticky clearance
remain based on the selected master. Returning inline restores the larger
responsive surface without recreating media or requesting another ad.

Existing saved responsive layouts remain valid. IMA linear slot dimensions
always describe the actual rendered media box at request time, excluding chrome;
its manager uses CSS layout pixels for a transformed publisher surface.
GAM `sz` is separate inventory targeting: valid configured single or pipe-separated
sizes are preserved, including explicit `1x1`. Generated tags use the selected
master. Missing, empty, unresolved-placeholder, malformed or non-positive `sz`
falls back to that selected master, never an invented article-width inventory size.
See the [Google IMA FAQ](https://developers.google.com/interactive-media-ads/docs/sdks/html5/client-side/faq)
for the distinction between player dimensions and `sz` targeting.
Additional supported sizes do not guarantee ad availability or increased fill.

## URL and runtime publication

VAST URLs have a 10,000-byte parser limit. URLs whose base64 fits in a 2,000-character
attribute retain the legacy single attribute. Longer URLs use
`data-hm-vast-url-parts` and zero-based 1,800-character chunks. Publication rejects
mixed, missing, extra or malformed chunks rather than truncating a valid-looking
URL. Runtime decoding is bounded and rejects invalid transport before requesting
an ad. Third-party request URLs are preserved unchanged.

The video runtime is published under its content-hashed URL. The production
static refresh rebuilds stale active Quick Monetize recipes from the original
stored tag, so previously truncated generated configurations are repaired during
publication. Static snapshot tests verify the config reference and runtime bytes
agree. Publisher installation snippets do not need to change.

## Bounded startup and privacy

SDK acquisition is bounded at 10 seconds. Each actual ad attempt separately
allows 15 seconds for VAST resolution, 15 seconds to regain viewability after the
manager arrives, and 15 seconds for media startup. The media phase accommodates
IMA's 12-second media timeout. Duplicate callbacks do not extend these budgets.
The Loader allows at most 60 seconds for the trusted video renderer, covering
these phases with a small scheduling margin; GPT and other providers keep their
existing timeout limits. Independent banner startup remains parallel.

Ordinary content video permits at most **two total preroll attempts**: the
initial request and one retry after a 1,000ms delay. All retry causes share this
single budget. Eligible pre-response loader errors are no-fill codes 303/1009
and selected transient codes 301/1012. Legacy recipes can use a narrowly qualified audible autoplay denial (IMA 1205)
for one muted retry. Fixed sound-on recipes never switch that declaration. Mixed error causes cannot produce a third request. Known VMAP,
rewarded/ad-only inventory, a manager/ad/content response that has already
begun, and unrelated fatal errors do not enter the generic retry path. Each
actual attempt retains the independent request, viewability and media watchdogs
above; there is no new shared cutoff across attempts.

Retries recheck player ownership, visibility, consent and playback intent.
Dismissal, navigation/teardown or a superseded attempt retires pending work.
After an ineligible or exhausted failure, owned content resumes or the
ad-only/rewarded attempt closes. VMAP content-resume without preroll retires the
initial startup watchdog and retains the SDK-owned future schedule. Existing
Click Guard and reward-completion requirements remain in force. Test fixtures
use a simulated IMA boundary and never contact paid demand.

## Mixed-format lifecycle and geometry

Mixed support uses the existing IMA content player and ad display container.
There is no GPT fallback, second renderer or synthetic impression. The bounded
startup retry above never retries an active ad or creates an overlay retry loop.
A true non-linear `LOADED` event resumes content, keeps the SDK layer clickable,
and leaves content clock/EOS observation attached. `LINEAR_CHANGED` restores the
correct ownership if the creative changes mode. Linear video and SDK-converted
full-slot image/text ads continue through the linear pause/resume lifecycle.
`isLinear()` describes playback mode, not evidence that the asset is a video.
Diagnostics expose the SDK's current linearity and content type, when available.

The non-linear request area is the full usable media area, conservatively capped
to the future compact area when floating is enabled. Chrome is excluded. IMA,
not Horus, derives `afvsz`; no arbitrary size list is added. For example, a
336×280 compact player can accommodate rectangular image demand, whereas a
320×180 player fits none of GAM's documented non-linear sizes. This is valid
reduced eligibility, not a reason to enlarge the compact player or misstate sizes.
If a true overlay no longer fits after a viewport shrink, it is retired through
IMA instead of being cropped. No `forceNonLinearFullSlot` flag is enabled.
See [GAM sizing parameters](https://support.google.com/admanager/answer/10678356?hl=en#afvsz)
and the [IMA request reference](https://developers.google.com/interactive-media-ads/docs/sdks/html5/client-side/reference/class/google.ima.AdsRequest).

While a true overlay is active, content Play/Pause and Mute controls occupy the
existing separate 44px chrome rail. No control covers the creative. User pause
intent survives overlay completion. SDK completion, close, content failure,
content EOS and player dismissal all release ownership and stale callbacks.
IMA owns the overlay duration; Horus does not replace it with a video-duration
countdown. A midpoint reached under an active overlay is consumed rather than
queued as an immediate back-to-back midroll. EOS retires that overlay and allows
only the existing one postroll. A true non-linear postroll cannot continue content
that has already ended and closes cleanly; a linear/full-slot postroll still plays.
The initial midpoint and final postroll remain; longer content may additionally
use the eligible repeat opportunities described below.

VMAP remains SDK-scheduled and its manager survives between linear breaks. The
HTML5 IMA compatibility matrix lists VMAP overlays as unsupported; GAM also
excludes AdSense/AdX overlays when video ad rules are enabled. Mixed mode does
not silently turn off those rules. Unexpected VMAP non-linear breaks are discarded
without replacing the remaining schedule. Explicit `ad_rule=1` and VMAP output
formats suppress Horus `vpos` injection. No network/account setting is changed.
See [IMA compatibility](https://developers.google.com/interactive-media-ads/docs/sdks/html5/client-side/compatibility)
and [GAM backfill eligibility](https://support.google.com/admanager/answer/1734048?hl=en).

## Existing generated templates and truthful requests

Publication rebuilds canonical legacy GAM-path templates from widget-level
`GAM_VIDEO_PATH` provenance, its saved path, selected master and effective format.
The old/new canonical template must match exactly. A manual URL, modified template,
or inherited/ambiguous provenance is never treated as permission to remove an
explicit restriction. Existing `demand:refresh-quick-runtimes` previews and then
idempotently publishes the changed immutable recipes using its normal apply flow.

Full manual content tags retain explicit `vad_type`, ad rules, privacy and custom
parameters. A linear-only renderer safely rejects unsupported non-linear responses;
it does not broaden an explicitly non-linear content tag into a linear auction.
Other third-party URLs remain byte-for-byte unchanged. The established ad-only
and rewarded fallback remains linear. The working manual tag was used only as
structural evidence: its unrelated page URL, `npa=0`, `tfcd=0` and test parameters
are not copied into generated production requests.

At dispatch, `vpmute` (1/0), `vpa` (auto/click) and IMA playback hints come from
one fixed snapshot of the observed playback intent. A later viewer mute/volume
change cannot rewrite a request already sent. The arriving and playing IMA
manager instead follows the latest viewer audio state, including a change made
while waiting for the response. SDK initialization or media-state restoration
cannot overwrite that newer choice. A zero-volume slider is muted even when the
media element's `muted` flag is false; nonzero viewer volume is retained.
Content-timeline breaks retain the original content start method; click-start
content and rewarded requests retain click intent. Page/description,
consent, viewability and break-position signals remain truthful. A returned 303
is still no-fill, not proof of a broken player or a guaranteed fixable filter.
The earlier successful manual tag subsequently also returned 303 in the official
inspector. Mixed support expands supported creative formats; it cannot promise
auction fill or prove why any individual request was empty.

Regression fixtures are deterministic IMA boundary doubles and local content,
never paid ad requests. Their rendering and event tests verify Horus behavior,
not Google's live auction eligibility or the exact creative returned in VSI.

## Fixed platform video configuration (October 9, 2026)

All generated content-video recipes, including refreshed existing sites, use
`data-hm-video-fixed-instream=1`, `data-hm-video-content-mode=instream`,
`data-hm-video-muted=0` and autoplay. Google VAST requests have fixed
`plcmt=1` and `vpmute=0`. No content-play probe or extra click gate precedes
an automatic IMA request. Browser playback policy can still reject sound-on
media; Horus does not claim that a successful auction overrides browser policy.
The native controls remain available. A viewer's later mute suspends new
sound-on auctions until they unmute, without rewriting either fixed parameter.

The per-site inventory selector and its write route have been removed. Historical
site and global audio rows remain for rollback/history but do not control current
recipes. No publisher snippet changes are needed. Rewarded and ad-only placements
retain their distinct existing lifecycle; external third-party tag URLs retain
provider-owned parameters.

The content ad interval defaults to **5 seconds** for new and existing websites.
The deployment migration resets an existing interval override to 5, records the
previous value in the audit log, and invalidates the settings cache. The normal
post-deploy Quick Monetize refresh republishes static recipes. The interval may
be set to 5–600 whole seconds; 0 retains midpoint-only scheduling.

The new interval schedule starts with content playback, rather than waiting for
the old halfway cue. Following empty responses, content resumes and the next
opportunity is earned after another interval of actual content playback. The
inline player must be visible or have already been seen and scrolled past with
its existing floating surface eligible. Content stays inline after no-fill;
requiring that empty inline box to remain visible would prevent the request
that supplies its next floating ad. There
is no fixed retry-count ceiling during the remaining content. After a confirmed
filled ad completes and IMA ends the whole pod, the next request is scheduled
immediately. Per-ad COMPLETE alone, skipped creatives, empty generic completion,
stale callbacks and VMAP-owned pods cannot create overlapping requests.

Pauses, buffering, hidden documents, offscreen players without an eligible
floating surface, seeking and dismissal do not accrue repeated opportunities.
Requests alone never authorize hidden ad playback: a filled response floats and
IMA starts only after the actual media box meets its measured viewability gate.
Timer-only or seek jumps cannot manufacture
watched time. Pending continuation is cancelled at teardown. The final content
second is left for the single postroll/EOS transition. Legacy published recipes
retain their earlier midpoint/60-second behavior until refreshed, and existing
VMAP schedules remain exclusively IMA-owned. Live fill depends on the ad server;
deterministic IMA test responses are not evidence of paid demand.
