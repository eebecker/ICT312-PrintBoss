# Changelog

All notable changes to PrintBoss are recorded here. Versions follow semantic versioning.

## v1.1.0 (2026-10-11)

Regression round (pull requests #14 to #20 by Nabil, reviewed by Eduardo).

- Stock: orders can no longer reserve more units than exist; failed saves roll back completely (#14).
- Orders: one-time submission token stops duplicate orders on double-click or refresh (#15).
- Printers: starting a job from an order reserves the order's stock (#16); one job per printer, stale actions rejected (#17).
- Dashboard: quotes and cancelled orders no longer count in revenue, profit or best sellers (#18).
- Invoices: zero GST handled in the preview (#19); each invoice stores its GST rate, migration 001 (#20).
- Payments larger than the balance are rejected; login lockout is per account, migration 002; printer and invoice pages fit 320 px screens (#20).
- Invoice view prints bank details on separate lines.
- Added docs/LOCAL_TESTING.md and eight regression test scripts (138 automated checks in total).

## v1.0.0 (2026-10-09)

First release for ICT312 Assessment 2.

- Accounts: registration, sign in with lockout, password change, account and data deletion, privacy notice.
- Dashboard with monthly KPIs, production and stock alerts, six-month charts.
- Real cost calculator with save as quote and create stock product.
- Filament inventory, stock with movement audit trail.
- Printers with print job lifecycle, live timers and maintenance log.
- Orders pipeline with automatic stock reservation and return.
- Invoices with line items, discount, GST, partial payments and printable tax invoice.
- Customer CRM and business settings.
- Installation and user manuals, automated end-to-end test suite.
