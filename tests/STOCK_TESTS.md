Stock regression tests
======================

Use a disposable local database loaded from `database/printboss.sql`, with the app configured to use that same database. Do not run this against a shared or live database: it creates test products and orders and temporarily injects database failures. The failure triggers are removed in `finally` blocks; test records are left for inspection.

Install Python Playwright (`pip install playwright`, then `playwright install chromium`) and make sure the MySQL client is available. The database user needs permission to create and drop triggers. Authentication uses the seeded demo account.

Set these environment variables before running `python tests/stock_regression.py`:

- `PRINTBOSS_TESTING=1`
- `PRINTBOSS_TEST_URL`: local app URL, for example `http://127.0.0.1:8080`
- `PRINTBOSS_TEST_DB_PORT`: port of the disposable database

Optional settings: `PRINTBOSS_TEST_MYSQL` (mysql executable), `PRINTBOSS_TEST_DB_HOST` (default 127.0.0.1), `PRINTBOSS_TEST_DB_USER` (default root), `PRINTBOSS_TEST_DB_NAME` (default printboss), `PRINTBOSS_TEST_CHROME` (browser executable), and `PRINTBOSS_TEST_OUTPUT` (results and screenshot folder). The client can use its normal MySQL credentials configuration.

The checks cover insufficient stock, order edits and product changes, cancellation, manual adjustments, and rollback when movement logging or order deletion fails. Exit code 1 means a check failed.
