# PrintBoss

**3D printing business manager** built for ICT312 Advanced Web Information Systems (Assignment 02, Wentworth Institute of Higher Education).

Group: Valerio Pinheiro (team leader), Eduardo Becker, Nabil.

PrintBoss helps a small 3D printing business work out the real cost of a print, quote it, track filament and finished stock, run print jobs on each printer, manage orders and customers, and issue GST invoices.

## Stack

HTML5, CSS3, vanilla JavaScript, PHP 8 (PDO) and MySQL. No frameworks, no build step, no external CDNs. Runs on WAMP, XAMPP or any Apache/PHP/MySQL host.

## Quick start (WAMP)

1. Copy this folder to `C:\wamp64\www\printboss`.
2. Open phpMyAdmin (`http://localhost/phpmyadmin`), go to **Import** and import `database/printboss.sql`. It creates the `printboss` database, all 12 tables and demo data.
3. If your MySQL root user has a password, set it in `includes/config.php`.
4. Open `http://localhost/printboss/`.
5. Sign in with the demo account: `demo@printboss.local` / `Demo1234!` (or create your own account).

Full installation and user manuals are in the `docs/` folder.

## Folder structure

```
printboss/
  index.php             entry point (redirects to login or dashboard)
  login.php             sign in (bcrypt, lockout after 5 failures)
  register.php          create account
  logout.php
  privacy.php           privacy notice
  dashboard.php         KPIs, 6-month charts, alerts
  calculator.php        real cost calculator -> quote / stock product
  inventory.php         filament rolls
  stock.php             finished products + movement audit trail
  printers.php          printers, print jobs, maintenance
  orders.php            quotes and orders with stock reservation
  invoices.php          invoice list, payments
  invoice_form.php      create / edit invoice with line items and GST
  invoice_view.php      printable tax invoice
  settings.php          business profile, defaults, password, delete account
  includes/             config, db (PDO), auth (sessions, CSRF), functions, layout
  assets/css/style.css  CSS3 theme
  assets/js/*.js        app.js (dialogs, filters), calculator.js, dashboard.js, printers.js, orders.js, invoice.js
  database/printboss.sql  schema + demo data
  docs/                 installation manual, user manual, test report
```

## Security features

- Passwords hashed with bcrypt (`password_hash`), never stored in plain text.
- Every SQL statement is a PDO prepared statement.
- Every form carries a CSRF token checked on the server.
- Every output is escaped with `htmlspecialchars`.
- Every query is scoped to the signed-in `user_id`, so one business can never see another's data.
- HttpOnly, SameSite session cookies; session ID regenerated on login; 8 hour idle timeout.
- Login lockout for 5 minutes after 5 failed attempts.
- Users can delete their account and all their data (privacy by design, Australian Privacy Principles).

## Licence

Academic project. Icons and name carried over from the group's Assignment 01 prototype.
