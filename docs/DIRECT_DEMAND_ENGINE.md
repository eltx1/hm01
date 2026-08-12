# Direct Demand Engine

This document is the serving contract for Horus Media demand that renders directly in the publisher browser without requiring Google Ad Manager. It generalizes the existing Native/Alternative Demand implementation; it does not replace or delete that domain.

## Compatibility

The normalized persistence model remains:

`DemandNetwork -> DemandAccount -> DemandSite -> DemandPlacement -> DemandWidget`

Existing approvals, reports, ads.txt records, remote mappings, history, account scopes, and GAM-managed demand are preserved.

During rollout the existing database master `native_demand_enabled`, operational alias `NATIVE_DEMAND`, and static `nativeDemand` payload remain supported. Static schema v4 adds the broader aliases `directDemandEnabled` and `directDemand`. Both aliases describe the same normalized candidate graph; publisher code does not change.

`DIRECT_JS` remains the engine-level operational control key. `NATIVE_DEMAND` remains a legacy broad compatibility control and may still suppress the historical demand surface.

## Renderer ownership

Direct Demand is independent from GAM and Prebid. Different physical placements may run simultaneously, for example:

- slot A -> standalone Prebid;
- slot B -> Direct Demand provider 1;
- slot C -> Direct Demand provider 2.

There is no global Prebid-versus-Direct-Demand auction and Direct Demand does not automatically wait for Prebid.

A standard physical placement has one renderer owner. A placement mapped to both standalone Prebid and Direct Demand without an explicit composite design is a configuration conflict and fails closed. This existing placement invariant remains authoritative.

## Structured direct tag recipe

Public recipes are data, not executable database JavaScript.

```json
{
  "recipeVersion": 1,
  "executionMode": "STRUCTURED",
  "format": "DISPLAY",
  "scripts": [
    {
      "url": "https://provider.example/public-loader.js",
      "async": true,
      "defer": false,
      "dedupeKey": "provider-loader",
      "attributes": {}
    }
  ],
  "container": {
    "element": "div",
    "id": "provider-issued-container",
    "class": "provider-class",
    "attributes": {
      "data-widget-id": "provider-issued-public-id"
    }
  },
  "publicPlacementId": "provider-issued-public-id",
  "initialization": {
    "type": "NONE",
    "parameters": {}
  },
  "render": {
    "timeoutMs": 2500,
    "successSelector": null,
    "assumeLoadedIsSuccess": false,
    "allowedFormats": ["DISPLAY"],
    "allowedSizes": [[300, 250]]
  },
  "isolation": null
}
```

Recipe rules:

- every external script is HTTPS and must match an approved provider/network origin;
- one recipe may contain multiple ordered scripts;
- shared loader scripts are deduplicated page-wide while each placement still performs its own initialization;
- public `data-*`/`aria-*` attributes and provider-issued public IDs are allowed;
- credentials, authorization tokens, environment/file references, secrets and private keys are never emitted;
- `javascript:` URLs and inline DOM event handlers are rejected;
- arbitrary initialization JavaScript is never stored or evaluated;
- initialization is an enumerated connector-owned action implemented in Horus code.

Schema v4 also retains the old flattened `scriptUrl`, `containerId`, `containerClass`, `attributes`, `renderTimeoutMs`, `successSelector` and `assumeLoadedIsSuccess` fields inside the tag during the compatibility window.

## Paste-and-parse boundary

`DirectTagRecipeParser` parses provider-issued markup without executing it. A review result exposes:

- detected external scripts;
- candidate render container;
- public identifiers and attributes;
- unsupported inline code;
- security warnings;
- a structured recipe only when conversion is safe.

Generic inline JavaScript is unsupported. A connector may translate only a narrowly recognized official pattern into a trusted initialization enum. Unknown code remains a warning/rejection.

Protocol-relative provider script URLs are normalized to HTTPS. Plain HTTP is not accepted.

## Trusted connector actions

The Loader implements only these trusted initialization actions in Task 17:

- `NONE`;
- `MGID_QUEUE_LOAD`;
- `TABOOLA_QUEUE`;
- `OUTBRAIN_RESEARCH`.

The connector must opt in to the action and provide only public parameters. Provider values such as widget ID, placement name, mode, target type, publisher ID or zone ID come from the provider-issued tag/account mapping. Horus does not invent them.

Speakol remains on the provider-neutral structured path because the public official material reviewed for this task did not define a sufficiently specific initialization recipe to hard-code safely.

## Custom Third Party isolation

`CUSTOM_THIRD_PARTY_TAG` is not permission to execute arbitrary top-window JavaScript.

When an approved custom tag cannot be represented structurally, Horus may emit `executionMode=ISOLATED_IFRAME` only when all of the following hold:

- the tag passed existing third-party HTML safety validation;
- explicit HTTPS provider origins were approved;
- a restrictive CSP is generated for those origins;
- the iframe is an opaque-origin sandbox with only `allow-scripts`;
- no `allow-same-origin`, top navigation or Horus control-plane origin is granted.

If the provider cannot operate within that isolation, the tag is unsupported and fails closed rather than weakening the publisher-page security model.

## Browser lifecycle

For a structured Direct Demand placement the permanent Loader:

1. confirms the site and placement are active and operational controls permit serving;
2. waits for the existing privacy gate;
3. confirms Click Guard permits a new request;
4. creates the approved provider container;
5. loads every approved external script with a bounded network timeout;
6. reuses a page-wide script promise when another placement uses the same dedupe key;
7. runs only the connector-approved initialization enum for this placement;
8. checks the configured success policy within the bounded render window;
9. marks only that placement rendered or fails to the next configured Direct Demand candidate/house path.

A DNS/script error, timeout, initialization failure, no-render or malformed recipe is contained to the placement. Raw provider errors are not exposed to Publisher UI.

The existing SPA rescan and duplicate-Loader protections remain. Dynamic replacement nodes receive monotonic Loader element IDs so a removed no-GAM placement cannot accidentally reuse an earlier runtime key.

## Lifecycle and controls

A candidate is public only while all relevant gates remain eligible:

- website enabled/active;
- placement active;
- network enabled;
- account enabled and approved;
- site mapping enabled and approved;
- placement mapping enabled and approved;
- master `AD_SERVING` not disabled;
- `DIRECT_JS` not disabled for Direct Demand;
- legacy `NATIVE_DEMAND` broad pause not active.

Browser privacy and Click Guard are final runtime gates.

## Ads.txt and reporting

Direct Demand does not create parallel compliance or reporting systems.

- ads.txt records continue through `DemandReportService::syncAdsTxt()` and the canonical Ads.txt Compliance / Supply Chain services;
- reporting continues through provider API or approved CSV/manual import into `DemandReportService` and `ReportingBridge`;
- the browser Loader does not post raw Direct Demand impressions/clicks to Laravel.

## Formats

The recipe carries explicit format and size capabilities. Display and existing native/widget integrations are supported when the provider tag is representable by the structured lifecycle. Outstream/video may be enabled only for a provider recipe whose browser behavior is known to be safe with the same isolation/ownership rules. Unknown video/native renderer semantics fail closed rather than being labeled supported optimistically.
