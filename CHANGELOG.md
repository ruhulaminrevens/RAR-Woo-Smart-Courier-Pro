# Changelog

## 3.1.2 — 2026-11-14

Integration-tested on WordPress 7.1.4 + WooCommerce 11.3.0 (SQLite), legacy and HPOS order storage, with RAR Courier Suite active and inactive; courier HTTP calls mocked.

### Fixed
- Suite parcel migration ran from `maybe_upgrade()` at `plugins_loaded`, before WooCommerce registers order types (WooCommerce "called incorrectly" notice). It could scan nothing and record completion. It now runs only on `wp_loaded`; upgrading to 3.1.2 resets the cursor once.
- SteadFast tracking mirrored the numeric consignment id. The bridge now uses the `tracking_code` (`sfc_ref`), which the public tracking page needs.
- Auto-send and courier-module bulk bookings were not mirrored to the Shipments board (tracking / status left empty).
- An auto-assigned (`_rwsc_source = auto`) recommendation was treated as the customer's choice and overrode the auto-send courier from Settings.
- Removed the unused duplicate dispatch front-end class (customer tracking is rendered once by the core plugin).

### Added
- Tools & Health: repeatable **Import from RAR Courier Suite** (settings fill-blank-only + parcel mirror) next to *Continue parcel migration*.

### Changed
- `WC tested up to` 11.3, `Tested up to` 7.1.

## 3.1.1 — 2026-11-14

- Regression fix: Courier APIs and Tools & Health remain accessible while Suite/original vendor plugins own dispatch, enabling pre-uninstall settings and historic-parcel verification.
- Coexistence AJAX handlers allow only non-mutating API connection/lookup checks; create/refresh/status mutation and bulk booking remain disabled until conflicting plugins are deactivated.
- Added an ownership/coactivation regression test to the PHP CI matrix and rebuilt installable package.

## 3.1.0 — 2026-11-14

**Safety and migration hardening** of the v3.0 Suite consolidation; not a claimed live-provider certification.

### Fixed
- Suite settings importer handles array-valued settings (PHP 8 compatible), fills blank Pro values without overwriting existing configuration, and preserves provider credentials and tokens.
- Encrypts historical plaintext webhook secret (when OpenSSL is available) and includes webhook secret in encrypted settings.
- Paginated resumable Suite parcel migration with an explicit completion status and manual continuation. Historic imports do not send tracking notifications or trigger WooCommerce status automations.
- API shipment bridge writes the true courier without re-quoting checkout shipping for old orders; terminal states cannot silently reverse.
- Native Pathao bulk now checks all-courier duplicate and uncertain-timeout guards under the order lock. Transport uncertainty prevents unsafe retries.
- Pathao, RedX, SteadFast, Paperfly and Sundarban automated terminal status changes are protected against late events.
- Pauses embedded API dispatch if an original Pathao, RedX or SteadFast plugin is active; prevents overlapping webhook/admin handlers.
- Registers custom 30-minute WP-Cron interval before activation scheduling and cleans up migration tasks on deactivation.
- Deduplicates customer-facing tracking cards and emails across Pro and its ported dispatch module.
- Legacy RedX/SteadFast and HPOS old-Pathao postmeta migration actions now scan additional batches instead of repeatedly reading the first 500.
- Clarifies configured credentials versus verified connection; removes stale Suite branding in tools.

### Validation
- PHP 7.4/8.2/8.3 syntax, standalone logic/migration/backfill regressions, JS syntax, and checked installable ZIP in GitHub Actions.
- **Still unverified:** WordPress/WooCommerce end-to-end operation on a live site, third-party provider API behavior, webhook delivery and real COD reconciliation. Do not uninstall Suite without a staging and production acceptance check.


## 3.0.0 — 2026-11-14

**The RAR Courier Suite source engine was ported.** The v3.0 unconditional removal statement was premature; follow the verified v3.1 migration checklist.

### Added — live courier APIs
- Book parcels straight from the WooCommerce Orders list (single, bulk and a native Pathao bulk call) with **Pathao** (v3 engine: token lifecycle, stores, cities / zones / areas, price estimate), **RedX**, **SteadFast** and **Paperfly**; **Sundarban** works as manual CN entry.
- One named database lock per order across all couriers, and one courier per order. A timed-out ("uncertain") send blocks every courier for 10 minutes, so a parcel is never booked twice.
- Idempotent, ordered status updates that never reopen a delivered / returned parcel.
- Hardened webhooks: Pathao `X-PATHAO-Signature` (`hash_equals`) and handshake header, SteadFast Bearer token, de-duplication, unknown orders acknowledged. Webhook URLs are unchanged from the Suite (`/wp-json/ptc/v1/webhook`, `/wp-json/ptc/v1/steadfast`), so nothing needs re-registering.
- 30-minute status-sync safety net (WP-Cron / Action Scheduler) and optional auto-send when an order reaches a chosen status, with courier routing.
- COD- and advance-payment-aware amounts (RAR Advance Payment and prepaid gateways).
- API secrets encrypted at rest (AES-256-CBC + HMAC), optional `wp-config.php` constants, secret-redacting WooCommerce log.
- Order list **Dispatch** column, bulk actions, order-screen box with timeline, customer tracking block in My Account and e-mails.
- New admin pages: **Courier APIs** (settings per courier) and **Tools & Health** (diagnostics, 30-day summary, cache flush, legacy RedX / SteadFast import, log tail).
- **Bridge**: every booking and status change made through an API is mirrored into the Smart Courier shipment model, so the Shipments board, Dashboard, labels, manifests, reports and `[rwsc_tracking]` always show the courier's real state.

