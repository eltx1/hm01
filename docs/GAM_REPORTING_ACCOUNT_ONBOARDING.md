# Connect Google Ad Manager accounts from a website

From **Admin → Website → Reports**, use **Connect with Google** directly.
The admin may enter the exact ad unit name, code or ID before authorization; it is
kept in encrypted, session-bound state. After Google consent, a sole network is
selected automatically and the unit is verified and bound through the existing
financial reporting service. With several networks, the admin explicitly chooses
the one containing that unit. Horus never guesses which network owns a website.

If no unit was entered, one or more networks may be saved together and the admin
returns to the same website with its account selected to search for the unit.
If unit verification fails, the account stays connected and the original input
is preserved for correction without repeating Google consent. Repeated submission
reuses the saved connection and existing identical site binding.

## Platform provisioning (operations only)

Website administrators do not create Google apps, enter callbacks, or upload
credential files. The platform operator provisions **one platform-owned Google
Web OAuth application** for all reporting accounts. A Google Cloud project and
registered Web client are still required by Google; local readiness never claims
that external provisioning, approval or live consent has already succeeded.

The callback uses configured `APP_URL`, not the request Host header:

`https://app.horusmedia.net/admin/gam/reporting/oauth/callback`

After obtaining the platform application's private downloaded file, operations
can install it in encrypted shared storage with:

```sh
php artisan gam:reporting-google --file=/private/path/google-web-client.json
php artisan gam:reporting-google
```

The import validates client type, credential structure, and the exact authorized
callback. Public-directory files are rejected; failed imports preserve existing
configuration. No secrets are accepted as command arguments or printed. Imported
secrets are encrypted; the original source should remain only in protected
operator storage. The command is not a live authorization test.

Alternatively configure `GAM_REPORTING_OAUTH_APP_REFERENCE=file:/private/path/...`
(or an existing `env:` reference). Explicit deployment configuration takes
precedence and fails closed if unreadable/invalid rather than silently switching
clients. Existing encrypted `managed:oauth-app` configuration remains supported.
No automatic guessing or reuse of unrelated Google OAuth clients is performed.

If the platform app is absent or corrupt, the admin sees a truthful activation
pending message, with no technical setup form. Existing GAM/CSV sources stay
available. The normal production audit separately reports this local readiness
without printing secrets. The older permission-protected upload endpoints remain
compatible for existing operational tooling; they are not in the website flow.

Google Cloud audience/consent configuration and any required production
verification belong to platform operations. Google's Testing-mode refresh-token
expiry still applies. Each Google account owner authorizes their account, and
Ad Manager API/reporting access must be enabled for its networks. These Google
requirements cannot be bypassed by changing the admin interface.

## Account isolation and reconnection

- Several Google identities and several networks per identity are supported.
- Reconnecting the same identity/network updates its reporting credential while
  preserving its connection ID and existing site bindings; repeating the final
  submission returns the same saved accounts. Other identities remain independent.
- These accounts have `is_reporting_only=true`. They never become a serving
  fallback or the primary Horus network. Serving assignment, credential editing
  through the old full-connection form, and advertising API writes are rejected
  for these new reporting accounts. Existing full GAM connections are unchanged.
- Google's SOAP API has one Ad Manager scope whose consent wording includes
  management. Horus enforces the reporting-only restriction in both its serving
  resolver and SOAP/REST execution paths and explains the consent wording in UI.
- Reports do not begin until the actual website ad unit has been verified and
  bound, including the optional unit entered before Google consent. The previous reporting feature's effective dates, financial rules,
  historical preservation and duplicate protection still apply.

## Credential lifecycle

The one-time app configuration and saved credentials are encrypted with the
Laravel application key in private `storage/app/private/gam-credentials` files
(0700 directory, 0600 files, atomic replacement). Shared storage survives normal
atomic deployments and is covered by the existing storage backup. Release ZIPs
exclude storage payloads. Keep the application key with its existing protected
backup; rotating it requires re-encryption/reconnection as with other encrypted
platform data. No credential values are returned to the browser or audit logs.

OAuth uses a random, single-use state, browser/admin ownership binding and PKCE
S256. Temporary state and pending material are encrypted in the server cache for
10/20 minutes. Temporary credential material is removed after account creation.
Callbacks recheck admin permissions and two-factor middleware. Cancelling,
expired state, a missing refresh token or denied Ad Manager scope cannot create a
connection. Google endpoints are fixed HTTPS destinations without redirects.

References: [Ad Manager authentication](https://developers.google.com/ad-manager/api/authentication),
[Google web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server),
[network discovery](https://developers.google.com/ad-manager/api/reference/v202608/NetworkService#getAllNetworks),
[refresh-token expiry](https://developers.google.com/identity/protocols/oauth2#expiration).
The installed SDK continues to determine the runtime SOAP version.
