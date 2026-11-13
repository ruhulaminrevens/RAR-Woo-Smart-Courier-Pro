# RAR Woo Smart Courier Pro v3.1.1

**Corrected release for safe RAR Courier Suite migration.** Supersedes v3.1.0.

## Fixed since v3.1.0

- Courier APIs and Tools & Health stay visible when old Suite or original vendor integrations are still active, so settings migration, account tests, parcel counts and historical imports can be verified *before* uninstalling Suite.
- Connection/lookup AJAX operations are available in read-only coexistence mode. API send, bulk-send, status mutation and other booking handlers remain deliberately disabled until the conflicting dispatch owner is deactivated.
- Added a standalone regression for the safe read-only-vs-normal AJAX registration behavior and included it in PHP 7.4, 8.2 and 8.3 CI jobs.

## Prior v3.1.0 fixes retained

Suite credentials migration, encrypted webhook secret, paged historical import and legacy HPOS batches, cross-provider duplicate booking safety, final-status handling, deduplicated customer tracking, license and attribution alignment.

## Production deployment gate

Source tests, ZIP integrity and checksum are automated; actual WordPress/WooCommerce/HPOS staging tests, merchant provider API bookings/webhook delivery and old-vs-new shipment count reconciliation are still required. **Do not uninstall the Suite** before those checks. The Suite's **Delete data on uninstall** option must be OFF before deletion. Paperfly and some RedX endpoints are provider dependent; Sundarban supports manual CN entry.

Read [installation / rollback checklist](https://github.com/ruhulaminrevens/RAR-Woo-Smart-Courier-Pro/blob/main/docs/INSTALLATION.md) and [source audit](https://github.com/ruhulaminrevens/RAR-Woo-Smart-Courier-Pro/blob/main/docs/AUDIT-2026-11-14.md).
