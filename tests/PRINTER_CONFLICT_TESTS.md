Printer job conflict tests
==========================

Run only against a disposable database seeded from `database/printboss.sql`, with the app using the same database. Tests create printers and jobs, recreate old conflicting job records and inject temporary database failures. Triggers are removed in `finally` blocks; normal test records remain for inspection.

Install Python Playwright and Chromium, and have a MySQL client available. The database user needs trigger permissions. Tests use the seeded demo account.

Set `PRINTBOSS_TESTING=1`, `PRINTBOSS_TEST_URL` to the app URL and `PRINTBOSS_TEST_DB_PORT` to the isolated database port. Run `python tests/printer_job_conflicts_test.py`.

Optional settings: `PRINTBOSS_TEST_MYSQL` (client executable), `PRINTBOSS_TEST_DB_HOST` (127.0.0.1), `PRINTBOSS_TEST_DB_USER` (root), `PRINTBOSS_TEST_DB_NAME` (printboss), `PRINTBOSS_TEST_CHROME` (browser executable), `PRINTBOSS_TEST_OUTPUT` (results folder). Credentials use normal MySQL client configuration.

Checks cover conflicting starts and schedules, stale job actions, normal cancellation and rollback. Exit code 1 means a check failed. Existing orphan jobs are protected from taking over a printer; this change does not automatically clean up old conflicting records.
