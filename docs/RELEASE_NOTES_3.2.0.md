# RAR Woo Smart Courier Pro v3.2.0

**UI, options and functionality clean-up.** Every Smart Courier screen was reviewed against live-site screenshots and re-checked by rendering it at phone and desktop width. Keeps all v3.1.x safety work.

## What changes for your team

- **Orders list:** one **Courier** column shows the courier, status, consignment number, refresh and booking buttons. The separate *Dispatch* column is gone.
- **Order screen:** one **Smart Courier** box. Once a parcel is booked through an API, the courier's update history appears and manual fields move under *Edit manually (override)*.
- **Statuses** read the same everywhere (Booked, In transit, Delivered…); the courier's own text appears underneath as "RedX says: …".
- **Menu and tabs** are identical on every page: Dashboard · Shipments · Book Parcels · API Overview · Rates & Checkout · Courier APIs · Tools & Health.
- **Courier APIs** works on a phone, and badges show **Connected · verified** after a successful test, booking or sync.

## Fixes

- Pathao *Test connection* always asked to save settings and never stored the token.
- Couriers stayed "Configured (test required)" even after successful bookings.
- Estimated delivery dates were counted from the assignment day; older wrong estimates are repaired once on upgrade.
- RedX delivered / undelivered wording, tab-dot colours, a broken "Connect a courier" link, and unstyled pills on Book Parcels.

## Upgrade notes

- Saved courier names still equal to the old presets "Redx" / "Steadfast" become "RedX" / "SteadFast". Custom names are untouched, and orders that stored the old name still resolve.
- Custom code that hooked the removed `rwsc_dispatch` column or the `rwsc-d-courier-dispatch` meta box should use the `rwsc_courier` column / `rwsc-shipment` box.

## Tested

WordPress 7.1.4 + WooCommerce 11.3.0 (SQLite), legacy and HPOS order storage, RAR Courier Suite active and inactive. Integration scenarios cover booking, webhooks, sync, auto-send, permissions, migration, connection verification, the unified cell, menu order, brand-name migration and estimate repair, plus the repository's standalone tests. Courier HTTP responses were mocked, so run one real booking per courier on staging before going live.
