# Platform access review — 2026-11-16

Existing role definitions remain authoritative. This review adds regression
coverage rather than redefining roles or introducing an assignment requirement.

| Existing role | Config history / recipe review | Attention queue / performance report | Performance changes |
|---|---|---|---|
| Operations Admin | Allow | Allow | Existing configs.manage permission |
| Ad Ops Admin | Allow | Allow | Existing configs.manage permission |
| Support Agent | Allow | Deny | Deny |
| Finance Admin | Deny | Deny | Deny |
| Publisher Admin / Viewer | No Horus administration | Own publisher surfaces | No Horus configuration access |

Publisher affiliate reads derive the owner from the authenticated organization;
foreign publisher selectors are ignored. Publisher Admin and Viewer cannot change
commission administration; Partner Admin cannot enter the publisher affiliate page.
Referral progress exposes authorized aggregate counts and retains organization
parity and soft-delete filtering. Existing tests also cover immutable finalized
commission rates, replay-safe reconciliation, no retrospective referral credit and
no commission on affiliate income.

Validation: PlatformAccessReviewTest, PublisherAffiliateSystemTest and
RolePermissionTest: 11 tests / 65 assertions passed locally on PHP 8.4.
This verifies the existing role model for these additions. It does not claim a
new per-site assigned-staff model or a complete audit of every legacy route.
No payout processor or financial calculation was changed.
