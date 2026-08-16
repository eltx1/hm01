# Dual Horus Seller Identity and Application Ads.txt Verification

Task 39 extends the Task 33 Horus-managed Publisher identity without replacing it.

## Identity hierarchy

Each Horus-managed Publisher has one permanent Publisher seller/account ID:

- `HMP-<ULID>` — `identity_scope=PUBLISHER`

Each Horus-managed website/property has one permanent Website seller/account ID:

- `HMS-<ULID>` — `identity_scope=WEBSITE`

Both IDs are `HORUS_MANAGED` seller/account identifiers in the same Horus advertising system and both map to the same paid Publisher/legal seller entity. They are public technical identifiers, not credentials and not verification tokens.

An HMP or HMS identifier is immutable, globally unique, never recycled, and never reassigned to another Publisher. A reserved HMS is bound to the application domain claim before a normal `Site` exists, then attached once to the matching canonical Site after approval/onboarding.

## Public application verification

Application website verification is ads.txt-only. Horus reserves HMP and HMS and instructs the applicant to publish the real authorizations at the root ads.txt URL:

```text
horusmedia.net, HMP-<ULID>, DIRECT
horusmedia.net, HMS-<ULID>, DIRECT
```

The advertising-system domain comes from the canonical supply-chain configuration. Verification uses the canonical `AdsTxtParser` and the bounded SSRF-safe ads.txt fetcher. Both exact seller IDs must be present with `DIRECT`. Standards-valid whitespace and comments do not change the semantic comparison.

The application fetcher follows ads.txt 1.1 redirect behavior: redirects inside the original root domain are bounded and allowed; one outside-root delegation is allowed; a delegated target may not redirect again. HTTPS downgrade, private/reserved network resolution, excessive response size, invalid content type, and invalid redirect chains fail closed.

Verification evidence stores bounded metadata and a SHA-256 evidence hash, not an indefinite copy of the remote ads.txt body.

Website verification never activates the Organization, Publisher, Site, placements, serving engines, Finance, or payouts. It is supply-chain/website evidence only.

## Pre-approval sellers.json

Reserved public-application HMP/HMS identities can be represented as confidential sellers.json Seller objects while the application remains live:

- `seller_id`: HMP or HMS
- `seller_type`: `PUBLISHER`
- `is_confidential`: `1`
- public legal name/domain omitted

Applicant email, private business details, staff notes, THOTH findings, and application status are never emitted. Rejected/withdrawn claims are released from active publication while their immutable seller/evidence records remain stored and disabled.

After approval, the existing seller-review lifecycle determines confidentiality, review state, and activation. Approval reuses the same HMP/HMS; it does not issue replacements.

## Site handoff and additional websites

When an approved Publisher creates a Site whose normalized domain matches a verified application claim, Horus attaches the already-reserved HMS to that Site exactly once. This is idempotent and same-Publisher constrained.

Additional websites for a Publisher that already has an HMP receive new HMS identities. They do not receive new HMP identities.

Legacy Publishers that do not yet participate in the Horus-managed HMP lifecycle keep their existing Site-creation behavior; Site creation does not silently invent a new HMP for those legacy records.

## Canonical ads.txt and sellers.json

For an eligible reviewed Site with active dual identities, canonical ads.txt contains both Horus authorizations:

```text
horusmedia.net, HMP-..., DIRECT
horusmedia.net, HMS-..., DIRECT
```

These coexist with reviewed Platform Master, Prebid Bidder, Direct Demand, and other canonical sources. Exact duplicate copies collapse through the canonical composer; conflicting relationships for the same seller ID fail closed.

HMP and HMS are intentionally distinct seller IDs and therefore are not duplicates. Both sellers.json objects map to the same Publisher legal identity.

## SupplyChain Object / OpenRTB / Prebid

HMP and HMS are two account identities for one paid legal seller, not two payment-chain entities. They MUST NOT produce two sequential Horus SupplyChain nodes.

For a Task 39 website transaction, the Website seller identity is transaction-facing:

```json
{
  "asi": "horusmedia.net",
  "sid": "HMS-...",
  "hp": 1
}
```

The canonical chain remains:

`SiteConfigurationBuilder -> supplyChain -> Horus Loader -> Prebid ORTB2/SChain`

No second SChain mechanism is introduced, and HMP/HMS are not copied into bidder-specific `publisher.id` parameters. Provider account identifiers remain provider-specific.

## Review and safety invariants

- one Publisher HMP per Horus-managed Publisher;
- one HMS per application-domain claim / canonical Site;
- HMP/HMS namespaces are reserved to `HORUS_MANAGED` identities;
- HMP/HMS cannot be deleted, recycled, or reassigned;
- HMS activation requires its canonical Site and an active/reviewed HMP;
- Publisher legal/commercial changes reopen review for both HMP and all HMS identities;
- Publisher suspension disables both identity levels;
- Task 37 supply-chain publication observers continue to publish deterministic global artifact updates;
- application authentication, tenant isolation, audit, SSRF safety, rate limiting, and human Admin decision boundaries remain in force.

## Task 40 handoff

`ApplicationAdsTxtVerificationService` is the canonical application-domain verification contract. Task 40 crawling is eligible only when the current application domain claim has `verification_status=VERIFIED`, established through the two real Horus ads.txt authorizations.
