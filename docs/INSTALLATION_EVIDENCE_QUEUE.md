# Recorded installation evidence in the attention queue

The user-requested safe HTML checker already audits its allowlisted result. New
checks additionally record the site's existing public primary domain/key and
whether the requested host was that primary domain. No page URL, path, query,
HTML, token, error text or visitor identity is added to the diagnostic payload.
The existing audit envelope is unchanged.

Operations can filter recorded installation findings and unavailable checks.
The latest completed primary-domain check for the current public domain/key
wins. A newer success clears an older finding; unknown codes do not resurrect
old failures. Legacy unscoped records, secondary-domain checks, wrong-tenant
rows, deleted sites and records for previous domain/key values are excluded.
The view selects only result code/time and relationship keys from audit rows.
One eager query covers the page's installation records; no new schema or N+1
requests. Existing audit entity/time indexes apply.

This is historical evidence about one requested page, not a site-wide monitor.
Finding a tag in HTML does not prove script execution or ad rendering. HTTP,
transport and safety failures describe unavailable inspection evidence. GET
runs no check and writes nothing. Existing Horus Operations/site permissions,
CSRF, request throttle, URL safety, DNS pinning and checker deadlines remain.

Changed files: app/Http/Controllers/SiteInstallationCheckController.php,
app/Models/Site.php, app/Services/Sites/StoredSourceAttention.php,
app/Http/Controllers/Admin/SiteAttentionController.php,
resources/views/admin/operations/site-attention.blade.php,
tests/Feature/PublisherPageCheckerTest.php, tests/Feature/SiteAttentionTest.php,
docs/INSTALLATION_EVIDENCE_QUEUE.md.

Validation: PHP 8.4 targeted suites passed: 8 tests, 74 assertions. Coverage
includes stale domain/key, secondary/legacy records, newer recovery/unknown,
wrong-tenant forged success, redaction, bounded query count, read-only GET and
publisher denial. Diff and static-analysis results are recorded in the PR.
Required PHP/MySQL/browser CI and production deployment remain separate steps.
