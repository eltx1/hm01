# Google Ad Manager Setup for Horus Media

This guide connects the Horus Media control plane to the main `HORUS_GAM`
network and to optional MCM partner or publisher networks.

## 1. Create a Google Cloud project

1. Sign in to Google Cloud Console with the company-owned Google account.
2. Create a dedicated project such as `horus-media-ad-platform`.
3. Keep the project under the Horus Media organization when one is available.
4. Record the project ID in the internal credential register. It is not a
   private key, but it should still be managed as operational configuration.

## 2. Enable Google Ad Manager API

1. Open **APIs & Services** in the Google Cloud project.
2. Open **Library**.
3. Search for **Google Ad Manager API**.
4. Enable it for the project.
5. Do not enable unrelated APIs merely for convenience.

The application uses the version in `config/gam.php`. Never place an API
version directly inside service classes.

## 3. Create a service account

1. Open **IAM & Admin → Service Accounts**.
2. Create a service account named `horus-gam-control-plane`.
3. Do not grant broad Google Cloud project roles unless they are independently
   required. Access to GAM is granted inside Google Ad Manager itself.
4. Create a JSON key only on the production administrator's trusted device.
5. Move the JSON file to a private hosting directory outside `public_html`.
6. Restrict filesystem permissions so only the application account can read it.

Suggested Hostinger path:

```text
/home/ACCOUNT/private/gam-horus-service-account.json
```

Never upload the file to GitHub, a public web directory, an issue, a pull
request, a support ticket, or browser JavaScript.

## 4. Add the service-account email to Google Ad Manager

1. Copy `client_email` from the service-account JSON file.
2. Sign in to the required Google Ad Manager network.
3. Open **Admin → Access & authorization → Users**.
4. Add the service-account email as a GAM user.
5. Assign a role that permits only the operations Horus Media needs.
6. Confirm API access and the required inventory, orders, line items, creatives,
   custom targeting, and reporting permissions.

Use a test network or a restricted test role before permitting production
writes. Dry-run remains enabled by default in Horus Media.

## 5. Configure the main HORUS_GAM network

Set the production environment variables:

```dotenv
GAM_API_VERSION=v202602
GAM_APPLICATION_NAME="Horus Media Platform"
GAM_HORUS_NETWORK_CODE=123456789
GAM_HORUS_SERVICE_ACCOUNT_PATH=/home/ACCOUNT/private/gam-horus-service-account.json
GAM_DRY_RUN_DEFAULT=true
```

In the Horus Media administrator dashboard:

1. Open **Google Ad Manager → Add connection**.
2. Select `HORUS_GAM`.
3. Select `SERVICE_ACCOUNT`.
4. Select the `SOAP` driver.
5. Enter the network code.
6. Enter this credential reference:

```text
env:GAM_HORUS_SERVICE_ACCOUNT_PATH
```

7. Leave **Dry-run by default** enabled.
8. Save the connection.
9. Run **Test and synchronize**.
10. Confirm that network metadata and permission checks appear.
11. Select **Make primary HORUS_GAM** when it is not already primary.

The first Horus connection becomes primary automatically. Selecting another
primary Horus connection turns off the primary flag on the previous one without
deleting it or changing any website assignments.

## 6. Add optional MCM partner GAM

1. Obtain written authorization and the correct GAM user/API access from the
   partner.
2. Store that partner's credential file in a separate private file.
3. Create a separate environment variable for it.
4. Add a new connection with type `MCM_PARTNER_GAM`.
5. Test and synchronize the connection.
6. Assign it only to the intended website from the connection page.

Example reference:

```text
env:GAM_PARTNER_ALPHA_SERVICE_ACCOUNT_PATH
```

MCM partner connections never replace the main Horus connection globally unless
an administrator explicitly assigns them per website.

## 7. Add optional publisher GAM

A publisher connection is owned by the publisher organization in Horus Media:

1. The publisher adds the service-account or OAuth user to its GAM network.
2. Horus Media stores a separate protected credential reference.
3. Create the connection as `PUBLISHER_GAM` and select the publisher's
   organization as owner.
4. Test the connection.
5. Assign it to the intended publisher website.

The resolver does not select one publisher's GAM connection for another
publisher organization.

## 8. OAuth2 alternative

For OAuth2, the protected JSON file must contain:

```json
{
  "client_id": "...",
  "client_secret": "...",
  "refresh_token": "...",
  "token_uri": "https://oauth2.googleapis.com/token"
}
```

The database stores only an encrypted `env:` or `file:` reference to this file.
The UI never displays the reference after saving, and access tokens are cached
only as Laravel-encrypted ciphertext.

## 9. Safe first production test

1. Keep the connection in dry-run mode.
2. Test network access.
3. Review permission results.
4. Use a GAM test network where possible.
5. Run one mocked or house-campaign creation plan.
6. Inspect `gam_api_operations` for a `DRY_RUN` result.
7. Disable dry-run only for one approved write.
8. Verify the remote object and its `gam_remote_objects` mapping.
9. Re-run the same operation and confirm it reports a duplicate instead of
   creating another GAM object.

## 10. Troubleshooting

- `SOAP_EXTENSION_MISSING`: enable the PHP SOAP extension in the hosting PHP
  configuration.
- `AUTHENTICATION`: verify the credential file, clock, service account, and API.
- `PERMISSION`: confirm the service-account email and GAM user role.
- `NETWORK_NOT_FOUND`: confirm the network code is accessible to the credential.
- `QUOTA` or `RATE_LIMIT`: wait and retry reads; do not blindly retry writes.
- `CONNECTION_DISABLED`: enable the connection in Horus Media.

Use the administrator dashboard's operation and error records. They contain
sanitized payloads and categories, never private keys or tokens.
