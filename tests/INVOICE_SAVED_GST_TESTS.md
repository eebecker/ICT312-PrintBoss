Saved invoice GST tests
=======================

Use a disposable local app seeded from the updated `database/printboss.sql`. Alternatively, seed a disposable database from the previous schema and apply `database/migrations/001_invoice_gst_rate.sql` once. Seeded invoices keep NULL rates to represent older records. Tests create invoices, change Settings and restore the original GST setting in a `finally` block.

Install Python Playwright and Chromium. Set `PRINTBOSS_TESTING=1` and `PRINTBOSS_TEST_URL` to the app URL, then run `python tests/invoice_saved_gst_test.py`.

Optional variables: `PRINTBOSS_TEST_CHROME` for the browser executable and `PRINTBOSS_TEST_OUTPUT` for screenshots and results. Checks cover historical views and edits, new invoices, zero and fractional rates, and truthful handling of older invoices without a saved rate. Exit code 1 means a check failed.
