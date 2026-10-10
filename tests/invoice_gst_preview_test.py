"""Check live invoice totals against saved invoices in a disposable local app."""
import os
import re
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright

if os.environ.get('PRINTBOSS_TESTING') != '1':
    raise SystemExit('Set PRINTBOSS_TESTING=1 and use a disposable app. Tests change GST settings and create invoices.')
BASE = os.environ['PRINTBOSS_TEST_URL'].rstrip('/')
OUT = Path(os.environ.get('PRINTBOSS_TEST_OUTPUT', 'gst-preview-results'))
OUT.mkdir(parents=True, exist_ok=True)
PREFIX = 'GST test ' + uuid.uuid4().hex[:8]
results = []


def check(name, ok):
    results.append((name, bool(ok)))
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)


def amount(value):
    return float(re.sub(r'[^0-9.\-]', '', value))


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

    cases = [('zero', 0, True, False, 100, 0, 100),
             ('standard', 10, True, False, 110, 10, 110),
             ('fractional', 7.5, True, False, 107.5, 7.5, 107.5),
             ('disabled', 10, False, False, 100, 0, 100),
             ('zero with discount and payment', 0, True, True, 100, 0, 75)]
    try:
        for label, gst_rate, enabled, extra, total, gst, balance in cases:
            rate(gst_rate)
            page.goto(BASE + '/invoice_form.php')
            page.fill('[name=customer_name]', PREFIX + ' ' + label)
            page.fill('[name="item_name[]"]', 'Test item')
            page.fill('[name="item_price[]"]', '50' if extra else '100')
            page.set_checked('[name=gst_enabled]', enabled)
            if extra:
                page.fill('[name="item_qty[]"]', '2')
                page.click('#addLine')
                page.locator('[name="item_name[]"]').nth(1).fill('Second item')
                page.locator('[name="item_price[]"]').nth(1).fill('20')
                page.fill('[name=discount]', '20')
                page.fill('[name=amount_paid]', '25')
            preview_total = amount(page.locator('#s_total').inner_text())
            preview_gst = amount(page.locator('#s_gst').inner_text())
            preview_balance = amount(page.locator('#s_balance').inner_text())
            check(label + ': preview total, GST and balance are correct', preview_total == total and preview_gst == gst and preview_balance == balance)
            if label == 'zero':
                page.screenshot(path=str(OUT / 'zero-gst-preview.png'), full_page=True)
            page.click('#invoiceForm button[type=submit]')
            page.wait_for_url('**/invoice_view.php?id=*')
            saved = amount(page.locator('.totals .grand span').last.inner_text())
            check(label + ': saved total matches the preview', saved == preview_total)
            if enabled:
                saved_gst = amount(page.locator('.totals > div').filter(has_text='GST (').locator('span').last.inner_text())
                matches = saved_gst == preview_gst
            else:
                matches = page.locator('.totals > div').filter(has_text='GST (').count() == 0
            if extra:
                matches = matches and amount(page.locator('.totals > div').filter(has_text='Balance due').locator('span').last.inner_text()) == preview_balance
            check(label + ': saved GST and payment balance match the preview', matches)
            if label == 'zero':
                page.screenshot(path=str(OUT / 'zero-gst-saved-invoice.png'), full_page=True)
    finally:
        rate(original_rate)
        browser.close()

(OUT / 'results.txt').write_text('\n'.join(('PASS ' if ok else 'FAIL ') + name for name, ok in results) + '\n', encoding='utf-8')
print(f'{sum(ok for _, ok in results)}/{len(results)} passed')
raise SystemExit(0 if all(ok for _, ok in results) else 1)
