# Changelog

## 3.0.1 — 2026-11-14

Stabilisation release after a full audit and integration testing on WordPress 7.1 + WooCommerce 11.3 (legacy and HPOS storage, with and without the Suite active).

### Fixed
- Suite parcels are imported reliably: the one-time backfill now runs on `wp_loaded` (it used to run before WooCommerce registered orders, found nothing and marked itself done). It is paginated and time-boxed.
- API-booked parcels now mirror to the Shipments board with the right tracking value (SteadFast consignment reference) and status; a delivered / returned parcel is never reopened.
- An auto-recommended courier no longer overrides the courier you chose in Settings, and the courier picked at checkout is respected.
- Admin menu order, duplicated front-end block, action-name collisions and stale "Suite" branding strings.
- Fatal `TypeError` when saving settings with empty fields, and Suite settings import merging empty values over saved ones.
- Cron schedule (`rwsc_30min`) is registered before activation scheduling, so status sync starts immediately.

### Added
- "Migrate from RAR Courier Suite" panel in Tools (re-run settings / parcel import on demand).

### Changed
- Uninstall removes settings only when "Delete data on uninstall" is enabled; order meta is never deleted.

## 3.0.0 — 2026-11-14

**RAR Courier Suite is now built in.** Everything the stand-alone Suite did is included, so you can deactivate and delete it. Settings and parcels are imported automatically.

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
