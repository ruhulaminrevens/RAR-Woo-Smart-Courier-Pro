=== RAR Woo Smart Courier ===
Contributors: ruhulaminrevens
Tags: woocommerce, shipping, courier, bangladesh, tracking
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.1
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-courier shipping and dispatch for Bangladesh WooCommerce stores: zone and weight pricing, delivery dates, badges, tracking, labels, manifests and a courier dashboard.

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

= Dispatch =
* Shipments board with status tabs, search, filters, inline courier, tracking and status editing, and bulk actions.
* Code 128 parcel labels (A4, 4×6 in, A6), a hand-over manifest and a UTF-8 CSV export.
* A Smart Courier box on the order screen, a Courier column, filters and bulk actions in the order list. Works with HPOS and legacy order storage.

= Customers =
* Tracking email, a delivery card on the thank-you and My Account pages, a Track button, and courier details in order emails.
* `[rwsc_tracking]` tracking page: customers enter their order number and phone number.

The plugin uses the rates you enter from your courier contracts. It does not call courier APIs.

== Installation ==

1. Take a full backup of your site (files and database).
2. Plugins → Add New → Upload Plugin → choose `rar-woo-smart-courier-v2.0.0.zip` → Install Now → Activate. When upgrading, choose "Replace current with uploaded".
3. WooCommerce → Settings → Shipping: make sure there is a zone for Bangladesh with a **Flat rate** method (and Free shipping if you use it).
4. Smart Courier → Settings: enter your courier rates and ETAs, and pick your Nearby districts and areas.
5. Keep Safe Test Mode ON and place a test order for an Inside Dhaka, a Nearby and an Outside Dhaka address.
6. Turn Safe Test Mode OFF.

== Frequently Asked Questions ==

= Why do I see my normal Flat rate instead of couriers? =
Either Safe Test Mode is on (only shop managers see couriers) or the address is not in Bangladesh. Smart Courier → Dashboard shows a setup check.

= Does it book parcels with the courier automatically? =
No. Book in the courier's merchant panel. The Shipments board makes it quick: "Copy parcel info", CSV export, and Enter-to-save tracking numbers.

= Will I lose data when I update or uninstall? =
No. Settings and order data are kept. v1.x settings and orders are converted automatically.

== Screenshots ==

1. Courier dashboard
2. Shipments board
3. Courier rates
4. Checkout options with badges and arrival dates
5. Parcel labels

== Changelog ==

= 2.0.0 =
* Fixed Dhaka/Nearby detection (BD-13 codes), weight unit conversion, tax per courier, pickup handling and rate caching.
* Added the dashboard, Shipments board, tracking, labels, manifest, CSV, delivery dates, badges, block checkout support, HPOS, customer tracking page and email details, auto-assign, and unlimited couriers.

= 1.3.0 =
* Configurable Nearby locations and custom courier, ETA/rate/priority recommendation, and free-shipping presentation.
