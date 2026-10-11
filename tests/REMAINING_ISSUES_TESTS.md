Run these checks only on a disposable local database seeded from `database/printboss.sql`, with the updated app serving it. The tests create invoices and a temporary login account, then remove the temporary account. Do not point them at a shared installation.

Install Python Playwright and Chromium. Set `PRINTBOSS_TESTING=1`, `PRINTBOSS_TEST_URL`, `PRINTBOSS_TEST_MYSQL` (MySQL client path), `PRINTBOSS_TEST_DB_PORT`, and `PRINTBOSS_TEST_DB_NAME`. Run `python tests/remaining_issues_test.py`.

Optional variables: `PRINTBOSS_TEST_CHROME` and `PRINTBOSS_TEST_OUTPUT`. The 23 checks cover rejected overpayments, unchanged rejected records, partial and exact payments, fresh-cookie login failures, lock expiry and reset, and printer/invoice layouts at 320px, 390px and desktop widths. Screenshots and a text result file are written to the output directory. Exit code 1 means a check failed.

Run `python tests/invoice_saved_gst_test.py` separately to check saved invoice rates. Existing installations need both SQL migrations described in `docs/INSTALL.md` before testing.
