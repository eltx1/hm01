# HORUS_GAM Setup

`HORUS_GAM` is the default and primary advertising architecture. MCM partner GAM and publisher-owned GAM are optional connections only.

1. Create or select the Horus Media GAM network.
2. Create a Google Cloud service account dedicated to this application.
3. Add its email as a GAM user with only the permissions required for inventory, orders, line items, creatives, reports, and read reconciliation.
4. Store the downloaded JSON outside public web directories and restrict file permissions.
5. Set `GAM_HORUS_NETWORK_CODE` and `GAM_HORUS_SERVICE_ACCOUNT_PATH` in `.env`.
6. In the dashboard create the primary connection with type `HORUS_GAM`, the matching network code, and an `env:GAM_HORUS_SERVICE_ACCOUNT_PATH` credential reference.
7. Keep dry-run enabled and run connection/permission discovery.
8. Create one local test website and one ad unit; run a dry-run sync and inspect sanitized `gam_api_operations`.
9. Approve one write, synchronize the ad unit, and confirm its `gam_remote_objects` mapping.
10. Re-run the same operation to confirm idempotent duplicate handling.
11. Create a house advertiser/campaign and deploy it to the Horus connection only.
12. Enable production writes gradually after the pilot confirms reporting and reconciliation.

Never paste JSON credentials, OAuth tokens, or private keys into database configuration, notes, logs, static JSON, or support messages. The global GAM kill switch is an emergency control; it does not remove or architecturally block HORUS_GAM.
