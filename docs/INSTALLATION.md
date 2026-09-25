# Installation & Production Checklist (v2.0.0)

## 1. Before you start

- Take a **full backup** of files and database (hosting backup or UpdraftPlus).
- Requirements: WordPress 6.2+, WooCommerce 8.0+, PHP 7.4+.
- If an old WPCode/custom Smart Courier snippet is still active, switch it **OFF**. Two engines must never filter the same rates.

## 2. Install or upgrade

1. WordPress Admin → Plugins → Add New → Upload Plugin.
2. Choose `rar-woo-smart-courier-v2.0.0.zip` → Install Now.
   - **Upgrading from 1.x:** choose **Replace current with uploaded**.
3. Activate. A new **Smart Courier** menu appears (Dashboard · Shipments · Settings).

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

Smart Courier → Settings:

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

Smart Courier → Settings → Checkout display → turn **Safe Test Mode OFF** → Save.

## Daily workflow

1. **Shipments → Ready to book:** check the courier on each order (or bulk-set it).
2. Book the parcels in the courier's merchant panel. **Copy parcel info** or CSV export helps.
3. Paste each tracking number into its row and press Enter. The parcel is saved as **Booked** and the customer is emailed.
4. Print the labels and the **manifest**. Get the manifest signed at pickup.
5. Update statuses as parcels move: Picked up → In transit → Delivered / Returned. Use bulk actions for many orders.
6. Check the **Dashboard** for delivery success and return rates, parcels waiting to be booked, and COD still with couriers.
