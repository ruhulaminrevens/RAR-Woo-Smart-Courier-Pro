# Installation & Production Checklist (v3.2.0)

## 1. Before you start

- Take a **full backup** of files and database (hosting backup or UpdraftPlus).
- Requirements: WordPress 6.2+, WooCommerce 8.0+, PHP 7.4+.
- If an old WPCode/custom Smart Courier snippet is still active, switch it **OFF**. Two engines must never filter the same rates.

## 2. Install or upgrade

1. WordPress Admin → Plugins → Add New → Upload Plugin.
2. Choose `rar-woo-smart-courier-v3.2.0.zip` → Install Now.
   - **Upgrading from 1.x:** choose **Replace current with uploaded**.
3. Activate. A new **Smart Courier** menu appears (Dashboard · Shipments · Book Parcels · API Overview · Rates & Checkout · Courier APIs · Tools & Health).

What happens when upgrading from 1.x:
- Settings are converted automatically. Nearby district names become WooCommerce codes, and "nearby cities" become "nearby areas". Your rates and ETAs are kept.
- Old orders receive a courier key and shipment status in the background (Completed → Delivered, others → Awaiting booking).
- Nothing is deleted.

## 3. WooCommerce shipping zone

WooCommerce → Settings → Shipping → Add zone:

| Field | Value |
|---|---|
| Zone name | Bangladesh |
| Zone regions | Bangladesh |
| Methods | **Flat rate** (any cost), plus **Free shipping** (e.g. minimum order amount) and **Local pickup** if you use them |

Smart Courier replaces the Flat rate with one option per courier. When Free shipping applies, the couriers become free and the normal price is shown struck through.

If cash on delivery is limited to certain shipping methods, keep **Flat rate** selected in its "Enable for shipping methods" setting. Courier options count as Flat rate.

## 4. Configure

Smart Courier → Rates & Checkout:

1. **Couriers & rates:**
   - Enable your couriers.
   - Enter ≤1 kg, ≤2 kg and extra-per-kg prices for each zone, and the delivery time (ETA).
   - Add the tracking link (`{tracking}`, `{phone}`, `{order}`).
   - Use **＋ Add courier** for more companies.
2. **Zones & weight:**
   - Pick the Nearby districts, and check the Nearby areas inside Dhaka district.
   - Optionally add areas that should always count as "Inside Dhaka".
   - Set the fallback weight for products without a weight.
   - Test addresses in **Test an address**.
3. **Checkout display:** choose the recommendation mode, label style, badges and free-shipping mode.
4. **Delivery dates:** set the weekly off-days (default Friday), the cut-off time and holidays. Add Eid dates when they are announced.
5. **Tracking & automation:**
   - Customer notification and display options.
   - Auto-assign, and which order statuses count as ready.
   - Complete-on-delivery and on-return behaviour.
   - COD methods and sender details for labels.
   - Create the **Track your order** page.

## 5. Test (Safe Test Mode ON)

While testing, only shop managers see courier options.

- [ ] Dhaka address (e.g. Mirpur) → Inside Dhaka prices
- [ ] Dhaka district with "Savar" in the city or address → Nearby prices
- [ ] Gazipur / Narayanganj → Nearby prices
- [ ] Chattogram / Sylhet → Outside Dhaka prices
- [ ] Carts under 1 kg, under 2 kg and over 2 kg; change quantities
- [ ] The Free shipping threshold, with struck-through prices
- [ ] A COD test order → Smart Courier → Shipments shows it under **Ready to book**
- [ ] Add a tracking number (press Enter) → status Booked, and the customer note email arrives
- [ ] Print a label and a manifest, and export CSV
- [ ] The thank-you page and My Account show the Delivery card
- [ ] Check on mobile

## 6. Go live

Smart Courier → Rates & Checkout → Checkout display → turn **Safe Test Mode OFF** → Save.

## Daily workflow

