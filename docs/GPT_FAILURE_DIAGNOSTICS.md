# GPT failure diagnostics

The GPT adapter records the branch that produced `failed` before cleanup. The
Loader keeps the original `gpt-failed` result and preserves the diagnostic on the
surviving placement. This applies to all sites using the adapter, not one publisher.

## Reading a failure

On the candidate, inspect `data-hm-gpt-failure-stage` and
`data-hm-gpt-failure-reason`. After candidate removal, inspect the placement's
`data-hm-gpt-last-failure-stage` and `data-hm-gpt-last-failure-reason`.
These are historical labels: a later fallback may succeed, so use the existing
placement status to determine the current result.

The local startup trace also contains a `GPT error` event, for example:

```text
[HM] +4272ms GPT error slot=2 stage=display reason=exception
```

`HorusMediaLoader.getStartupTrace()` returns the same bounded, sanitized events.
The slot number is local to the current page, not a GAM ad-unit ID. The trace
retains at most 160 events; placement attributes remain available if that limit
is reached. Reloading or leaving the page discards these diagnostics. There is
no server-side collection, persistent browser storage, or new network request.

| Stage | Reason | Existing failure branch |
| --- | --- | --- |
| `library` | `load-error` / `unavailable` | GPT script error / library could not be inserted |
| `queue` | `invalid-queue` | Display command queue has no callable push |
| `api` | `unavailable` | Required display API is missing |
| `define` | `null-slot` / `exception` | Slot creation returned null / threw |
| `service` | `unavailable` / `exception` | Pubads or slot service is missing / service operation threw |
| `listener` | `unavailable` / `exception` | Required listener API is missing / registration threw |
| `enable` | `exception` | Enabling services threw |
| `display` | `exception` | Displaying the slot threw |
| `render` | `host-unavailable` | Existing fit check rejected the surface at render time |
| `render` | `response-rejected` | Non-empty response failed existing size/fluid validation |
| `rewarded-ready` | `ready-timeout` / `callback-missing` | Existing 25-second ready timeout / missing visibility callback |
| `rewarded-show` | `show-declined` / `exception` | Visibility callback returned false / threw |
| `rewarded-refresh` | `exception` | Checking initial-load configuration or the existing refresh threw |

These labels identify the failing branch, not necessarily its underlying cause.
For example, `library/load-error` alone cannot distinguish a network failure
from client blocking. Provider exception messages, stack traces, URLs, tokens,
ad-unit paths and response payloads are never retained by this feature.

## Explaining `render / response-rejected`

This branch additionally records a bounded context **after** the existing
acceptance policy rejects the response. It does not change requested sizes,
fluid eligibility, accepted responses or cleanup. All publishers using the
adapter receive the same diagnostics; no hostname or publisher-specific branch
is used.

| Candidate suffix (`data-hm-gpt-failure-…`) | Placement suffix (`data-hm-gpt-last-failure-…`) | Meaning |
| --- | --- | --- |
| `detail` | `detail` | Specific validation defect, or an explicit limit on inspection, listed below |
| `response` | `response` | Bounded description of the returned size and its value types |
| `requested` | `requested` | Actual eligible sizes supplied when this slot was defined, e.g. `300x250,fluid` |
| `policy` | `policy` | Existing `flexible` or `fixed` validation mode |

| Detail | Rejected condition |
| --- | --- |
| `size-missing` | Size is null or undefined and the existing fluid fallback did not accept it |
| `fluid-not-requested` | A named fluid response arrived without fluid in this slot's requested sizes |
| `size-type` | Size is neither an array nor a recognized fluid value |
| `size-shape` | The fixed-size array does not contain exactly two elements |
| `dimension-type` | Flexible validation requires numeric dimensions |
| `dimension-uninspectable` | Fixed-policy coercion involved a non-primitive value; diagnostics will not invoke provider conversion again or claim a more specific cause |
| `dimension-non-finite` | Width or height is NaN/infinite after existing fixed-policy coercion, where applicable |
| `dimension-non-integer` | Width or height is fractional |
| `dimension-non-positive` | Width or height is zero or negative |
| `dimension-out-of-range` | Width or height exceeds the existing 10000 limit |
| `outside-size-policy` | Valid positive integer dimensions failed the existing fixed-size tolerance policy |

Example (synthetic; does not assert the cause of any historical live failure):

```text
[HM] +5257ms GPT error slot=1 stage=render reason=response-rejected detail=dimension-non-positive response=array(0,0) policy=flexible requested=300x250,fluid
```

The corresponding trace fields are `failureDetail`, `responseSize`,
`requestedSizes` and `sizePolicy`. Finite numeric dimensions within ±10000 are
preserved, including zero and fractions. Other dimensions retain only a type or
`non-finite`/`out-of-range` label. Arbitrary strings and objects are never copied
or converted to text; `array(string,string)` reports types, not string contents.
`array-length:21` means 21 or more elements. Requested sizes contain at most 20
validated pairs/named sizes. Both the Loader's DOM boundary and the trace
revalidate these fields. A later labelled failure clears earlier context so it
cannot appear to explain the new failure. Context remains local and is discarded
on reload, just like the existing diagnostics.

Use the captured condition to construct an offline regression before changing
acceptance behavior. A failure label proves a validation decision, not that the
creative was valid or that Google, the publisher, or Horus caused the bad value.

`gpt-empty`, `ineligible`, invalid recipes, normal reward dismissal and prompt
expiration remain separate outcomes. Successful display, sizes, retries,
timeouts, rendering policy, Turnstile, Click Guard and report filters are
unchanged. Diagnostics are best effort: logging or attribute failures must not
block the original failure handling or cleanup.

## Validation

- `npm run test:browser`: adapter fault injection, loader cleanup, diagnostic
  allowlists, logging failures, no-fill, serving gate and existing rendering tests.
- `npx playwright test --config=playwright.traffic-gate.config.js`: real composed
  and minified Loader tests, Chromium/WebKit desktop and mobile, real traffic-gate
  and Click Guard code with offline provider fixtures; includes independent video
  continuing after GPT failure and rewarded cleanup without granting a reward.

All provider fault injection occurs in offline fixtures. Do not intentionally
break GPT or generate repeated paid requests on a publisher's live page.
