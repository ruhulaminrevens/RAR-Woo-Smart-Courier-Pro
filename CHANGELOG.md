# Changelog

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
