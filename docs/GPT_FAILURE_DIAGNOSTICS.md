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
