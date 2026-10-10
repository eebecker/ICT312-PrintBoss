Invoice GST preview tests
=========================

Use a disposable local app seeded from `database/printboss.sql`. Tests change the demo business GST setting and create invoices. The original GST rate is restored in a `finally` block; invoices remain for inspection.

Install Python Playwright and Chromium. Set `PRINTBOSS_TESTING=1` and `PRINTBOSS_TEST_URL` to the app URL, then run `python tests/invoice_gst_preview_test.py`.

Optional variables: `PRINTBOSS_TEST_CHROME` for the browser executable and `PRINTBOSS_TEST_OUTPUT` for screenshots and results. Checks cover zero, standard and fractional rates, disabled GST, multiple items, discounts and partial payments. Exit code 1 means a check failed.
