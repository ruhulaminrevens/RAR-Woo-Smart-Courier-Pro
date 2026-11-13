# RAR Woo Smart Courier Pro v3.1.0

Source audit and safety-hardening release following v3.0.0 Courier Suite consolidation.

## What's changed

- Resumable full-catalog historic parcel backfill with visible Tools & Health progress and manual continuation.
- Safe import of settings, encrypted webhook credentials, legacy Pathao token and older RedX/SteadFast/Pathao order meta batches.
- Stronger duplicate/uncertain booking protection for Pathao native bulk and terminal-status synchronization.
- Correct API courier mirror to existing Shipments board without re-quoting historic checkout shipping, and silent historic imports.
- Removed duplicate customer-facing tracking blocks and corrected documentation and connection status.
- Added PHP 7.4/8.2/8.3 source checks and migration/backfill regressions; CI-built and SHA256-checked WordPress-installable ZIP.

## Known limitations before production rollout

**Automated source and test checks passed is not proof of merchant API or live WooCommerce acceptance.** Paperfly and some RedX endpoints depend on provider approval/specification; Sundarban is manual. Before removing stand-alone RAR Courier Suite, perform the [full staging/cutover and rollback checklist](https://github.com/ruhulaminrevens/RAR-Woo-Smart-Courier-Pro/blob/main/docs/INSTALLATION.md), compare source and Pro parcel counts/IDs, verify real provider connectivity, webhooks, HPOS and checkout, then uninstall Suite with **Delete data on uninstall = No**.

Version: 3.1.0. Plugin folder: rar-woo-smart-courier. Do not install the GitHub source-code ZIP; use the attached installable ZIP.