1. **Shipments → Ready to book:** check the courier on each order (or bulk-set it).
2. Book the parcels in the courier's merchant panel. **Copy parcel info** or CSV export helps.
3. Paste each tracking number into its row and press Enter. The parcel is saved as **Booked** and the customer is emailed.
4. Print the labels and the **manifest**. Get the manifest signed at pickup.
5. Update statuses as parcels move: Picked up → In transit → Delivered / Returned. Use bulk actions for many orders.
6. Check the **Dashboard** for delivery success and return rates, parcels waiting to be booked, and COD still with couriers.

## 7. Courier APIs (Pathao, RedX, SteadFast, Paperfly, Sundarban)

1. **Smart Courier → Courier APIs**: open each courier tab, enter credentials, press **Test connection**, save (one Save button covers every tab). Secrets are stored encrypted; you can also define them in `wp-config.php` (`PATHAO_CLIENT_ID`, `PATHAO_CLIENT_SECRET`, `PATHAO_ENVIRONMENT`).
2. Register the webhooks in the courier panels:
   - Pathao: `https://YOUR-SITE/wp-json/ptc/v1/webhook` (secret = your client secret unless you set one).
   - SteadFast: `https://YOUR-SITE/wp-json/ptc/v1/steadfast` with the Bearer token you set under the SteadFast tab.
3. **WooCommerce → Orders**: select orders → *Send with <courier>* (or use the **Dispatch** column / order-screen box). Statuses return by webhook and a 30-minute sync.
4. **Smart Courier → Tools & Health** shows a live health table (environment, locks, cron, last webhook, URLs).

### Replacing the RAR Courier Suite plugin — staged migration

**Do not uninstall Suite before all acceptance checks pass.** If installed alongside Pro, its dispatch module pauses while Suite handles webhooks/cron.

1. Back up plugin files and full DB. Record an export/count of courier API settings and every old consignment/status grouped by provider. Retain a sample of old/new order IDs.
2. Install v3.2.0 on staging. Smart Courier → Courier APIs: ensure credentials were imported and test each configured courier. A displayed credential is not proof of access.
3. Smart Courier → Tools & Health: check Suite parcel migration. Use **Continue parcel migration (up to 300)** until the scan is complete; WP-Cron can continue automatically. This does not automatically prove count parity.
4. If legacy RedX/SteadFast plugins or original Pathao postmeta were used, click their import tool repeatedly for successive 500-ID batches; verify order IDs and status values. With HPOS, use WooCommerce CRUD-based order details, not raw wp_postmeta assumptions.
5. Validate 10+ historical orders spanning all couriers and oldest/newest dates. The Pro Shipments board, order meta, tracking ID/status and WooCommerce order status must match the originals. If counts do not match, keep Suite installed and investigate.
6. Deactivate Suite on staging (do not delete) and verify webhook endpoints, cron scheduling, one controlled API booking per provider, COD/advance payment, bulk duplicate protection, timeout recovery, customer tracking/emails, label and manifest. Paperfly and some RedX behavior may differ by account/endpoint. Sundarban does not book over API.
7. After staging passes, perform low-traffic production cutover with backup and auto-dispatch paused. Re-run connection tests and a small set of controlled real orders. Only after results match and staff accept the process set Suite **Delete data on uninstall = No**, then deactivate/delete Suite.

**Rollback:** If dispatch/checkout fails, stop auto-send, deactivate Pro's dispatch by reactivating Suite, inspect provider merchant panels for possibly-created consignments, and restore the pre-change database only after reconciling any live order activity. Never blindly re-send a timed-out parcel.

**Validation limits:** PHP regression suite and checked ZIP packaging do not substitute for a real WooCommerce (classic/blocks), HPOS on/off, PHP/WordPress version matrix, and provider API webhook tests. Run these before claiming production-ready.

See [2026-11-14 source audit](AUDIT-2026-11-14.md).
