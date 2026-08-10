# Horus Media Support Ticket System

## Scope

The Support workspace is a first-party, threaded customer-support product for
Publisher, Advertiser, and Partner organizations. It uses the existing Horus
identity, organization scope, permission, audit, private-upload, navigation,
and database/cron architecture. It does not require Redis, WebSockets, a
permanent worker, or a production Node.js runtime.

## Domain model

- `SupportTicket` is the organization-owned case and contains the public
  `HM-TKT-...` identifier, requester, category, operational priority, status,
  assignee, optional authorized resource link, SLA evidence, and lifecycle
  timestamps.
- `SupportTicketMessage` is either `PUBLIC` or `INTERNAL`. Customer queries
  select only `PUBLIC`; internal rows are never loaded into customer views.
- `SupportTicketAttachment` stores private randomized paths, bounded metadata,
  and the parent message/ticket relationship.
- `SupportTicketEvent` is the append-oriented ticket timeline. Conversation
  bodies are not copied into the global audit log.
- `SupportSlaPolicy` stores priority-aware first-response/resolution targets and
  whether the resolution clock pauses while waiting for a customer.

Ticket IDs and database relations use ULIDs. The human ticket number derives
from a freshly generated ULID and has an independent unique database index; no
sequential database integer is exposed.

## Controlled values and lifecycle

Categories are centrally defined as Technical, Monetization, Ads / Serving,
Revenue & Reporting, Payments, Ads.txt / Compliance, Website Approval,
Contracts, Account, Campaigns, and Other.

Priorities are `LOW`, `NORMAL`, `HIGH`, and `URGENT`. Customers may select the
first three; authorized Horus staff retain the operational `URGENT` decision.

The state machine is:

```text
OPEN -> PENDING_CUSTOMER (Horus public reply)
OPEN/PENDING_CUSTOMER -> PENDING_HORUS (customer reply)
OPEN/PENDING_* -> RESOLVED (Horus decision)
RESOLVED -> CLOSED
RESOLVED/CLOSED -> PENDING_HORUS (reopen)
```

Customers can close their organization ticket and reopen a resolved/closed
ticket. Invalid arbitrary transitions are rejected in the service under a row
lock, independently of which UI controls are rendered.

## Authorized linked resources

The controlled resource aliases are `SITE`, `CONTRACT`, `STATEMENT`, `PAYMENT`,
and `CAMPAIGN`. `SupportLinkedResourceResolver` validates the resource against
the requester's `organization_id` using the authoritative domain model. It does
not accept an arbitrary morph class, route, URL, or organization parameter.
This prevents polymorphic IDOR and cross-product resource guessing.

## Public conversation and internal notes

Public messages are visible to the ticket organization and authorized Horus
Support staff. Customer input is rendered as escaped plain text; HTML and
scripts are never trusted.

Internal notes require `support.internal_notes.view`. Publisher, Advertiser,
and Partner controllers query the `publicMessages` relation, so an internal
note is absent from their HTML source and not merely hidden with CSS. Task 8
must preserve the same boundary in notification payloads and emails.

## Private attachments

`SecureUploadService::storeRandomized()` is reused with a 10 MiB default limit.
The server-derived MIME and client extension must match an allowlist: PDF, JPG,
PNG, WebP, plain text, or CSV. Executable extensions and mismatched content are
rejected. Files use randomized names under the Laravel private disk.

Every download validates both the parent ticket and attachment relationship,
then independently checks Horus Support permission or customer organization
ownership. Responses use safe content disposition, `nosniff`, and private
`no-store` caching.

## Permissions

Customer capabilities:

- `support.tickets.create`
- `support.tickets.view_own`
- `support.tickets.reply_own`

Horus capabilities:

- `support.admin.view`
- `support.admin.reply`
- `support.admin.assign`
- `support.admin.manage`
- `support.internal_notes.view`

Publisher/Advertiser/Partner Admin roles receive create/view/reply. Viewer
roles receive organization-scoped view only. Support Agent and Operations Admin
receive the Admin Support lifecycle. Super Admin retains every capability.
The legacy `support.manage` permission remains for data compatibility but no
longer protects the product as one all-powerful capability.

## SLA behavior

Default targets are stored per priority and can be seeded repeatedly. Ticket
creation persists the selected policy and due timestamps. A first Horus public
reply records `first_response_at`. When a Horus reply waits on the customer,
the configured resolution clock pauses; the next customer reply shifts the due
time by exactly the paused duration. Reprioritization is audited and recalculates
the applicable persisted targets.

Computed presentation states are `ON_TRACK`, `APPROACHING`, `BREACHED`, `MET`,
`PAUSED`, and `NOT_APPLICABLE`. The Task 8 scheduler emits idempotent warning
and breach notifications keyed by ticket, metric, due timestamp, and state,
without changing this calculation boundary. It also appends one sanitized SLA
event when a transition is first notified.

## Abuse, audit, and operational access

Named Laravel rate limiters constrain customer creation, replies, state
actions, and downloads. List screens paginate and eager-load only bounded
summary relations; message histories are loaded only on ticket detail.

Assignment, priority changes, status changes, close, and reopen create sanitized
global audit entries. Ticket creation, public replies, internal notes,
attachments, assignment, priority, state changes, and reopen also append a
ticket-specific event. Message bodies and private attachment paths never enter
global audit metadata.

## Tests

`SupportTicketSystemTest` covers creation, collision-safe display numbers,
organization and linked-resource isolation, public replies, internal-note
confidentiality, stored XSS, private attachments, executable/MIME rejection,
state transitions, reopen, assignment, role permissions, SLA pause/warning/
breach, pagination, rate limiting, audit, and event evidence.
