# Connect Google Ad Manager accounts from a website

From **Admin → Website → Reports**, select **Connect your first Ad Manager
account** (or **Connect another Ad Manager account**). Sign in with Google and
approve access. A sole accessible network is selected automatically; if Google
returns several networks, select one or more together. The administrator returns
to the same website with the new account selected and chooses its ad unit.
Existing connected accounts remain available for reuse across websites.

## One-time Google app setup

Google authorization requires an OAuth Web application belonging to the platform.
If it has not been configured, the same connection screen opens setup instructions
and provides the exact callback URI:

`https://app.horusmedia.net/admin/gam/reporting/oauth/callback`

Configure the Google Auth Platform audience and consent screen, create a Web
application client with that authorized redirect URI, download its JSON, and use
**Save and continue with Google**. No server paths, refresh tokens, SSH changes,
or individual Google Cloud project per website are required. The callback uses
the configured application URL rather than an untrusted request host.

For an external app in Testing, add permitted test users. Google's seven-day
refresh-token expiry for this mode applies to Ad Manager authorization; configure
the appropriate production audience/verification for ongoing reporting. Google
account consent and any required Google application configuration are external
steps which the platform cannot silently bypass. Ad Manager API access must also
be enabled in the selected network.

An administrator who already has a Google service-account key can instead upload
its JSON through the alternative form. The service-account email must be added to
the Google network with the needed inventory/reporting permissions. Networks are
discovered and verified through Google in the same way. Uploaded JSON cannot
choose token endpoints or redirect destinations.

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
- Reports do not begin until the administrator chooses the actual website ad
  unit. The previous reporting feature's effective dates, financial rules,
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
