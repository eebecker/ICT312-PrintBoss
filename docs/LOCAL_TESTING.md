# Local testing checklist (Windows, Laragon or WAMP)

Each step has a command and the result you should see.

## 1. Get the code

```powershell
cd C:\laragon\www          # WAMP: cd C:\wamp64\www
git clone https://github.com/eebecker/ICT312-PrintBoss.git printboss
cd printboss
git log --oneline | Select-Object -First 5
```

Expect the latest commits on `main`. If you already have an old clone: `git fetch origin; git reset --hard origin/main`.

## 2. Check the tools

```powershell
php -v          # PHP 8.1 or newer
mysql --version # MySQL 8 or MariaDB 10.4+
php -m | Select-String "pdo_mysql"
```

`pdo_mysql` must be listed. In WAMP it is on by default; in Laragon too. If `php` or `mysql` are not found in PowerShell, open the terminal from the Laragon window or add `C:\laragon\bin\php\php-8.x` and `C:\laragon\bin\mysql\mysql-8.x\bin` to PATH.

## 3. Create the database

```powershell
mysql -u root < database\printboss.sql
mysql -u root -e "USE printboss; SHOW TABLES; SELECT email FROM users;"
```

Expect 12 tables and two users (`demo@printboss.local`, `admin@printboss.local`). If root has a password: `mysql -u root -p < database\printboss.sql` and set `DB_PASS` in `includes\config.php`.

## 4. Start the app

Option A, through Apache (Laragon or WAMP running, green icon):

```powershell
start http://localhost/printboss/
```

Option B, PHP's built-in server (no Apache needed):

```powershell
php -S 127.0.0.1:8080 -t .
start http://127.0.0.1:8080/
```

Expect the login page. Sign in with `demo@printboss.local` / `Demo1234!` and land on the dashboard with sample numbers.

## 5. Static check

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName } | Select-String -NotMatch "No syntax errors"
```

Expect no output (every file passes).

## 6. Automated end-to-end tests

The suite checks exact quantities, so re-import the database before every run.

```powershell
pip install playwright
playwright install chromium
mysql -u root < database\printboss.sql
$env:PRINTBOSS_URL = "http://localhost/printboss"   # Option A
# $env:PRINTBOSS_URL = "http://127.0.0.1:8080"      # Option B
python tests\e2e_test.py
```

Expect `40/40 checks passed`. Screenshots of every page land in `tests\screenshots\`. A `FAIL` line names the check; the test stops at the first hard error with a Playwright trace in the console.

## 6b. Focused regression suites (optional, about 10 minutes)

Eight extra Playwright scripts cover stock reservation edge cases, duplicate orders, printer job conflicts, GST handling, dashboard totals and login lockout. They inject temporary database failures with triggers and create throwaway accounts, so run them only on this disposable local database. Re-import the SQL before each one.

```powershell
$env:PRINTBOSS_TESTING = "1"
$env:PRINTBOSS_TEST_URL = "http://localhost/printboss"   # same URL as above
$env:PRINTBOSS_TEST_MYSQL = "mysql"
$env:PRINTBOSS_TEST_DB_PORT = "3306"
$env:PRINTBOSS_TEST_DB_NAME = "printboss"
$env:PRINTBOSS_TEST_OUTPUT = "$env:TEMP\printboss-tests"
foreach ($t in "stock_regression","duplicate_orders_test","printer_order_stock_test","printer_job_conflicts_test","dashboard_quotes_test","invoice_gst_preview_test","invoice_saved_gst_test","remaining_issues_test") {
  mysql -u root < database\printboss.sql
  python tests\$t.py
}
```

Expect every script to end with `N/N passed` and exit code 0 (12, 9, 9, 13, 8, 15, 9 and 23 checks).

## 7. Manual smoke test (10 minutes)

| Step | Where | Expect |
|---|---|---|
| Wrong password 5 times | Login | "Too many failed attempts" for 5 minutes |
| Change grams or hours | Cost Calculator | Breakdown and price update without reload |
| Save as quote | Cost Calculator | New row with status Quote in Orders |
| Change status Quote to Approved on an order linked to stock | Orders | Stock quantity drops by the order quantity; movement row appears in Stock |
| Change the same order to Cancelled | Orders | Quantity comes back |
| Start print job, Pause, Resume, Finish, Confirm 3 of 4 | Printers | Status Partially Failed, stock +3, filament roll reduced, printer back to Available |
| Invoice on an order | Orders > Invoice | Form prefilled; totals update live; tax invoice page opens after save |
| Print / PDF | Invoice view | Sidebar hidden, white sheet only |
| Resize window to 390 px | Any page | Sidebar hides behind the menu button |
| Delete account | Settings > Danger zone | Back to login; the demo data is gone (re-import to restore) |

## 8. Reset

```powershell
mysql -u root < database\printboss.sql
```

Drops and recreates everything, demo data included.
