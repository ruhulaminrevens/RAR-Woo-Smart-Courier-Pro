=== RAR Woo Smart Courier ===
Contributors: ruhulaminrevens
Tags: woocommerce, shipping, courier, bangladesh, tracking
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.3
Stable tag: 3.1.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

All-in-one courier suite for Bangladesh WooCommerce stores: smart checkout courier choice plus live Pathao, RedX, SteadFast, Paperfly and Sundarban booking, webhooks, status sync, labels, manifests and a courier dashboard.

== Description ==

Customers see one option per courier at checkout (Pathao, Paperfly, Steadfast, Redx, Sundarban or your own couriers). Each option shows a price, a delivery time and an estimated arrival date. The recommended courier is listed first and pre-selected.

Your team gets a Shipments board to book parcels and add tracking numbers, update statuses in bulk, print barcode labels and hand-over manifests, and export CSV. A Courier dashboard shows courier performance, success and return rates, COD still with couriers, and a live rate calculator.

= Checkout =
* Inside Dhaka / Nearby Dhaka / Outside Dhaka zones that understand WooCommerce district codes (BD-13) and English and বাংলা names.
* Nearby districts and areas you choose (Savar, Ashulia, Keraniganj…), matched in the city or address line.
* Weight pricing: up to 1 kg, up to 2 kg, then extra per kg. Weights are converted from g, lbs or oz.
* Delivery time plus an estimated arrival date that respects off-days, holidays and a cut-off time.
* Recommended, Fastest and Best price badges; smart, cheapest or priority recommendation.
* Works with WooCommerce Free shipping, shipping tax, Local pickup and cash on delivery.
* Classic checkout and the Cart/Checkout blocks. Safe Test Mode for rollout.

= Courier API booking (v3.1 hardened) =
* Book with Pathao, RedX, SteadFast and Paperfly from the Orders list (bulk supported); Sundarban by CN number.
* One DB lock per order and one courier per order: no duplicate parcels, even after a timeout.
* Signed Pathao webhooks, token-protected SteadFast webhook, 30-minute status sync, auto-send on order status.
* COD-aware amounts, encrypted API secrets, redacted logs. Replaces the stand-alone RAR Courier Suite plugin.

= Dispatch =
* Shipments board with status tabs, search, filters, inline courier, tracking and status editing, and bulk actions.
* Code 128 parcel labels (A4, 4×6 in, A6), a hand-over manifest and a UTF-8 CSV export.
* A Smart Courier box on the order screen, a Courier column, filters and bulk actions in the order list. Works with HPOS and legacy order storage.

= Customers =
* Tracking email, a delivery card on the thank-you and My Account pages, a Track button, and courier details in order emails.
* `[rwsc_tracking]` tracking page: customers enter their order number and phone number.

Checkout pricing uses the merchant-defined rate table. The separate dispatch engine DOES call supported courier APIs when enabled; Sundarban uses manual CN entry. Live connection must be confirmed for each provider.

== Installation ==

1. Take a full backup of your site (files and database).
2. Plugins → Add New → Upload Plugin → choose `rar-woo-smart-courier-v3.1.2.zip` → Install Now → Activate. When upgrading, choose "Replace current with uploaded".
3. WooCommerce → Settings → Shipping: make sure there is a zone for Bangladesh with a **Flat rate** method (and Free shipping if you use it).
4. Smart Courier → Settings: enter your courier rates and ETAs, and pick your Nearby districts and areas.
5. Keep Safe Test Mode ON and place a test order for an Inside Dhaka, a Nearby and an Outside Dhaka address.
6. Turn Safe Test Mode OFF.

== Frequently Asked Questions ==

= Why do I see my normal Flat rate instead of couriers? =
Either Safe Test Mode is on (only shop managers see couriers) or the address is not in Bangladesh. Smart Courier → Dashboard shows a setup check.

= Does it book parcels with the courier automatically? =
Yes, when the supported provider API is configured. Pathao, RedX, SteadFast and Paperfly booking modules are available but each must be tested with your merchant account; Sundarban remains manual. Auto-send is optional and off by default.

= Will I lose data when I update or uninstall? =
Order meta is kept by this plugin's uninstall handler. Its API credential settings can be deleted if you choose "Delete data on uninstall". Before removing the older Courier Suite, ensure its delete-data option is OFF and verify all imported parcels against the source records.

== Screenshots ==

1. Courier dashboard
2. Shipments board
3. Courier rates
4. Checkout options with badges and arrival dates
5. Parcel labels

== Changelog ==

= 3.1.2 =
* Suite parcel migration now runs after WooCommerce loads, so it can no longer finish empty; restarts once on upgrade.
* SteadFast tracking uses the real tracking code.
* Auto-send and bulk bookings appear on the Shipments board.
* Auto-recommended courier no longer overrides the courier chosen in Settings / at checkout.
* Repeatable "Import from RAR Courier Suite" tool. Tested with WordPress 7.1 and WooCommerce 11.3.

= 3.1.1 =
* Courier APIs and Tools & Health remain available while Suite is active; connection checks are read-only and booking handlers remain disabled.
* Added CI regression for safe dispatch coexistence.

= 3.1.0 =
* Fix Suite settings merge and encrypted webhook secret; resumable multi-page parcel migration and legacy HPOS importer.
* Enforce duplicate-send and uncertain-timeout guard for Pathao native bulk, protect terminal statuses and avoid duplicate customer tracking blocks.
* Add regression tests and build-quality GitHub Actions workflow; live provider verification is still required.

= 3.0.0 =
* RAR Courier Suite merged in: live Pathao / RedX / SteadFast / Paperfly booking, Sundarban manual CN, webhooks, status sync, auto-send.
* Bridge mirrors API parcels into the Shipments board, Dashboard, labels and tracking.
* Automatic import of RAR Courier Suite settings and parcels. See README for the removal steps.

= 2.0.0 =
* Fixed Dhaka/Nearby detection (BD-13 codes), weight unit conversion, tax per courier, pickup handling and rate caching.
* Added the dashboard, Shipments board, tracking, labels, manifest, CSV, delivery dates, badges, block checkout support, HPOS, customer tracking page and email details, auto-assign, and unlimited couriers.

= 1.3.0 =
* Configurable Nearby locations and custom courier, ETA/rate/priority recommendation, and free-shipping presentation.
