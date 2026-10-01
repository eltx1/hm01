# Video delivery contract

## Admin input and sizes

Quick Monetize's **GAM ad unit path** mode accepts a validated path such as
`/123456/video` or a supported parent/child network path with nested ad units.
For Video placements the server builds and saves a linear GAM VAST template.
The existing complete-tag mode continues accepting third-party VAST URLs.
Generated tags do not disable fallback, impose a maximum ad duration, or assert
user privacy/consent values. Previously saved publisher constraints are retained.

The selectable video masters are 300×250, 320×180, 336×280, 400×225, 400×300 and
640×480. A master defines the media aspect ratio and maximum floating size,
not six simultaneous ad slots. Inline media fills the publisher container up to
960px (or an existing custom master wider than 960px); narrow columns remain
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

Ad failures release owned content or close the ad-only/rewarded attempt. There
are no automatic auction retries. VMAP content-resume without preroll retires the
initial startup watchdog and retains the SDK-owned future schedule. Existing
consent, viewability, Click Guard and reward-completion requirements remain in
force. Test fixtures use a simulated IMA boundary and never contact paid demand.
