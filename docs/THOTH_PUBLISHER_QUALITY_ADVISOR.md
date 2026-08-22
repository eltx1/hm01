# THOTH AI — Publisher Quality Advisor

THOTH is an advisory subsystem used from **Publisher 360 → Quality Review** and, for a pending public application, directly from **Admin → Publisher Applications → Application Review**. Its output is evidence for a Horus Media administrator, never an operational decision. The provider layer can write only AI review-run records; it has no dependency on Publisher lifecycle, Site lifecycle, serving, GAM, Prebid, Direct JS, contracts, revenue share, payments, seller identity mutation, or provider mappings. Human decisions remain separate and authoritative.

## Provider architecture

`PublisherQualityReviewService` creates an explicit allowlisted evidence envelope, then resolves exactly one adapter through `AiProviderManager`:

- `OpenAiResponsesProvider` uses the official OpenAI Responses API, strict JSON Schema output, `store: false`, and no tools. The default model is `gpt-5-mini`.
- `GeminiStructuredOutputProvider` uses the official Gemini `generateContent` API with `application/json` and `responseJsonSchema`, and no tools. The default Gemini model is the lowest-cost stable allowlisted model, `gemini-2.5-flash-lite`.
- `FakePublisherQualityAiProvider` exists only for deterministic automated tests and is neither registered in the production manager nor selectable in Admin.

Both adapters normalize into `PublisherQualityAiResult` and validate the same server-owned schema. Provider-specific response objects never reach controllers. There is no automatic provider failover. The Gemini allowlist is ordered from the lowest-cost lightweight model upward. If Google reports the selected Gemini model as unavailable, the Gemini adapter reads the authenticated account's official model inventory, retries once with the cheapest available allowlisted `generateContent` model, and saves that working model after a successful connection test. It never switches providers or uses a model outside the server allowlist.

## Two explicit evidence contexts

The canonical evidence system supports two typed review contexts and does not infer context from nullable relationships:

### `OPERATIONAL_PUBLISHER`

An established Publisher may contribute website evidence only through its normal `Site -> VERIFIED SiteDomain` trust boundary. Existing Publisher 360 reviews continue to use this path.

### `PUBLISHER_APPLICATION`

A pending Publisher application intentionally has no production Site. Its website trust boundary is the Task 39 `PublisherApplicationDomainClaim` and the real Horus ads.txt authorization verification. THOTH may fetch the public website only when the current application domain has successfully verified both Task 39 authorizations.

The pre-approval flow is:

```text
ADS.TXT VERIFIED WEBSITE
        ↓
THOTH MAY COLLECT PUBLIC EVIDENCE
        ↓
THOTH ADVISORY
        ↓
HUMAN ADMIN DECISION
```

**THOTH website verification does not equal Publisher approval.** It also does not equal Site approval or production monetization.

## Application verification freshness

A Task 39 verification is not trusted forever. `THOTH_APPLICATION_DOMAIN_VERIFICATION_FRESH_DAYS` controls the freshness window and defaults to 7 days.

- If the current Task 39 verification is fresh, THOTH may use it.
- If it is stale, THOTH asks the same canonical `ApplicationAdsTxtVerificationService` to re-check ads.txt.
- The THOTH refresh path consumes only the already-reserved HMP/HMS identities. It does **not** call the identity reservation path and cannot create, replace, recycle, or mutate either seller ID.
- If either required HMP or HMS authorization is missing during the fresh check, website fetching becomes ineligible. The application is not automatically rejected; declarations and manual review remain available.

## Evidence and prompt-injection boundary

`PublisherEvidenceCollector` converges both contexts into `publisher-quality-evidence-v2`. Application snapshots explicitly identify the review context, application ID/status, verified domain, semantic `website_authorization_verified` fact, verification source/time/freshness, profile version, collected pages, and evidence gaps.

HMP/HMS are useful public technical identifiers in the Admin supply-chain UI, but the AI does not need them to assess content quality. They are therefore deliberately omitted from the AI evidence envelope.

For eligible websites THOTH considers a bounded canonical set: homepage, privacy/privacy-policy, about/about-us, and contact/contact-us. It stops trying a page-family once one valid variant succeeds, caps page count, bytes per page, extracted characters per page and total extracted text, and applies connect/request timeouts.

