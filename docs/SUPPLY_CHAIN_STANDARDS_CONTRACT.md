# IAB Supply-Chain Standards Contract

Task 32 establishes the canonical identity and publication contract used by the existing Horus Media ads.txt, sellers.json, and OpenRTB SupplyChain implementation. It is a hardening layer inside the existing `App\Services\SupplyChain` subsystem; it is not a second supply-chain architecture.

## Official baseline reviewed

Reviewed on 2026-08-15 against primary sources only:

- IAB Tech Lab ads.txt / app-ads.txt 1.1 final specification (August 2022).
- IAB Tech Lab sellers.json 1.0 final specification.
- IAB Tech Lab OpenRTB SupplyChain Object 1.0 final specification and OpenRTB 2.6 SupplyChain definitions.
- IAB Tech Lab OpenRTB 2.6 Publisher object semantics.
- Prebid.js current Supply Chain Object Module and ORTB2 first-party-data guidance.

Do not advance the version strings because a draft or repository branch exists. Public Horus artifacts stay on sellers.json `1.0` and schain `1.0` until a later task deliberately adopts a newer final specification.

## Canonical identity hierarchy

These identities are intentionally different:

| Concept | Meaning | May be a Horus seller ID? |
| --- | --- | --- |
| Laravel User | Login / actor identity | No |
| Publisher | Selling legal entity Horus pays for inventory | Owns the stable Horus seller identity |
| Site | Website / inventory property | No |
| Site `public_key` | Public loader/config lookup key | No |
| Placement | Ad placement inside a Site | No |
| Horus seller ID | Stable seller/account identity for the paid Publisher legal entity inside the Horus advertising system | Yes |
| Bidder seller/account ID | Account identifier inside a particular SSP/exchange/bidder | Only for that advertising system |
| ads.txt relationship | Explicit `DIRECT` or `RESELLER` relationship for one advertising-system account | Not an identity |
| sellers.json seller | Public declaration of the legal entity paid via a seller ID | Uses the Horus seller ID in Horus sellers.json |
| SupplyChain `asi` / `sid` | Advertising-system domain plus the seller/account ID inside that system | `sid` uses the seller ID for that `asi` |

Default hierarchy:

```text
Publisher legal entity
    -> one stable Horus seller ID
        -> Site A
        -> Site B
        -> Site C
```

The existence of multiple Site rows is never a reason to mint multiple seller IDs. The current contract publishes a site-scoped SellerDeclaration only when the same active seller ID also exists as the Publisher-level legal-entity identity. A future site-specific transaction identity requires an explicit transaction model and a separate reviewed change; `site_id` alone is not sufficient evidence.

## ads.txt record identity

The canonical record identity is the tuple:

```text
advertising_system_domain,
seller/account ID inside that advertising system,
DIRECT or RESELLER,
optional certification-authority ID
```

`DIRECT` means the Publisher/content owner directly controls the account identified by field 2 in the advertising system identified by field 1. `RESELLER` means a different entity controls that account and is authorized to resell the Publisher's inventory.

Horus never derives this relationship from bidder name, `seller_type`, account-ID presence, serving mode, Prebid enablement, Direct JS enablement, or the fact that Horus provides technology. `SellerDeclaration.ads_txt_relationship` is nullable by design: null means no Horus seller authorization line is emitted. Existing `DemandAdsTxtRecord.relationship` remains the explicit relationship source for bidder/demand records.

The standards contract strips the Task-31 legacy candidate line that inferred `DIRECT` from seller type, then adds a Horus seller line only when the explicit relationship is `DIRECT` or `RESELLER`. Conflicting explicit records fail closed.

## OWNERDOMAIN

`OWNERDOMAIN` comes only from the Publisher business-domain identity and is normalized to PSL+1. It is not derived from the Site hostname, User, loader key, Placement, or serving mode.

When Horus publishes a non-confidential seller for that Publisher, the seller `domain` and Publisher business domain are validated as the same legal-entity domain. This keeps OWNERDOMAIN aligned with the originating Publisher seller identity.

If no Publisher business domain exists, Horus does not invent an OWNERDOMAIN from a Site row.

## MANAGERDOMAIN

`MANAGERDOMAIN` is not the Horus advertising-system domain and is not a synonym for “Horus technology is installed.”

It is emitted only when `site_serving_settings` explicitly stores:

- `monetization_manager_domain`; and
- `monetization_manager_relationship` = `PRIMARY` or `EXCLUSIVE`.

An optional `monetization_manager_country` may scope that declaration. Half-configured or malformed manager declarations fail closed. Serving mode, GAM usage, Prebid, Direct JS, or loader installation never imply manager status.

