# Automated end-to-end tests

`e2e_test.py` drives a real Chromium browser through the whole PrintBoss workflow (login, customers, calculator, stock, orders, print jobs, invoices, settings, security checks) and reports 40 pass/fail checks. It also saves screenshots to `tests/screenshots/`.

Requirements: Python 3.10+, `pip install playwright` then `playwright install chromium`, a fresh import of `database/printboss.sql`, and the app served at `http://127.0.0.1:8080` (for example `php -S 127.0.0.1:8080` from the project folder, or change `BASE` in the script to your WAMP URL).

Run: `python tests/e2e_test.py`
