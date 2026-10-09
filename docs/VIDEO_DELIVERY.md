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
and selected transient codes 301/1012. A narrowly qualified initial audible
autoplay denial (IMA 1205) can instead consume that same retry with muted
playback. Mixed error causes cannot produce a third request. Known VMAP,
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

## Additional mid-roll opportunities

The global Video Player setting `video_player.mid_roll_interval_seconds` is
labeled **Additional mid-roll interval (seconds)**. It defaults to 60; 0 disables
additional mid-rolls, and enabled values must be whole seconds from 30 through
600. Saving uses the existing permission, audit and active-site publication
path. Recipes publish `data-hm-video-mid-roll-interval-seconds`; the original
`data-hm-video-mid-roll-ratio=0.5` remains for the initial midpoint.

Additional breaks are considered only after that first midpoint opportunity,
after the configured interval of genuine eligible content playback. A wall-clock
timer alone cannot create an auction. Hidden documents, offscreen players below
50% visibility, pauses, stalls, seeking, live ad pods and non-linear overlays do
not accrue eligible playback. VMAP remains exclusively SDK-scheduled. A repeat
also requires at least 15 seconds of real content remaining; seeking, skipped
opportunities and resume events cannot cause catch-up or back-to-back requests.
The current approximately 49.4-second content clip therefore retains its
midpoint and postroll with no additional break. There is no artificial replay,
extra content-completion signaling or guarantee of fill.

## Explicit inventory and autoplay preferences

Each website's admin overview and Inventory page expose **Video ad type / نوع إعلان الفيديو**:
**Accompanying content (2)** (GAM `plcmt=2`) or **Instream (1)** (`plcmt=1`).
The nullable `site_configs.video_inventory_type` field defaults to accompanying
for existing and future websites. No global inventory value is inherited: the
retired `video_player.inventory_type` registry entry, UI control, and config
fallback are removed. Any historical stored global row is retained for history
but ignored, including cached values. Content URL, autoplay audio and the
additional mid-roll interval remain global.

Only Horus admins with `video_player.manage` can save the dedicated field. The
change is audited, and only the selected active website gets a new static
production configuration. Inactive websites save the value for activation;
repeating an unchanged save does not queue another version. Selecting a type
does not activate a website or placement. Normal runtime refresh during
deployment regenerates existing static recipes with the site's effective value.
The recipe emits that value as `data-hm-video-content-mode`; the runtime maps it
to the fixed `plcmt` without varying by visitor, browser capability, mute button,
or ad response.
Use instream only where video content is the focus of the visit or explicitly
requested by the viewer. Merely adding a video to an editorial page does not
establish that classification. Ad-only and rewarded inventory are unchanged.

The independent `video_player.autoplay_audio` preference defaults to `muted`.
`prefer_audible` attempts real sound-on content playback when viewable and waits
for the browser's play promise before requesting an ad. A browser policy denial
tries muted playback once. If both fail or a media check stalls, the player shows
Play and makes no new auction until playback succeeds. The resulting state sets
the GAM and IMA audio/playback signals together. Dismissal retires pending checks;
it cannot resurrect the player or produce a late ad request. This is browser
capability handling, not a bypass or a guarantee that sound will autoplay.
An audible capability check never starts offscreen. If SDK readiness arrives
after the inline slot has scrolled away, this opt-in mode waits for the slot to
be visible again before probing or requesting; the existing muted mode keeps
its late-response floating behavior. Viewer mute and volume changes while
waiting are preserved. Successful playback of the current silent content clip
does not prove that the browser will allow an audible ad. Only the narrowly
qualified IMA 1205 denial described above enables the one muted fallback; the
runtime never treats arbitrary VAST errors as autoplay denials or restarts a
failed manager in place.

As verified on October 9, 2026, Google's accompanying-content definition still
requires muted-by-default playback. Google's October 8 notification removes two
layout requirements effective October 22, not that audio default. Choosing
sound-on accompanying content may therefore restrict demand rather than improve
fill. The publisher must review the actual viewing experience before selecting
instream. Neither setting is changed automatically by a deployment or a date.

References: [Google video inventory restrictions](https://support.google.com/publisherpolicies/answer/15208072?hl=en-GB),
[scope and consequences of inventory restrictions](https://support.google.com/publisherpolicies/answer/10437795?hl=en),
[IMA autoplay capability checks](https://developers.google.com/interactive-media-ads/docs/sdks/html5/client-side/autoplay),
and [GAM playback and placement signals](https://support.google.com/admanager/answer/10678356?hl=en).
