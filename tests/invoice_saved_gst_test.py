"""Saved GST rate checks. Use a disposable upgraded local database only."""
import os
import re
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright

if os.environ.get('PRINTBOSS_TESTING') != '1':
    raise SystemExit('Set PRINTBOSS_TESTING=1 and use a disposable seeded app. Tests create invoices and change Settings.')
BASE = os.environ['PRINTBOSS_TEST_URL'].rstrip('/')
OUT = Path(os.environ.get('PRINTBOSS_TEST_OUTPUT', 'saved-gst-results'))
OUT.mkdir(parents=True, exist_ok=True)
PREFIX = 'Saved GST test ' + uuid.uuid4().hex[:8]
results = []


def check(name, ok):
    results.append((name, bool(ok)))
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)


with sync_playwright() as p:
    options = {}
    if os.environ.get('PRINTBOSS_TEST_CHROME'):
        options['executable_path'] = os.environ['PRINTBOSS_TEST_CHROME']
    browser = p.chromium.launch(**options)
    page = browser.new_page(viewport={'width': 1366, 'height': 900})
    page.goto(BASE + '/login.php')
    page.fill('#email', 'demo@printboss.local')
    page.fill('#password', 'Demo1234!')
    page.click('button[type=submit]')
    page.wait_for_url('**/dashboard.php')
    page.goto(BASE + '/settings.php')
    original_rate = page.locator('[name=gst_rate]').input_value()

    def rate(value):
        page.goto(BASE + '/settings.php')
        page.fill('[name=gst_rate]', str(value))
        page.click('button:has-text("Save settings")')
        page.wait_for_load_state('networkidle')

    def invoice(label):
        page.goto(BASE + '/invoice_form.php')
        page.fill('[name=customer_name]', PREFIX + ' ' + label)
        page.fill('[name="item_name[]"]', 'Test item')
        page.fill('[name="item_price[]"]', '100')
        page.check('[name=gst_enabled]')
        page.click('#invoiceForm button[type=submit]')
        page.wait_for_url('**/invoice_view.php?id=*')
        return int(re.search(r'id=(\d+)', page.url).group(1))

    def total():
        return page.locator('.totals .grand span').last.inner_text()

    try:
        rate(10)
        iid = invoice('original')
        check('new invoice shows the rate used to calculate GST', 'GST (10%)' in page.locator('.totals').inner_text() and total() == 'A$110.00')
        rate(15)
        page.goto(BASE + f'/invoice_view.php?id={iid}')
        check('changing Settings leaves the historical GST label and total unchanged', 'GST (10%)' in page.locator('.totals').inner_text() and total() == 'A$110.00')
        page.goto(BASE + f'/invoice_form.php?id={iid}')
        check('edit form uses the saved historical rate', page.locator('#invoiceForm').get_attribute('data-gst') == '10' and page.locator('#s_total').inner_text() == 'A$110.00')
        page.fill('[name=notes]', 'Updated notes')
        page.click('#invoiceForm button[type=submit]')
        page.wait_for_url('**/invoice_view.php?id=*')
        check('saving an edit preserves the historical tax rate and amount', 'GST (10%)' in page.locator('.totals').inner_text() and total() == 'A$110.00')
        page.screenshot(path=str(OUT / 'historical-invoice-after-settings-change.png'), full_page=True)
        invoice('current')
        check('new invoices use the current Settings rate', 'GST (15%)' in page.locator('.totals').inner_text() and total() == 'A$115.00')
        rate(0)
        zero = invoice('zero')
        rate(15)
        page.goto(BASE + f'/invoice_view.php?id={zero}')
        check('stored zero GST is preserved after Settings changes', 'GST (0%)' in page.locator('.totals').inner_text() and total() == 'A$100.00')
        rate(7.5)
        fractional = invoice('fractional')
        rate(15)
        page.goto(BASE + f'/invoice_view.php?id={fractional}')
        check('fractional GST rate is preserved', 'GST (7.5%)' in page.locator('.totals').inner_text() and total() == 'A$107.50')
        # Invoice 1 in the seeded database predates rate snapshots.
        page.goto(BASE + '/invoice_view.php?id=1')
        check('older invoices display the recorded GST without inventing a rate', 'GST (' not in page.locator('.totals').inner_text() and 'GST' in page.locator('.totals').inner_text())
        page.goto(BASE + '/invoice_form.php?id=1')
        check('older invoice edits explain the current-rate recalculation', 'This older invoice has no saved GST rate' in page.content() and page.locator('#invoiceForm').get_attribute('data-gst') == '15')
    finally:
        rate(original_rate)
        browser.close()

(OUT / 'results.txt').write_text('\n'.join(('PASS ' if ok else 'FAIL ') + name for name, ok in results) + '\n', encoding='utf-8')
print(f'{sum(ok for _, ok in results)}/{len(results)} passed')
raise SystemExit(0 if all(ok for _, ok in results) else 1)