The existing `supply-chain.manager_domain` configuration key remains the Horus advertising-system domain used as schain `asi` and as field 1 when a Horus seller account is explicitly authorized in ads.txt. Its historic key name must not be interpreted as authorization to emit the ads.txt `MANAGERDOMAIN` directive.

## sellers.json contract

Horus emits only fields it owns and has reviewed. Optional fields are supported by validation without being emitted merely because the final specification defines them.

Top-level supported fields:

- `version`
- `sellers`
- `identifiers`
- `contact_email`
- `contact_address`
- `ext`

Seller supported fields:

- `seller_id`
- `seller_type`
- `is_confidential`
- `is_passthrough`
- `name`
- `domain`
- `comment`
- `ext`

Seller IDs remain deterministic and unique. A seller ID maps to one paid legal entity. Confidential Horus output omits public name/domain. Public extension objects are bounded and reject secret-like key names; the artifact builder does not forward arbitrary extension data from private model metadata.

## SupplyChain contract

Generated Horus nodes remain minimal:

```json
{"asi":"horusmedia.net","sid":"seller-123","hp":1}
```

The validator accepts current final optional fields `rid`, `name`, `domain`, and `ext`, plus root `ext`, while requiring `ver=1.0`, `complete` 0/1, non-empty nodes, canonical `asi`, seller/account `sid` no longer than 64 characters, and `hp=1` for SupplyChain 1.0.

Runtime schain `sid` comes from the selected canonical Horus SellerDeclaration. It never comes from `users.id`, `sites.id`, `placements.id`, or `sites.public_key`.

## Prebid ORTB2 publisher metadata

OpenRTB 2.6 defines `site.publisher.id` as an exchange-specific seller ID corresponding to one seller in that exchange's sellers.json. Horus therefore does not populate generic `ortb2.site.publisher.id` with `Site.public_key`.

Global first-party data contains the Site domain plus Publisher name/business domain when available. Bidder adapter account parameters continue to use each `BidderAccount.publisher_id` through the adapter's configured `publisher_parameter`; those bidder-specific parameters are not changed by this task.

Prebid's current SChain guidance is compatible with the Horus minimal `ver/complete/nodes` object and, in Prebid 10, can be delivered as ORTB2 first-party data under `source.ext.schain`. Task 32 does not redesign loader delivery.

## Examples

### One Publisher / one Site

```text
Publisher: Nile News LLC
Publisher business domain: nilenews.com
Horus seller ID: hm-pub-1001
Site: news.nilenews.com
```

`hm-pub-1001` is the seller ID. The Site ID, Site public key, and User IDs are unrelated implementation identities.

### One Publisher / multiple Sites

```text
Nile News LLC -> hm-pub-1001
  -> news.nilenews.com
  -> sports.nilenews.com
  -> finance.nilenews.com
```

All three sites normally use `hm-pub-1001` for the Horus seller node.

### Publisher-owned bidder account

If Nile News directly controls account `pub-7788` at `ssp.example`, the explicit record is:

```text
ssp.example, pub-7788, DIRECT
```

The value is configured as DIRECT because of account control, not because the Horus seller type is PUBLISHER.

### Horus/reseller-owned bidder account

If the Publisher inventory is sold through account `horus-42` at `ssp.example` and that account is controlled by the reseller rather than the Publisher:

```text
ssp.example, horus-42, RESELLER
```

Again the relationship comes from the account-control contract.

### Horus as exclusive monetization manager

With explicit Site settings:

```text
monetization_manager_domain = horusmedia.net
monetization_manager_relationship = EXCLUSIVE
```

Horus may publish:

```text
MANAGERDOMAIN=horusmedia.net
```

A country-scoped explicit relationship may publish `MANAGERDOMAIN=horusmedia.net, EG`.

### Horus as technology provider, not exclusive manager

The Horus loader, Prebid and/or Direct JS may all be enabled while no primary/exclusive manager relationship exists. In that case:

```text
MANAGERDOMAIN is absent
```

No serving-mode heuristic may change that result.

## Operational rules for later tasks

1. Do not overload User, Site, Placement, or loader keys as seller/account identities.
2. Prefer one Publisher-level SellerDeclaration per paid legal entity.
3. Require explicit `ads_txt_relationship` before publishing a Horus ads.txt authorization line.
4. Require explicit manager relationship + domain before MANAGERDOMAIN.
5. Never expose model metadata or unknown extension payloads directly in public artifacts.
6. Keep bidder-specific account IDs in bidder adapter parameters unless a universally correct OpenRTB Publisher ID is deliberately established.
7. Preserve deterministic ordering and fail closed on identity conflicts.
