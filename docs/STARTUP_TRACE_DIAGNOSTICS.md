# Local startup diagnostics and duplicate preparation

## Scope

The publisher reports very late Google requests after verification and repeated Traffic Gate frames. This change adds the requested small, timestamped console messages and fixes one reproduced startup defect. It does not claim the full five-minute field delay or low video fill is resolved.

The public Bluekl and Natega HTML captured in diagnostic run 36612904651 has duplicate permanent Horus Loader tags. The pre-DOM preparation Promise was local to each script evaluation, while its generation counter and verification runtime were shared. Re-evaluating the Loader could therefore start two control/config reads and invalidate the first preparation. The preparation is now owned by the existing shared Loader state. No publisher embed edit is required. A regression test fails with the old local ownership (two config reads instead of one) and passes with the shared ownership. Duplicate tags alone do not prove the cause of every repeated challenge or the entire field delay.

## Console contract

Messages use `[HM] +<milliseconds>ms <phase>`. Time is measured from the current document's performance clock. All messages are local to the browser, capped at 160 distinct event/detail combinations, and have no network or persistent-storage side effects. Only allowlisted phases, small numeric slot/attempt/error codes and allowlisted stage names are retained. No tokens, nonces, URLs, account IDs, consent strings or raw provider errors are logged. Anyone with browser developer tools can read the messages; these are diagnostic codes, not secret/admin-only information.

Important phases:

- `Horus init`, `CFG start`, `CFG ready`: Loader and independent control/config preparation.
- `CFG wait`: a prerequisite is still unresolved after five seconds. This is an observation, not a new timeout, retry or authorization.
- `CF start`, `CF ready`: a verification attempt starts and its isolated frame replies.
- `CF token`: Turnstile produced a token. This is NOT a verified PASS.
- `CF verify`: the isolated frame is attempting server-side verification.
- `CF pass`: the parent accepted the existing origin/source/protocol/nonce-bound server-verified message.
- `CF reject`, `CF error`, `CF timeout`: rejection, technical failure or the existing deadline respectively.
- `Privacy ready` / `Privacy reject`: the existing privacy decision.
- `Core handoff`, `Core ready`, `Core error`: production release delegation.
- `Horus start`: eligible ad scanning starts after the existing decisions allow it. It is not an ad impression.
- `GPT load`, `GPT fetched`, `GPT ready`: SDK injection, script-load completion and usable GPT queue execution. These are distinct.
- `GPT call`: the Direct adapter calls `display`; `GPT request`: Google emits `slotRequested`.
- `GPT response`, `GPT render`, `GPT empty`, `GPT onload`: response received, creative markup injected, empty response or creative iframe load. None alone proves viewability or paid revenue.
- `VAST call`, `VAST no-fill`, `VAST error`: video request or recorded failure, with numeric code and break position where available.
- `Video start`, `Video content`: SDK ad-start event versus accompanying content playback.

The snapshot is available with:

```javascript
console.table(window.HorusMediaLoader.getStartupTrace().events);
```

To copy only the sanitized local snapshot from Chrome DevTools:

```javascript
copy(JSON.stringify(window.HorusMediaLoader.getStartupTrace(), null, 2));
```

The trace records initial occurrences per phase/slot/detail and is not a raw auction log or complete refresh history. Console failures must never affect authorization or rendering. Diagnostic progress messages cannot grant permission, reset a deadline or settle a gate.

## How to identify the remaining field wait

Record a single ordinary page visit with Console open, filter `[HM]`, and keep the page in the foreground. Avoid clicking paid ads or repeated refresh loops. Capture the sequence when the delay occurs, plus Network timing if needed. Distinguish a delay before `CF token`, token-to-verified-PASS, PASS-to-`Horus start`, SDK readiness, request-to-response and creative loading. A pending `blob:` entry or an HTTP200 alone does not establish the verification decision. A PAT HTTP401 can be expected in Cloudflare's challenge process.

## Verified tests before PR CI

Checksum-bound isolated run 36624493482 succeeded: 368 Node tests; 502 Chromium/WebKit desktop/mobile-viewport cases; two pre-existing browser skips. Artifact 11059970808 ZIP SHA256: `1d60c4c7c2536d4a97368f0a208c71af25ee6485c5550a7153601a4fbfdbd2d2`. Patch SHA256: `41da4116fe42553512608be432e70cc1f156541cbdbd63ae2b63a4922417e95d`.

Tests cover spoofed progress, token versus verified PASS, denied/slow verification, independent display requests with video held, trace privacy/copying/bounds, a throwing console and duplicate pre-DOM evaluation. Provider/verification boundaries in browser tests are deterministic; no paid ad endpoints are used. PR/main CI and exact deployment verification are separate requirements.

## Unchanged boundaries

No WAF rule, Turnstile deadline, consent requirement, Click Guard policy/state, ad-demand setting, VAST tag, pricing floor or content URL is changed. No retry loop, fail-open ad authorization or backend impression telemetry is added. PR214 remains separate. VAST303 still requires a source/demand diagnosis; this change makes its timing visible without fabricating fill.

## Primary references

- https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- https://developers.cloudflare.com/cloudflare-challenges/troubleshooting/challenge-solve-issues/
- https://developers.google.com/publisher-tag/reference
