# RAR Woo Smart Courier Pro v3.2.1

**Fix: a courier already named on the order is no longer replaced by an automatic pick.**

## What changes for your team

- When an order without Smart Courier data reaches a "ready" status (for example Processing), Smart Courier first looks at the order's shipping line. If the line names a courier, Smart Courier keeps that courier and marks it **Set by staff**. A name counts if it matches exactly, or appears as a whole word: "SteadFast", "SteadFast Courier", "paperfly" and "RedX Express" all match. Earlier versions always replaced it with the recommended courier.
- Orders whose shipping line names no courier (for example "Inside Dhaka Delivery") still get the recommended courier, as before (*Suggested by Smart Courier*).
- Orders chosen at checkout, couriers set manually on the order screen, and API bookings are unchanged.

## Upgrade notes

- No settings change and no data migration. Existing orders are not touched; the fix applies when an order next reaches a ready status without courier data.

## Tested

Tested on WordPress 7.1.4 + WooCommerce 11.3.0 with orders whose shipping lines were "SteadFast Courier", "paperfly", "RedX Express" and "Inside Dhaka Delivery". The first three kept their courier as *Set by staff*; "Inside Dhaka Delivery" got the recommended courier. The repository's standalone logic, migration, backfill and ownership tests all pass.
