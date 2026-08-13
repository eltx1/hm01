# THOTH AI — Publisher Quality Advisor

THOTH is an advisory subsystem inside **Publisher 360 → Quality Review**. Its output is evidence for a Horus Media administrator, never an operational decision. The provider layer can write only AI review-run records; it has no dependency on Publisher lifecycle, Site lifecycle, serving, GAM, Prebid, Direct JS, contracts, revenue share, payments, or provider mappings. Human decisions are stored separately in the append-oriented `publisher_quality_decisions` table.

## Provider architecture

`PublisherQualityReviewService` creates an explicit allowlisted evidence envelope, then resolves exactly one adapter through `AiProviderManager`:

- `OpenAiResponsesProvider` uses the official OpenAI Responses API, strict JSON Schema output, `store: false`, and no tools. The default model is `gpt-5-mini`.
- `GeminiStructuredOutputProvider` uses the official Gemini `generateContent` API with `application/json` and `responseJsonSchema`, and no tools. The default Gemini model is `gemini-2.5-flash`.
- `FakePublisherQualityAiProvider` exists only for deterministic automated tests and is neither registered in the production manager nor selectable in Admin.

Both adapters normalize into `PublisherQualityAiResult` and validate the same server-owned schema. Provider-specific response objects never reach controllers. There is no automatic provider failover. References: [OpenAI Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs), [OpenAI GPT-5 mini](https://developers.openai.com/api/docs/models/gpt-5-mini), and [Gemini structured output](https://ai.google.dev/gemini-api/docs/structured-output).

## Evidence and prompt-injection boundary

Only domains already associated with the Publisher and marked `VERIFIED` may be fetched. THOTH considers at most four same-domain candidate pages, validates DNS/IP destinations, pins the validated address where cURL supports it, blocks private/reserved/loopback destinations, disables redirects, accepts static HTML only, and bounds response and extracted-text size. It never executes JavaScript or embeds remote HTML in Admin.

Scripts, styles, forms, iframes, objects, and embeds are removed. Website text remains explicitly labeled untrusted evidence in the system policy. The model receives no tools, browsing, arbitrary URL fetch, internal API, shell, credential, serving, or finance access.

The evidence envelope allowlists Publisher identity, the versioned quality profile, selected non-secret Site facts, sanitized website text, and evidence gaps. It excludes payment/tax data, credentials, private contracts, support tickets, finance notes, and whole Eloquent objects.

## Configuration and operations

Open **Admin → Settings → THOTH Quality Advisor**:

1. Choose OpenAI or Gemini and a curated structured-output-capable model.
2. Add an Admin-managed API credential, or configure `THOTH_OPENAI_API_KEY` / `THOTH_GEMINI_API_KEY` on the server.
3. Run **Test real connection**. This sends only a small synthetic payload and verifies authentication, model availability, structured output, and schema parsing.
4. Select the tested provider as active and enable THOTH.
5. Open a test Publisher, complete the Quality Profile, and run THOTH.
6. Verify the advisory and record a separate human final decision.

THOTH defaults to disabled and OpenAI. Activation requires an available credential and a recent successful connection test. Model, provider, credential, and enabled-state changes affect future runs only; historical run metadata is immutable. A failed or timed-out provider call creates a safe failed run and never changes Publisher or serving state. Manual review always remains available.

Admin-managed credentials use Laravel authenticated encryption under `APP_KEY`, are hidden from model serialization, never rendered after save, and are redacted from audit payloads. The encrypted Admin credential takes precedence over an environment credential; environment secrets are never copied into the database. Credential replacement/removal, connection tests, settings changes, AI runs, and human decisions are audited with safe metadata.

External provider retention and processing depend on the configured provider, account, and API policy. Horus minimizes the submitted envelope and requests disabled response storage from OpenAI, but does not claim that an external provider never retains data.

Permissions are separated: `publisher_quality.review`, `publisher_quality.ai.run`, `thoth.settings.view`, `thoth.settings.manage`, and the higher-trust `thoth.credentials.manage`. Publisher-organization users have none of these permissions and cannot access the Horus-only routes.
