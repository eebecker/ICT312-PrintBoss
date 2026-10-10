Dashboard quote tests
=====================

Run against a disposable local app seeded from `database/printboss.sql`. Tests add orders to the demo and admin businesses, approve and cancel them, and check the rendered dashboard cards, chart data and best sellers. Test records remain for inspection.

Install Python Playwright and Chromium. Set `PRINTBOSS_TESTING=1` and `PRINTBOSS_TEST_URL` to the local app URL, then run `python tests/dashboard_quotes_test.py`.

Optional variables: `PRINTBOSS_TEST_CHROME` for the browser executable and `PRINTBOSS_TEST_OUTPUT` for screenshots and results. Exit code 1 means a check failed.
