# Prebid Architecture

## Browser ownership

Prebid.js executes in the visitor's browser. The Horus loader retrieves a
versioned configuration from the CDN, loads a pinned Prebid bundle when enabled,
runs a bounded auction, applies targeting to GPT, and allows GPT to request the
selected GAM network.

Laravel is not in this request path.

## Intended sequence

1. Publisher page loads one permanent Horus loader using a stable site key.
2. Loader resolves a cacheable configuration snapshot from the Horus CDN.
3. Consent state and placement eligibility are evaluated in the browser.
4. Loader initializes the pinned Prebid.js bundle and configured bidders.
5. Auction closes at a strict timeout.
6. Prebid targeting is passed to GPT.
7. GPT requests the selected GAM network, defaulting to `HORUS_GAM`.
8. GAM selects among eligible demand sources and returns the creative.

## Reliability

The future loader must be asynchronous, small, versioned, cacheable, and
fail-safe. It must prevent duplicate initialization, cap auction latency, isolate
placement failures, provide a GPT fallback when bidders fail, and respect
`PAUSED`. Configuration publication must allow rollback to a prior immutable
version.

## Privacy and security

Only public serving configuration belongs in CDN artifacts. Credentials and
internal identifiers must never be published. Consent integration, regional
privacy behavior, bidder user-sync policy, and data minimization require
dedicated implementation and review.

## Not in this release

No loader, CDN publishing, Prebid bundle, bidder adapter, consent module, GPT
slot mapping, or advertising tag is implemented yet.
