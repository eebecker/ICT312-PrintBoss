# Installation manual

This manual is for you if you'll run PrintBoss on your own Windows computer. You don't need any programming knowledge. The whole job takes about ten minutes.

## 11.1 What you need

- Windows 10 or 11 with at least 2 GB of free disk space.
- WAMP Server 3.3 or newer (free, from wampserver.com). XAMPP works the same way; the folder names differ as noted below.
- The PrintBoss zip file from the submission or a download of the GitHub repository.
- A modern browser: Chrome, Edge or Firefox.

## 11.2 Step 1: install WAMP

1. Download WAMP Server 64-bit and run the installer with the default options. If the installer asks for Visual C++ redistributables, install them from the link it shows.
2. Start WAMP from the Start menu. The icon in the system tray turns green when Apache and MySQL are running. If it stays orange, another program (often Skype or IIS) is using port 80; use the WAMP tray menu to change Apache's port or close that program.
3. Open `http://localhost/` in your browser. You should see the WAMP home page.

## 11.3 Step 2: copy the application

1. Unzip `PrintBoss.zip`. You get a folder named `printboss`.
2. Copy that folder to `C:\wamp64\www\`. The result must be `C:\wamp64\www\printboss\index.php`. For XAMPP use `C:\xampp\htdocs\printboss`.

## 11.4 Step 3: create the database

1. Open `http://localhost/phpmyadmin/` and sign in (WAMP default: user `root`, no password).
2. Click **Import** in the top menu.
3. Click **Choose File** and select `C:\wamp64\www\printboss\database\printboss.sql`.
4. Leave every option as it is and click **Import** (at the bottom; in newer versions the button is called **Go**).
5. After a few seconds phpMyAdmin shows "Import has been successfully finished" and a new database `printboss` with 12 tables appears in the left panel.

Command-line alternative: open a terminal in `C:\wamp64\bin\mysql\mysql8.x.x\bin` and run `mysql -u root < C:\wamp64\www\printboss\database\printboss.sql`.

## 11.5 Step 4: check the configuration

Open `C:\wamp64\www\printboss\includes\config.php` in Notepad. The defaults match a standard WAMP install:

```
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'printboss');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Change `DB_PASS` only if you gave MySQL's root user a password. Save and close.

## 11.6 Step 5: open PrintBoss

1. Go to `http://localhost/printboss/`.
2. Sign in with the demo account: email `demo@printboss.local`, password `Demo1234!`. The demo account comes with sample customers, filament, stock, printers, orders and invoices so you can explore.
3. To start with your own business, click **Create an account** instead, then fill in **Settings**.

## 11.7 Troubleshooting

**Common installation problems**

| Symptom | Cause | Fix |
|---|---|---|
| "Database connection failed" page | MySQL not running, SQL not imported, or wrong password | Check the WAMP icon is green; repeat step 3; check `DB_PASS` |
| Page shows PHP code as text | Folder outside `www`, or Apache not running | Move the folder; start WAMP |
| 403 Forbidden on localhost | WAMP 3 blocks non-local access by default | Access from the same machine, or edit `httpd-vhosts.conf` to allow your network |
| "Invalid or missing security token" | Form left open for more than 8 hours or cookies blocked | Reload the page and submit again; allow cookies for localhost |
| Login locked | Five wrong passwords | Wait five minutes |
| Styles missing | Wrong folder name in the URL | Use `http://localhost/printboss/` with the folder name exactly as copied |

## 11.8 Upgrading an existing installation

For an existing installation upgrading to saved invoice GST rates, back up the database and run `database/migrations/001_invoice_gst_rate.sql` once against the existing `printboss` database before using the updated app. Fresh installations already include the column. Do not reimport `database/printboss.sql` to upgrade: that file replaces the database and its data. Older invoices retain their recorded amounts and display GST without an assumed percentage. Editing and saving one of these older invoices recalculates GST at the current Settings rate; the edit form explains this.

## 11.9 Uninstall

Delete `C:\wamp64\www\printboss` and drop the `printboss` database in phpMyAdmin. PrintBoss writes nothing else to the computer.
