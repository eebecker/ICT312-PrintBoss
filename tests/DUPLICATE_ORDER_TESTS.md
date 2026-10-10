Duplicate order tests
=====================

Use a disposable local database seeded from `database/printboss.sql` and configure the app to use it. These tests add records and temporarily inject an order-save failure using a database trigger. Do not use a shared database. The trigger is removed in a `finally` block; test records remain for inspection.

Install Python Playwright and its Chromium browser, and have the MySQL client available. The database user needs trigger permissions. Tests use the seeded demo account.

Set `PRINTBOSS_TESTING=1`, `PRINTBOSS_TEST_URL` to the local app URL and `PRINTBOSS_TEST_DB_PORT` to the disposable database port. Run `python tests/duplicate_orders_test.py`.

Optional environment variables: `PRINTBOSS_TEST_MYSQL` (client executable), `PRINTBOSS_TEST_DB_HOST` (127.0.0.1), `PRINTBOSS_TEST_DB_USER` (root), `PRINTBOSS_TEST_DB_NAME` (printboss), `PRINTBOSS_TEST_CHROME` (browser executable), `PRINTBOSS_TEST_OUTPUT` (results folder). MySQL uses its normal client credentials configuration.

Checks cover replayed and overlapping submissions, missing or invalid tokens, separate tabs, normal edits and retrying a failed save. For a second PHP server pointing at the same app, database and session storage, set `PRINTBOSS_TEST_SECOND_URL` to its URL to exercise the overlapping requests across two server processes. Exit code 1 means a check failed.

The new-order token is stored in the PHP session and consumed after the database commit. The session lock serializes requests from the same browser session. The most recent 100 form tokens are retained; older forms need reloading. This guards replays of the same form, not separately opened forms or submissions from different sessions. No database migration is needed.
