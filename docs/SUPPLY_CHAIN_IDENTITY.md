# Supply-chain identity foundation

## Status and authority

This document defines the canonical Horus Media supply-chain identity model introduced by Task 2. Database records are the source of truth; generated files are deterministic projections and must never be edited as canonical input.

The implementation was checked against the authoritative specifications available on 2026-08-09:

- [ads.txt 1.1 final specification](https://iabtechlab.com/wp-content/uploads/2022/04/Ads.txt-1.1.pdf)
- [sellers.json 1.0 final specification](https://iabtechlab.com/wp-content/uploads/2019/07/Sellers.json_Final.pdf)
- [SupplyChain Object 1.0 final specification](https://github.com/InteractiveAdvertisingBureau/openrtb/blob/main/supplychainobject.md)

SupplyChain 1.1 was in public comment through 2026-08-21 when this foundation was implemented. It is not treated as the production authority until IAB Tech Lab publishes a final version. Horus therefore emits `ver: 1.0` and records that need a future 1.1 review remain an explicit follow-up.

## Canonical entities

| Entity | Canonical fields | Meaning |
| --- | --- | --- |
| `Publisher` | `legal_name` | Private contractual/legal entity name. It is never inferred from a website domain. |
| `Publisher` | `business_domain` | Normalized publisher business/owner domain used for the ads.txt `OWNERDOMAIN` directive and seller-domain consistency. Nullable until confirmed. |
| `Publisher` | `supply_chain_review_status`, `supply_chain_reviewed_at`, `supply_chain_reviewed_by` | Review state and provenance for the business identity. |
| `Site` | `publisher_id`, `primary_domain` | The content website and its public domain. This may legitimately differ from the publisher business domain. |
| central configuration | `supply-chain.manager_domain` | Horus advertising-system/manager domain used by `MANAGERDOMAIN` and the Horus schain node `asi`. Default: `horusmedia.net`. |
| `SellerDeclaration` | `publisher_id`, optional `site_id`, `seller_id`, `seller_type`, `name`, `domain`, `is_confidential` | A Horus sellers.json seller identity. A null `site_id` is publisher-wide; a populated `site_id` is limited to that website. `publisher_id` is the paid entity mapping. |
| `SellerDeclaration` | `status`, `review_status`, verification/reviewer timestamps | Publication lifecycle and independent review state. Only `ACTIVE` declarations are eligible for public artifacts. |
| `DemandAccount` + `DemandSite` | account scope, publisher/partner association, explicit site mapping, approval and enabled state | The authorized relationship between an external advertising system account and a Horus website. Cross-organization delivery is allowed only through this explicit mapping. |
| `DemandAdsTxtRecord` | `demand_account_id`, optional `site_id`, four normalized ads.txt fields | A null `site_id` is an account-level/global requirement applied to enabled, approved site mappings. A populated `site_id` is site-specific. |
| `DemandAdsTxtRecord` | `status`, `review_status`, verification/reviewer timestamps | Technical presence and independent human review state. Only active records with an eligible account/mapping are generated. |

`legal_name`, `business_domain`, `primary_domain`, manager domain, Horus seller ID, and an external demand seller account ID represent different concepts. Equality is allowed only when it is factually correct; it is never assumed by the model.

## Review states

Supply-chain identity uses a defined `SupplyChainReviewStatus`:

- `REVIEW_REQUIRED`: newly created, structurally changed, or legacy identity awaiting confirmation.
- `VERIFIED`: reviewed by an authorized Horus operator; reviewer and time are recorded.
- `REJECTED`: reviewed and rejected; the reason/history belongs in the audited workflow built by the compliance UI.

Review state is deliberately separate from publication `status`. During the legacy transition, `REVIEW_REQUIRED` does not switch off serving. A structural identity edit resets its review state and reviewer metadata.

## Domain normalization

`DomainNormalizer` is the shared comparison boundary. It:

- lowercases DNS names and removes a terminal dot;
- accepts a bare domain or an HTTP(S) URL containing only that domain;
- converts internationalized names to ASCII when the PHP `intl` extension is available;
- rejects credentials, ports, paths, queries, fragments, IP addresses, invalid labels, and invalid public-suffix syntax;
- compares normalized values rather than raw form input.

This is intentionally not a bundled Public Suffix List. Commercial review must still confirm that a configured value is the correct registrable business domain, particularly for multi-label public suffixes.

## Central invariants

`SupplyChainInvariantService` is the single policy boundary used by controllers, connector synchronization, static artifacts, and runtime configuration.

### Publisher and website identity

- A seller declaration assigned to a site must reference the site's publisher and organization.
- A declared publisher `business_domain` is the canonical `OWNERDOMAIN`, even when one publisher owns multiple sites with different content domains.
- A non-confidential seller domain must match its publisher's declared business domain. Confidential declarations are checked against the same internal entity mapping even though their public name/domain are omitted.
- A publisher business-domain edit resets supply-chain review to `REVIEW_REQUIRED`.

### Demand Ads.txt records

- Site-specific records require an explicit `DemandSite` mapping.
- Publisher-scoped demand accounts may map only to that publisher's sites.
- An eligible record requires an active record, enabled and approved account, enabled network, and enabled and approved site mapping.
- External system domain, seller account ID, relationship (`DIRECT` or `RESELLER`), and optional certification authority are normalized into one canonical line and hash.
- Exact global/site duplicates collapse to one line. The site-specific row wins only as deterministic provenance; its text is identical.
- The same advertising-system domain and seller account ID with conflicting relationship or authority fields is not guessed. The ambiguous lines are excluded and an `ADS_TXT_RELATIONSHIP_CONFLICT` finding is returned.

### Seller declarations and schain

- A Horus seller ID must map to one paid publisher/entity and one public identity.
- Equivalent per-site declarations for that same entity collapse to one sellers.json entry.
- A seller ID mapped to different entities, types, confidentiality states, names, or domains is excluded with `SELLER_ID_CONFLICT`.
- `PUBLISHER`, `INTERMEDIARY`, and `BOTH` are the only accepted seller types.
- Confidential sellers emit `seller_id`, `seller_type`, and `is_confidential: 1`; public name and domain are omitted. Their private internal identity is redacted from audit values.
- Disabled declarations are excluded.
- A site receives a Horus SupplyChain node only when it resolves to exactly one active seller ID that is also valid for Horus sellers.json. The emitted 1.0 node is `{asi: manager_domain, sid: seller_id, hp: 1}`.
- A `PUBLISHER` or `BOTH` identity tied to the site's publisher can produce `complete: 1`. An `INTERMEDIARY` identity emits the known Horus node with `complete: 0` and `SCHAIN_UPSTREAM_IDENTITY_REQUIRED`; the missing inventory-owner node is never invented.
- Missing or multiple eligible seller identities produce `complete: 0` and no invented node. Ad serving remains available; compliance findings drive remediation.

## Legacy transition and backfill

The migration does not copy a website domain into `Publisher.business_domain` and does not infer a legal identity.

For pre-existing data it performs only structural, lossless work:

1. Existing publisher identities receive `REVIEW_REQUIRED`; `business_domain` stays null.
2. Existing seller declarations with a site receive that site's existing `publisher_id`.
3. Existing global declarations receive the publisher already linked one-to-one to their organization, when present.
4. Existing seller and demand records receive `REVIEW_REQUIRED`; they remain active under their existing publication status.

`SupplyChainIdentityBackfill` is idempotent and can safely repair still-null structural seller links. It never derives a business domain or seller ID.

Until a publisher business domain is confirmed, artifact generation preserves the previous behavior by using the normalized site primary domain for `OWNERDOMAIN`. `ownerIdentity()` reports:

- `source: LEGACY_SITE_DOMAIN`;
- `reviewStatus: REVIEW_REQUIRED`;
- finding `OWNER_DOMAIN_REVIEW_REQUIRED`.

This keeps existing delivery operational while making the fallback queryable by the Admin control plane. Once `business_domain` is entered, the source becomes `PUBLISHER_BUSINESS_DOMAIN`; verification remains a separate Admin decision.

## Generated artifacts

`SupplyChainArtifactBuilder` consumes only canonical service projections:

- `supply/sites/{siteKey}/ads.txt`;
- `supply/domains/{siteDomain}/ads.txt`;
- `supply/sellers.json`.

Ads.txt directives are emitted first, followed by canonical records sorted by normalized line. sellers.json entries are sorted by seller ID. Duplicate equivalents are collapsed, conflicts are not guessed, JSON keys are canonicalized, and no generated timestamp is added to these artifacts. Repeated builds against unchanged database truth are byte-identical.

`SiteConfigurationBuilder` consumes the same service for `supplyChain.schain`; it does not independently query seller declarations. Only public protocol values are emitted—never internal IDs, credentials, review notes, or private confidential names/domains.

## Audit and organization boundaries

- Admin publisher business-domain changes remain covered by `publisher.created` / `publisher.updated`, including review-state reset.
- Seller creation/change/review uses `supply_chain.seller.updated` and `supply_chain.seller.reviewed`.
- Publisher identity review uses `supply_chain.publisher_identity.reviewed`.
- Existing demand account/mapping and Ads.txt synchronization audit events remain authoritative.
- Confidential seller names/domains are replaced with `[CONFIDENTIAL]` in audit values.
- Global public generation deliberately crosses organizations, but eligibility uses explicit publisher, account, and site relationships. User-facing queries must retain organization scope.

## Consumption by Tasks 3 and 4

Future Ads.txt and sellers.json screens must consume this model rather than create parallel tables:

- Admin Ads.txt tables use `adsTxtForSite()` lines and findings as canonical required state.
- Publisher Ads.txt pages use those exact lines and `ownerIdentity()`; they must not expose demand credentials or internal account metadata.
- Admin seller screens use `sellers()` plus declaration review/lifecycle fields, preserving conflicts for remediation rather than publishing a guessed winner.
- Publisher seller views filter declarations by their own `publisher_id` and reveal no additional confidential identity.
- schain previews use `schainForSite()` and show incomplete/conflict findings when a commercial seller ID is missing.
- Compliance status is computed from canonical findings and live verification evidence; it is not a manually editable badge.

## Configuration still requiring confirmation

- Each publisher's real business/owner domain.
- Each Horus seller ID and its paid entity, type, confidentiality choice, and site scope.
- Provider-issued external seller account IDs, relationships, and certification authority IDs.
- Whether `SUPPLY_CHAIN_MANAGER_DOMAIN`, contact email/address, and optional TAG ID match the production commercial entity.
- Any future final SupplyChain 1.1 requirements after IAB Tech Lab completes public comment and publishes the final specification.

These values must be supplied and reviewed by authorized business/compliance owners. The application deliberately does not invent them.
