# Advertising startup speed — 2026-11-13

## Behavior

- Remove Horus-owned CMP polling, privacy timeout/veto, inferred GPT privacy overrides and explicit privacy-diagnostic POST from the loader. Provider APIs and configured Prebid consent modules remain untouched. No custom consent UI is introduced.
- Begin boot while the publisher HTML is still parsing. Existing mutation discovery and a DOM-ready scan discover late containers. Keep the single bounded initial technical recovery, exact source/origin/nonce checks, deadline, verified server PASS and Click Guard.
- On slow static configuration reads, prepare one revalidated alias after 750 ms. This cannot authorize before the manifest path succeeds or fails. Manifest and immutable failures fall through immediately instead of retrying the same resource. A fallback older than a known manifest is rejected; a fresh newer alias supports propagation. Global emergency controls still independently fail closed.
- Preload the exact approved delegated loader URL during verification. On handoff, the new runtime can adopt the exact current configuration object without a forced refetch if the transfer takes at most 5 seconds; slower transfers revalidate controls/config. Gate configuration binding is still checked; no persisted PASS or cross-document reuse.
- Static edge snapshots inline the gate runtime after its container with a CSP SHA-256 authorizing only its exact bytes. Keep the external gate JS for cached legacy HTML. No unsafe-inline permission for scripts. This eliminates one serial script fetch; it does not shorten or omit Turnstile or server validation.
- Placement priority, lazy loading and auction policies are unchanged (proposal 5 explicitly excluded).

## Validation

Local JS unit suite and controlled fake-clock tests; GitHub Actions runs PHP/static-delivery and Chromium/WebKit desktop and mobile-emulation browser suites. Controlled timing tests use mocked providers and are not measurements of real Cloudflare or publisher ad latency. The publisher URL returned 403 to the cloud browser; physical Android and real publisher rendering time are not established.

A deterministic slow-manifest test resolves the already-prepared alias at 5000 ms without the old second 5000 ms manifest retry. A browser experiment compares external vs inline gate runtime with a synthetic 1200 ms script delay while requiring one successful server verification in both cases.

## Rollback

Revert this change and rebuild/sync static assets. The original external gate asset remains available. CSP hash and document are produced together by the same snapshot, so no manual WAF or CSP relaxation is needed.