Safe redirects are bounded. A redirect destination must remain within the verified site's exact/www scope, and every hop is revalidated through `DomainSafetyValidator` before a request is made. Loopback, private, link-local, reserved/internal addresses and unsafe DNS results remain blocked. Arbitrary cross-domain redirects are not followed.

THOTH accepts static HTML only. It never executes JavaScript, iframes, forms, browser automation, or remote embedded scripts. Scripts, styles, noscript, forms, iframes, objects, embeds, hidden elements and `aria-hidden=true` content are removed before evidence reaches the provider.

Website text remains explicitly labeled untrusted evidence in both provider system policies. In a pre-approval review, the model is also told that applicant declarations are applicant-supplied rather than independently verified facts and that no production Site should be assumed active. The model receives no tools, browsing, arbitrary URL fetch, internal API, shell, credential, serving, finance, approval, rejection, or seller-ID write capability.

## Application Admin action and failure behavior

The explicit action is:

```text
POST /admin/publishers/applications/{application}/thoth-review
```

It is protected by authenticated active Admin controls, verified account, Admin 2FA, Horus-only middleware, `publisher_quality.ai.run`, and the sensitive-action throttle. Pre-approval runs are allowed only for `SUBMITTED`, `UNDER_REVIEW`, and `MORE_INFO_REQUIRED`. Draft, withdrawn and terminal states fail closed.

The application action reuses the latest canonical `PublisherQualityProfile` and the same `PublisherQualityReviewService`, provider adapters, output schema, immutable review-run table, evidence hash, dedupe behavior, and provider failure handling as operational Publisher reviews. No `ApplicationQualityProfile`, fake Site, second AI engine, or automatic paid AI execution is introduced.

Application Review separates four blocks: Domain / Authorization, Public Website Evidence, THOTH AI Advisory, and Human Decision. Missing pages and timeouts are recorded as evidence gaps. Zero acceptable pages produces a declarations-only advisory context rather than an automatic rejection.

Provider outages, schema failures, website failures, or failed stale ads.txt refreshes never change the Publisher, Organization, application, Site, serving, contracts, financial terms, HMP, or HMS. Manual Admin review remains available.

Audit events include `application.thoth_review.requested`, `application.thoth_evidence.collected`, and `application.thoth_evidence.unavailable`, in addition to the existing THOTH run events. Audit metadata remains bounded and excludes API keys, raw authorization headers, unnecessary HTML, and private applicant data.

## Configuration and operations

Open **Admin → Settings → THOTH Quality Advisor**:

1. Choose OpenAI or Gemini and a curated structured-output-capable model.
2. Add an Admin-managed API credential, or configure `THOTH_OPENAI_API_KEY` / `THOTH_GEMINI_API_KEY` on the server.
3. Run **Test real connection**. This sends only a small synthetic payload and verifies authentication, model availability, structured output, and schema parsing.
4. Select the tested provider as active and enable THOTH.
5. For an established Publisher, complete the Quality Profile and run THOTH in Publisher 360; or for a reviewable pending application, use **Run THOTH Website Review** directly on Application Review.
6. Verify the advisory and complete the separate human decision workflow.

THOTH defaults to disabled and OpenAI. Activation requires an available credential and a recent successful connection test. Model, provider, credential, and enabled-state changes affect future runs only; historical run metadata is immutable. A failed or timed-out provider call creates a safe failed run and never changes Publisher or serving state. Manual review always remains available.

Admin-managed credentials use Laravel authenticated encryption under `APP_KEY`, are hidden from model serialization, never rendered after save, and are redacted from audit payloads. The encrypted Admin credential takes precedence over an environment credential; environment secrets are never copied into the database. Credential replacement/removal, connection tests, settings changes, AI runs, and human decisions are audited with safe metadata.

External provider retention and processing depend on the configured provider, account, and API policy. Horus minimizes the submitted envelope and requests disabled response storage from OpenAI, but does not claim that an external provider never retains data.

Permissions are separated: `publisher_quality.review`, `publisher_quality.ai.run`, `thoth.settings.view`, `thoth.settings.manage`, and the higher-trust `thoth.credentials.manage`. Publisher-organization users have none of these permissions and cannot access the Horus-only application or Publisher review routes.