### Migration
- On first load the Suite's settings (`pt_hms_settings`, encrypted values copied as-is, same key) and Pathao token are imported into `rwsc_api`; existing parcels are mirrored into the shipment board once, silently (no customer e-mails).
- While the Suite is still active the dispatch module pauses (no duplicate webhooks or cron) and a notice explains how to remove it.
- Order meta (`ptc_*`, `rdx_*`, `sfc_*`, `pf_*`, `sdb_*`) is untouched, so old parcels keep working.

### Changed
- Version 3.0.0; description and docs updated. Uninstall also clears the new cron hooks and, only if you ticked "Delete data on uninstall", the API settings. Order data is never deleted.

## 2.0.0 — 2026-09-25

### Fixed
- Dhaka and Nearby detection. WooCommerce stores Bangladesh districts as codes (`BD-13`), but v1.x compared them with the word "Dhaka", so every order was priced as Outside Dhaka. Districts now resolve from codes, English and বাংলা names, and old spellings.
- Nearby areas inside Dhaka district (Savar, Ashulia, Keraniganj…) are now checked before the Inside Dhaka rate. They are also found in the address line.
- Product weights are converted to kg from g, lbs and oz, and the weight comes from the shipping package instead of the whole cart.
- Shipping tax is recalculated for each courier's price instead of copying the flat-rate tax.
- Local pickup is kept, and it is never used as the price base.
- Rate caching: saving settings refreshes checkout prices straight away.
- Courier data is saved for Cart/Checkout block orders as well as classic checkout orders.
- Internal rate meta is hidden from the admin order items.
- The order list Courier column now works with HPOS.

### Added
- HPOS and Cart/Checkout blocks compatibility is declared to WooCommerce.
- Estimated delivery dates, using off-days, holidays and a same-day cut-off time.
- Recommended, Fastest and Best price badges; three recommendation modes; a detailed or compact label style.
- Free-shipping mode: every courier free, or only the recommended one.
- Per courier: colour, tracking-link template, maximum weight and on/off per zone. Unlimited custom couriers.
- Areas that always count as "Inside Dhaka", an address-line matching switch, a packaging weight, and a Bangladesh-only switch.
- Courier dashboard: KPIs, parcels per day, courier share, courier performance, shipment pipeline, zones and districts, rate calculator, "Needs attention" list and setup check.
- Shipments board: status tabs, search and filters, inline courier/tracking/status editing, Enter-to-book, bulk actions, details drawer with timeline, and copy parcel info.
- Parcel labels with a Code 128 barcode (A4, 4×6 in and A6), a hand-over manifest, and a UTF-8 CSV export.
- Order screen: Smart Courier box, Courier column, courier and status filters, bulk actions, and order preview details.
- Customer side: tracking email (customer note), a delivery card on the thank-you and My Account pages, a Track button, courier details in emails, and a `[rwsc_tracking]` tracking page.
- Automation: the recommended courier is auto-assigned to phone, admin and staff-app orders. Orders can optionally be completed on delivery and change status on return.
- Tabbed settings page with a live label preview and an address tester, plus settings export/import/reset and a translation template.
- Public API: `rwsc_get_quotes()`, `rwsc_get_shipment()`, `rwsc_update_shipment()`, plus filters and actions.

### Changed
- The plugin now has its own **Smart Courier** admin menu (Dashboard, Shipments, Settings). The old settings URL still works.
- Minimum PHP version lowered to 7.4 (tested up to 8.4).
- v1.x settings and orders are migrated automatically without losing any data.

## 1.3.0
- Refined storefront courier line format to `Name · ETA: Price · Rec`.
- Kept courier names compact without unnecessary “Courier” or “Delivery” suffixes.
- Added configurable per-zone ETA.
- Added a sixth optional Custom Courier slot.
- Added configurable Nearby districts and cities/areas.
- Preserved WooCommerce native Free Shipping while keeping courier choices visible.
- Shows the normal courier amount as strike-through reference when Free Shipping applies.
- Removed repeated “Free Delivery” wording from individual courier rows.
- Preserved Safe Test Mode and selected-courier order metadata/admin visibility.
- Added mobile-compact shipping labels.
- Published the actual stable plugin source tree in the repository instead of keeping the implementation only inside a ZIP package.

## 1.2.0
- Stabilized Smart Courier settings and admin workflow.
- Made courier pricing, ETA and tie priority configurable.
- Improved recommendation and Free Shipping presentation.

## 1.1.2
- Fixed Free Shipping compatibility so courier choices remain available when native WooCommerce Free Shipping applies.

## 1.1.1
- Added Safe Test Mode for administrator-only validation before public rollout.

## 1.1.0
- First installable Smart Courier plugin baseline.

## 1.0.0
- Initial WPCode/prototype Smart Courier engine.
