"""End-to-end test of PrintBoss with Playwright. Also captures screenshots for the documentation."""
import os, re, sys
from playwright.sync_api import sync_playwright, expect

BASE = "http://127.0.0.1:8080"
SHOTS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "screenshots")
os.makedirs(SHOTS, exist_ok=True)
results = []

def check(name, cond):
    results.append((name, bool(cond)))
    print(("PASS " if cond else "FAIL ") + name)

def pick(page, sel, text):
    val = page.locator(f"{sel} option", has_text=text).first.get_attribute("value")
    page.select_option(sel, val)

def shot(page, name):
    page.screenshot(path=f"{SHOTS}/{name}.png", full_page=False)

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1366, "height": 800})
    page.on("pageerror", lambda e: print("JS ERROR:", e))
    page.on("console", lambda m: print("CONSOLE:", m.text) if m.type == "error" else None)

    # ---- login ----
    page.goto(f"{BASE}/login.php")
    shot(page, "01_login")
    page.fill("#email", "demo@printboss.local")
    page.fill("#password", "wrongpass")
    with page.expect_navigation():
        page.click("button[type=submit]")
    check("wrong password rejected", "Incorrect email" in page.content())
    page.fill("#email", "demo@printboss.local")
    page.fill("#password", "Demo1234!")
    with page.expect_navigation():
        page.click("button[type=submit]")
    page.wait_for_url("**/dashboard.php")
    check("login redirects to dashboard", "Operational Dashboard" in page.content())
    page.wait_for_timeout(300)
    shot(page, "02_dashboard")

    # ---- customers ----
    page.goto(f"{BASE}/customers.php")
    page.click("button[data-open=customerDialog]")
    page.fill("#customerDialog [name=name]", "E2E Test Customer")
    page.fill("#customerDialog [name=email]", "e2e@example.com")
    page.fill("#customerDialog [name=city]", "Sydney")
    page.select_option("#customerDialog [name=state]", "NSW")
    page.fill("#customerDialog [name=postcode]", "2000")
    with page.expect_navigation():
        page.click("#customerDialog button[type=submit]")
    check("customer created", "Customer added" in page.content() and "E2E Test Customer" in page.content())
    shot(page, "03_customers")
    # edit
    row = page.locator("tr", has_text="E2E Test Customer")
    row.locator("button", has_text="Edit").click()
    expect(page.locator("#customerDialog [name=name]")).to_have_value("E2E Test Customer")
    page.fill("#customerDialog [name=phone]", "0400 000 000")
    with page.expect_navigation():
        page.click("#customerDialog button[type=submit]")
    check("customer edited", "0400 000 000" in page.content())

    # ---- calculator ----
    page.goto(f"{BASE}/calculator.php")
    page.fill("#product_name", "E2E Phone Stand")
    page.fill("#grams_used", "50")
    page.fill("#print_time_hours", "2")
    page.wait_for_timeout(200)
    total = page.locator("#r_total").inner_text()
    price = page.locator("#r_price").inner_text()
    check("calculator live totals rendered", total.startswith("A$") and price.startswith("A$"))
    shot(page, "04_calculator")
    # expected: material 50/1000*25=1.25, elec 0.2*2*0.3=0.12, dep 2*0.5=1, labour .5*25=12.5, pack 1 => 15.87 *1.1 = 17.457 ; price = 17.457/0.5=34.914
    check("calculator maths correct", total == "A$17.46" and price == "A$34.91")
    page.select_option("#customer_id", label="E2E Test Customer")
    with page.expect_navigation():
        page.click("button[value=save_quote]")
    page.wait_for_url("**/orders.php")
    check("quote saved to orders", "E2E Phone Stand" in page.content() and "Saved as a new quote" in page.content())
    shot(page, "05_orders")

    # create stock product from calculator
    page.goto(f"{BASE}/calculator.php")
    page.fill("#product_name", "E2E Stock Widget")
    page.click("button[data-open=stockDialog]")
    page.fill("#category", "Test")
    with page.expect_navigation():
        page.click("#stockDialog button[value=create_stock]")
    page.wait_for_url("**/stock.php")
    check("stock product created from calculator", "E2E Stock Widget" in page.content())
    # duplicate detection
    page.goto(f"{BASE}/calculator.php")
    page.fill("#product_name", "E2E Stock Widget")
    page.click("button[data-open=stockDialog]")
    with page.expect_navigation():
        page.click("#stockDialog button[value=create_stock]")
    check("duplicate stock product detected", "already exists" in page.content())

    # ---- stock adjustments ----
    page.goto(f"{BASE}/stock.php")
    row = page.locator("#stockTable tr", has_text="E2E Stock Widget")
    row.locator("button[title='Add stock']").click()
    page.fill("#addDialog [name=quantity]", "10")
    with page.expect_navigation():
        page.click("#addDialog button[type=submit]")
    row = page.locator("#stockTable tr", has_text="E2E Stock Widget")
    check("stock added (10)", "Added 10 units" in page.content() and "In Stock" in row.inner_text())
    row.locator("button[title='Reduce stock']").click()
    page.fill("#reduceDialog [name=quantity]", "50")
    with page.expect_navigation():
        page.click("#reduceDialog button[type=submit]")
    check("over-reduce blocked", "Cannot reduce more" in page.content())
    row = page.locator("#stockTable tr", has_text="E2E Stock Widget")
    row.locator("button[title='Reduce stock']").click()
    page.fill("#reduceDialog [name=quantity]", "3")
    with page.expect_navigation():
        page.click("#reduceDialog button[type=submit]")
    check("stock reduced to 7", "Removed 3 units" in page.content())
    check("movement audit trail", page.locator("tr", has_text="Stock Reduced").count() >= 1)
    shot(page, "06_stock")

    # ---- order with stock reservation ----
    page.goto(f"{BASE}/orders.php")
    page.click("button[data-open=orderDialog]")
    page.select_option("#orderDialog [name=customer_id]", label="E2E Test Customer")
    pick(page, "#orderDialog [name=stock_item_id]", "E2E Stock Widget")
    page.fill("#orderDialog [name=order_quantity]", "2")
    page.select_option("#orderDialog [name=status]", "Approved")
    page.fill("#orderDialog [name=unit_selling_price]", "30")
    page.fill("#orderDialog [name=unit_cost]", "10")
    page.wait_for_timeout(100)
    check("order totals live", page.locator("#t_profit").inner_text() == "A$40.00")
    with page.expect_navigation():
        page.click("#orderDialog button[type=submit]")
    check("order created", "Order created" in page.content())
    page.goto(f"{BASE}/stock.php")
    row = page.locator("#stockTable tr", has_text="E2E Stock Widget")
    check("order reserved stock (7 -> 5)", re.search(r"\b5\b", row.locator("td").nth(2).inner_text()) is not None)
    # cancel order returns stock
    page.goto(f"{BASE}/orders.php")
    row = page.locator("#orderTable tr", has_text="E2E Stock Widget").first
    row.locator("select[name=status]").select_option("Cancelled")
    page.wait_for_load_state("networkidle")
    page.goto(f"{BASE}/stock.php")
    row = page.locator("#stockTable tr", has_text="E2E Stock Widget")
    check("cancelled order returned stock (5 -> 7)", "7" in row.locator("td").nth(2).inner_text())

    # ---- printers & jobs ----
    page.goto(f"{BASE}/printers.php")
    shot(page, "07_printers")
    card = page.locator(".printer-card", has_text="P2S Main")
    card.locator("button", has_text="Start print job").click()
    page.select_option("#jobDialog [name=source_type]", "stock")
    pick(page, "#jobDialog [name=stock_item_id]", "E2E Stock Widget")
    page.fill("#jobDialog [name=planned_quantity]", "4")
    page.fill("#jobDialog [name=estimated_duration_minutes]", "90")
    page.fill("#jobDialog [name=estimated_material_quantity]", "80")
    page.select_option("#jobDialog [name=inventory_id]", index=1)
    with page.expect_navigation():
        page.click("#jobDialog button[type=submit]")
    check("print job started", "Print job started" in page.content())
    card = page.locator(".printer-card", has_text="P2S Main")
    check("printer shows Printing + timer", "Printing" in card.inner_text() and card.locator("[data-timer]").count() == 1)
    shot(page, "08_printer_running")
    with page.expect_navigation():
        card.locator("button", has_text="Pause").click()
    card = page.locator(".printer-card", has_text="P2S Main")
    check("job paused", "Paused" in card.inner_text())
    with page.expect_navigation():
        card.locator("button", has_text="Resume").click()
    card = page.locator(".printer-card", has_text="P2S Main")
    with page.expect_navigation():
        card.locator("button", has_text="Finish").click()
    card = page.locator(".printer-card", has_text="P2S Main")
    check("job awaiting confirmation", "Awaiting Confirmation" in card.inner_text())
    card.locator("button", has_text="Confirm result").click()
    page.fill("#confirmDialog [name=successful_quantity]", "3")
    page.fill("#confirmDialog [name=wasted_material]", "20")
    page.fill("#confirmDialog [name=failure_reason]", "Warping")
    with page.expect_navigation():
        page.click("#confirmDialog button[type=submit]")
    check("job confirmed partially failed", "3 successful, 1 failed" in page.content() and "Partially Failed" in page.content())
    page.goto(f"{BASE}/stock.php")
    row = page.locator("#stockTable tr", has_text="E2E Stock Widget")
    check("successful units added to stock (7 -> 10)", "10" in row.locator("td").nth(2).inner_text())
    check("production movement logged", page.locator("tr", has_text="Production Completed").count() >= 1)
    page.goto(f"{BASE}/inventory.php")
    check("filament deducted", "720 g of 1000 g" in page.content())  # PETG 820 - 80 - 20
    shot(page, "09_inventory")

    # maintenance log
    page.goto(f"{BASE}/printers.php")
    page.click("button[data-open=maintDialog]")
    page.select_option("#maintDialog [name=printer_id]", label="Resin Rig")
    page.select_option("#maintDialog [name=maintenance_type]", "Other")
    page.fill("#maintDialog [name=cost]", "25")
    page.fill("#maintDialog [name=notes]", "FEP replaced")
    with page.expect_navigation():
        page.click("#maintDialog button[type=submit]")
    check("maintenance logged and printer back to Available", "Maintenance logged" in page.content() and "Available" in page.locator(".printer-card", has_text="Resin Rig").inner_text())

    # ---- invoice from order ----
    page.goto(f"{BASE}/orders.php")
    row = page.locator("tr", has_text="E2E Phone Stand").first
    row.locator("a", has_text="Invoice").click()
    page.wait_for_url("**/invoice_form.php?order=*")
    check("invoice prefilled from order", page.locator("[name=customer_name]").input_value() == "E2E Test Customer")
    page.click("#addLine")
    rows = page.locator("tr.line")
    rows.nth(1).locator("[name='item_name[]']").fill("Shipping")
    rows.nth(1).locator("[name='item_price[]']").fill("9.50")
    page.wait_for_timeout(100)
    shot(page, "10_invoice_form")
    with page.expect_navigation():
        page.click("#invoiceForm button[type=submit]")
    page.wait_for_url("**/invoice_view.php?id=*")
    html = page.content()
    check("invoice saved and viewable", "TAX INVOICE" in html and "INV-0005" in html)
    # subtotal 34.91 + 9.50 = 44.41, gst 4.44, total 48.85
    check("invoice GST maths", "A$44.41" in html and "A$4.44" in html and "A$48.85" in html)
    shot(page, "11_invoice_view")
    page.goto(f"{BASE}/invoices.php")
    row = page.locator("tr", has_text="INV-0005")
    row.locator("button", has_text="Payment").click()
    page.fill("#paymentDialog [name=amount]", "20")
    with page.expect_navigation():
        page.click("#paymentDialog button[type=submit]")
    row = page.locator("tr", has_text="INV-0005")
    check("partial payment recorded (balance 28.85)", "A$28.85" in row.inner_text())
    with page.expect_navigation():
        row.locator("button", has_text="Mark sent").click()
    row = page.locator("tr", has_text="INV-0005")
    check("invoice marked sent", "Sent" in row.inner_text())
    with page.expect_navigation():
        row.locator("button", has_text="Mark paid").click()
    row = page.locator("tr", has_text="INV-0005")
    check("invoice marked paid", "Paid" in row.inner_text() and "A$0.00" in row.inner_text())
    shot(page, "12_invoices")

    # ---- settings ----
    page.goto(f"{BASE}/settings.php")
    page.fill("[name=business_phone]", "0411 999 888")
    with page.expect_navigation():
        page.click("button:has-text('Save settings')")
    check("settings saved", "Settings saved" in page.content() and page.locator("[name=business_phone]").input_value() == "0411 999 888")
    shot(page, "13_settings")

    # ---- security checks ----
    page.goto(f"{BASE}/invoice_view.php?id=999")
    check("foreign/missing invoice blocked", "Invoice not found" in page.content())
    resp = page.request.post(f"{BASE}/customers.php", form={"action": "delete", "id": "1"})
    check("CSRF: post without token rejected (403)", resp.status == 403)
    # XSS: name with script tag rendered escaped
    page.goto(f"{BASE}/customers.php")
    page.click("button[data-open=customerDialog]")
    page.fill("#customerDialog [name=name]", "<script>alert(1)</script>")
    with page.expect_navigation():
        page.click("#customerDialog button[type=submit]")
    check("XSS escaped", "&lt;script&gt;" in page.content() and "<script>alert(1)</script>" not in page.content())
    # SQL injection attempt in login
    page.goto(f"{BASE}/logout.php")
    page.goto(f"{BASE}/login.php")
    page.fill("#email", "' OR 1=1 --")
    page.fill("#password", "x")
    with page.expect_navigation():
        page.click("button[type=submit]")
    check("SQL injection login attempt rejected", "Incorrect email" in page.content())
    # register
    page.goto(f"{BASE}/register.php")
    page.fill("#full_name", "New Maker")
    page.fill("#email", "newmaker@example.com")
    page.fill("#password", "Maker2026")
    page.fill("#password_confirm", "Maker2026")
    page.check("[name=privacy_consent]")
    with page.expect_navigation():
        page.click("button[type=submit]")
    page.wait_for_url("**/settings.php?welcome=1")
    check("registration works", "Welcome" in page.content())
    page.goto(f"{BASE}/customers.php")
    check("new user sees no other user's data", "E2E Test Customer" not in page.content())
    # unauthenticated access
    page.goto(f"{BASE}/logout.php")
    page.goto(f"{BASE}/dashboard.php")
    check("unauthenticated access redirects to login", page.url.endswith("login.php"))

    browser.close()

fails = [n for n, ok in results if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
if fails:
    print("FAILED:", fails)
    sys.exit(1)
