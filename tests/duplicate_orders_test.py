"""Duplicate order checks against a disposable local database."""
import os
import subprocess
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright

if os.environ.get('PRINTBOSS_TESTING') != '1':
    raise SystemExit('Use a disposable database and set PRINTBOSS_TESTING=1. Tests add records and a temporary failure trigger.')

BASE = os.environ['PRINTBOSS_TEST_URL'].rstrip('/')
PREFIX = 'Duplicate test ' + uuid.uuid4().hex[:8]
OUT = Path(os.environ.get('PRINTBOSS_TEST_OUTPUT', 'duplicate-test-results'))
OUT.mkdir(parents=True, exist_ok=True)
results = []


def sql(query):
    args = [os.environ.get('PRINTBOSS_TEST_MYSQL', 'mysql'), '--protocol=tcp',
            '--host=' + os.environ.get('PRINTBOSS_TEST_DB_HOST', '127.0.0.1'),
            '--port=' + os.environ['PRINTBOSS_TEST_DB_PORT'],
            '--user=' + os.environ.get('PRINTBOSS_TEST_DB_USER', 'root'),
            '--database=' + os.environ.get('PRINTBOSS_TEST_DB_NAME', 'printboss'),
            '--batch', '--skip-column-names', '--execute', query]
    return subprocess.run(args, check=True, capture_output=True, text=True).stdout.strip()


def check(name, condition):
    results.append((name, bool(condition)))
    print(('PASS ' if condition else 'FAIL ') + name, flush=True)


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
    uid = int(sql("SELECT id FROM users WHERE email='demo@printboss.local'"))
    page.goto(BASE + '/orders.php')
    csrf = page.locator('input[name=csrf_token]').first.input_value()

    def form_token():
        page.goto(BASE + '/orders.php')
        return page.locator('#orderForm [name=submission_token]').input_value()

    def post(path, data):
        response = page.request.post(BASE + '/' + path, form=dict(data, csrf_token=csrf))
        assert response.status == 200
        return response.text()

    post('stock.php', dict(action='save', product_name=PREFIX, current_quantity='20', minimum_quantity='0'))
    sid = int(sql(f"SELECT id FROM stock_items WHERE user_id={uid} AND product_name='{PREFIX}'"))

    def count():
        return int(sql(f"SELECT COUNT(*) FROM orders WHERE user_id={uid} AND product_name LIKE '{PREFIX}%'") )

    def qty():
        return int(sql(f'SELECT current_quantity FROM stock_items WHERE id={sid}'))

    data = dict(action='save', product_name=PREFIX, customer_name='Test buyer', stock_item_id=str(sid),
                order_quantity='2', status='Approved', unit_cost='10', unit_selling_price='20', submission_token=form_token())
    post('orders.php', data)
    post('orders.php', data)
    check('replaying one form creates one order and one reservation', count() == 1 and qty() == 18)
    changed = dict(data, order_quantity='3')
    post('orders.php', changed)
    check('reusing a saved token with changed fields is also blocked', count() == 1 and qty() == 18)
    missing = dict(data)
    missing.pop('submission_token')
    body = post('orders.php', missing)
    check('missing token cannot bypass the duplicate check', count() == 1 and 'already saved or expired' in body)
    post('orders.php', dict(data, submission_token='invalid'))
    check('unissued token is rejected', count() == 1 and qty() == 18)

    first, second = form_token(), form_token()
    post('orders.php', dict(data, submission_token=first))
    post('orders.php', dict(data, submission_token=second))
    check('separate forms can create identical intentional orders', count() == 3 and qty() == 14)
    oid = int(sql(f"SELECT MIN(id) FROM orders WHERE user_id={uid} AND product_name='{PREFIX}'"))
    post('orders.php', dict(missing, id=str(oid), customer_name='Edited buyer'))
    check('existing orders can still be edited without a new-order token', sql(f'SELECT customer_name FROM orders WHERE id={oid}') == 'Edited buyer' and count() == 3 and qty() == 14)

    retry = dict(data, submission_token=form_token())
    trigger = 'duplicate_test_' + uuid.uuid4().hex[:12]
    sql(f"CREATE TRIGGER {trigger} BEFORE INSERT ON orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test save failure'")
    try:
        post('orders.php', retry)
        check('failed save creates no order or reservation', count() == 3 and qty() == 14)
    finally:
        sql(f'DROP TRIGGER IF EXISTS {trigger}')
    post('orders.php', retry)
    post('orders.php', retry)
    check('failed save can be retried and only saves once', count() == 4 and qty() == 12)
    overlap = dict(data, submission_token=form_token(), csrf_token=csrf)
    urls = [BASE + '/orders.php', os.environ.get('PRINTBOSS_TEST_SECOND_URL', BASE).rstrip('/') + '/orders.php']
    page.evaluate("""async ({urls, data}) => {
        await Promise.all(urls.map(url => fetch(url, {
            method: 'POST', credentials: 'include',
            body: new URLSearchParams(data)
        }).then(response => { if (!response.ok) throw new Error('Submission failed'); })));
    }""", dict(urls=urls, data=overlap))
    check('overlapping submissions save one order and reserve stock once', count() == 5 and qty() == 10)
    page.goto(BASE + '/orders.php')
    page.locator('input[type=search]').first.fill(PREFIX)
    page.screenshot(path=str(OUT / 'orders-after-replays.png'), full_page=True)
    browser.close()

(OUT / 'results.txt').write_text('\n'.join(('PASS ' if ok else 'FAIL ') + name for name, ok in results) + '\n', encoding='utf-8')
print(f'{sum(ok for _, ok in results)}/{len(results)} passed')
raise SystemExit(0 if all(ok for _, ok in results) else 1)
