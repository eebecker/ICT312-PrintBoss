"""Dashboard quote checks. Use a disposable seeded local app only."""
import json
import os
import re
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright

if os.environ.get('PRINTBOSS_TESTING') != '1':
    raise SystemExit('Set PRINTBOSS_TESTING=1 and use a disposable app. These tests create orders in the seeded demo accounts.')
BASE = os.environ['PRINTBOSS_TEST_URL'].rstrip('/')
PREFIX = 'Dashboard quote test ' + uuid.uuid4().hex[:8]
OUT = Path(os.environ.get('PRINTBOSS_TEST_OUTPUT', 'dashboard-quote-results'))
OUT.mkdir(parents=True, exist_ok=True)
results = []


def check(name, ok):
    results.append((name, bool(ok)))
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)


def money(text):
    return float(re.sub(r'[^0-9.\-]', '', text))


with sync_playwright() as p:
    options = {}
    if os.environ.get('PRINTBOSS_TEST_CHROME'):
        options['executable_path'] = os.environ['PRINTBOSS_TEST_CHROME']
    browser = p.chromium.launch(**options)
    context = browser.new_context(viewport={'width': 1366, 'height': 900})
    page = context.new_page()

    def login(target, email):
        target.goto(BASE + '/login.php')
        target.fill('#email', email)
        target.fill('#password', 'Demo1234!')
        target.click('button[type=submit]')
        target.wait_for_url('**/dashboard.php')

    def post(target, **data):
        target.goto(BASE + '/orders.php')
        data['csrf_token'] = target.locator('input[name=csrf_token]').first.input_value()
        if data['action'] == 'save' and not data.get('id'):
            data['submission_token'] = target.locator('#orderForm [name=submission_token]').input_value()
        response = target.request.post(BASE + '/orders.php', form=data)
        assert response.status == 200

    def snapshot():
        page.goto(BASE + '/dashboard.php')
        return dict(value=money(page.locator('.stat.accent .stat-value').inner_text()),
                    profit=money(page.locator('.stat.accent .stat-sub').inner_text()),
                    margin=page.locator('.stat').nth(2).locator('.stat-value').inner_text(),
                    best=page.locator('ul.list').first.inner_text(),
                    series=json.loads(page.locator('#revenueChart').get_attribute('data-series')))

    login(page, 'demo@printboss.local')
    before = snapshot()
    check('labels explain that totals are accepted order values', 'Accepted order value this month' in page.content() and 'Estimated profit' in page.content() and 'Accepted orders per month' in page.content())
    post(page, action='save', product_name=PREFIX, customer_name='Test buyer', order_quantity='10000', status='Quote', unit_cost='60', unit_selling_price='100')
    check('large quote does not change value, profit, margin, best sellers or charts', snapshot() == before)
    page.goto(BASE + '/orders.php')
    row = page.locator('#orderTable tr', has_text=PREFIX).first
    oid = row.locator('input[name=id]').first.input_value()
    check('excluded quote is still visible in the orders list', row.locator('select[name=status]').input_value() == 'Quote')

    post(page, action='status', id=oid, status='Approved')
    approved = snapshot()
    last = approved['series'][-1]
    previous = before['series'][-1]
    check('approval adds the order value and profit to the card and chart', approved['value'] == before['value'] + 1000000 and approved['profit'] == before['profit'] + 400000 and last['revenue'] == previous['revenue'] + 1000000 and last['profit'] == previous['profit'] + 400000 and last['orders'] == previous['orders'] + 1)
    post(page, action='status', id=oid, status='Printing')
    check('production status changes do not count the order twice', snapshot() == approved)
    post(page, action='status', id=oid, status='Delivered')
    check('delivered orders remain in accepted order totals', snapshot() == approved)
    post(page, action='status', id=oid, status='Cancelled')
    check('cancellation removes the order from every sales figure', snapshot() == before)

    other_context = browser.new_context()
    other = other_context.new_page()
    login(other, 'admin@printboss.local')
    post(other, action='save', product_name=PREFIX + ' other business', order_quantity='10000', status='Approved', unit_cost='60', unit_selling_price='100')
    check('another business cannot affect the dashboard figures', snapshot() == before)
    page.screenshot(path=str(OUT / 'dashboard-after-quote-and-cancellation.png'), full_page=True)
    browser.close()

(OUT / 'results.txt').write_text('\n'.join(('PASS ' if ok else 'FAIL ') + name for name, ok in results) + '\n', encoding='utf-8')
print(f'{sum(ok for _, ok in results)}/{len(results)} passed')
raise SystemExit(0 if all(ok for _, ok in results) else 1)
