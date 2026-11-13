# RAR Woo Smart Courier Pro v3.1.2

**Integration-tested fix release.** Supersedes v3.1.1; keeps all v3.1.x safety work (read-only coexistence, resumable migration, cross-courier duplicate guard, encrypted secrets).

## Fixed since v3.1.1

- **Suite parcel migration could finish empty.** It was also triggered at `plugins_loaded`, before WooCommerce registers orders. It now runs only on `wp_loaded`; upgrading resets the cursor once so every old parcel is scanned.
- **SteadFast tracking** now uses the courier's `tracking_code` instead of the numeric consignment id, so customer tracking links work.
- **Auto-send and bulk bookings** are mirrored to the Shipments board with tracking number and status.
- **Courier choice:** an auto-recommended courier no longer overrides the auto-send courier from Settings or the customer's checkout pick.
- Removed an unused duplicate front-end class.

## Added

- Tools & Health → **Import from RAR Courier Suite** (repeatable; fills only blank settings, mirrors parcels).

## How it was tested

WordPress 7.1.4 + WooCommerce 11.3.0 on SQLite, both legacy and HPOS order storage, with RAR Courier Suite active (read-only coexistence) and deactivated. Scenarios: Pathao/RedX/SteadFast/Paperfly single and bulk booking, Sundarban manual CN, webhooks, cron sync, auto-send, AJAX permissions and nonces, Suite settings/parcel import, uninstall data retention. Plus the repository's standalone logic, migration, backfill and ownership tests.

## Still required before uninstalling the Suite

Courier HTTP responses were mocked. Run real bookings and webhook delivery on staging, and compare old vs new parcel counts, before deleting RAR Courier Suite (keep its *Delete data on uninstall* OFF). Paperfly and some RedX endpoints depend on your merchant account.

See the [installation / rollback checklist](https://github.com/ruhulaminrevens/RAR-Woo-Smart-Courier-Pro/blob/main/docs/INSTALLATION.md).
