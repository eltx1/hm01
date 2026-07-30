# Known Technical Risks

- Shared-host cron can be delayed by the provider; the heartbeat exposes this but cannot eliminate provider scheduling latency.
- A database queue is portable but has lower throughput than dedicated Redis workers; jobs must remain short and idempotent.
- Static configuration propagation is bounded by CDN TTL and purge timing. Emergency controls require republishing affected site configurations unless the global stable loader itself is replaced.
- GAM API behavior, permissions, quotas, and supported API versions remain external dependencies.
- Prebid and native bidder adapters execute in publisher browsers and remain subject to consent, privacy, ad-quality, and browser restrictions.
- HSTS preload is intentionally disabled by default because it is difficult to reverse.
- Shared hosting provides less process, filesystem, and network isolation than a dedicated VPS; the package remains portable to a VPS without changing the fixed business architecture.
