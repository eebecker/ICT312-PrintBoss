"""Stock regression checks. Run only against a disposable local test database."""
import os
import subprocess
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright

if os.environ.get('PRINTBOSS_TESTING') != '1':
    raise SystemExit('Set PRINTBOSS_TESTING=1 and use a disposable database. These tests add data and temporary failure triggers.')

BASE = os.environ['PRINTBOSS_TEST_URL'].rstrip('/')
PREFIX = 'Stock test ' + uuid.uuid4().hex[:8]
OUT = Path(os.environ.get('PRINTBOSS_TEST_OUTPUT', 'stock-test-results'))
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


def num(query):
    return int(sql(query))


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
    uid = num("SELECT id FROM users WHERE email='demo@printboss.local'")
    page.goto(BASE + '/orders.php')
    token = page.locator('input[name=csrf_token]').first.input_value()

    def post(path, **data):
        response = page.request.post(BASE + '/' + path, form=dict(data, csrf_token=token))
        assert response.status == 200, response.status
        return response.text()

    def stock(label, quantity):
        name = PREFIX + ' ' + label
        post('stock.php', action='save', product_name=name, current_quantity=str(quantity), minimum_quantity='0', unit_cost='10', selling_price='20')
        return num(f"SELECT id FROM stock_items WHERE user_id={uid} AND product_name='{name}'")

    def quantity(sid):
        return num(f'SELECT current_quantity FROM stock_items WHERE id={sid}')

    def save_order(label, sid, qty, status='Approved', oid=None):
        data = dict(action='save', customer_name='Test Buyer', product_name=PREFIX + ' ' + label,
                    stock_item_id=str(sid), order_quantity=str(qty), status=status,
                    unit_cost='10', unit_selling_price='20')
        if oid:
            data['id'] = str(oid)
        return post('orders.php', **data)

    def order_id(label):
        return num(f"SELECT id FROM orders WHERE user_id={uid} AND product_name='{PREFIX} {label}'")

    sid = stock('limited', 2)
    body = save_order('shortage', sid, 5)
    count = num(f"SELECT COUNT(*) FROM orders WHERE product_name='{PREFIX} shortage'")
    check('shortage rejected without saving an order or changing stock', count == 0 and quantity(sid) == 2 and 'Not enough stock' in body)
    page.goto(BASE + '/orders.php')
    page.goto(BASE + '/stock.php')
    page.locator('input[type=search]').first.fill(PREFIX)
    page.screenshot(path=str(OUT / 'shortage-stock.png'), full_page=True)

    save_order('quote', sid, 5, 'Quote')
    oid = order_id('quote')
    body = post('orders.php', action='status', id=str(oid), status='Approved')
    check('quote approval rejects shortage and preserves quote', sql(f'SELECT status,stock_deducted FROM orders WHERE id={oid}') == 'Quote\t0' and quantity(sid) == 2 and 'Not enough stock' in body)
    save_order('valid', sid, 2)
    valid = order_id('valid')
    check('valid reservation deducts exactly the requested amount', quantity(sid) == 0)
    save_order('valid', sid, 3, oid=valid)
    check('increasing an order beyond available stock preserves its reservation', sql(f'SELECT order_quantity,stock_deducted FROM orders WHERE id={valid}') == '2\t2' and quantity(sid) == 0)
    other = stock('other', 1)
    save_order('valid', other, 2, oid=valid)
    check('failed product change restores both products and the original order', quantity(sid) == 0 and quantity(other) == 1 and num(f'SELECT stock_item_id FROM orders WHERE id={valid}') == sid)
    post('orders.php', action='status', id=str(valid), status='Cancelled')
    post('orders.php', action='status', id=str(valid), status='Cancelled')
    check('repeated cancellation returns stock only once', quantity(sid) == 2 and num(f'SELECT stock_deducted FROM orders WHERE id={valid}') == 0)
    post('stock.php', action='adjust', id=str(sid), mode='reduce', quantity='3')
    check('manual reduction cannot exceed available stock', quantity(sid) == 2)

    trigger = 'stock_test_' + uuid.uuid4().hex[:12]
    before = num(f'SELECT COUNT(*) FROM stock_movements WHERE stock_item_id={sid}')
    sql(f"CREATE TRIGGER {trigger} BEFORE INSERT ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test audit failure'")
    try:
        body = post('stock.php', action='adjust', id=str(sid), mode='add', quantity='3')
        check('failed audit insert rolls back a manual adjustment', quantity(sid) == 2 and num(f'SELECT COUNT(*) FROM stock_movements WHERE stock_item_id={sid}') == before and 'Could not adjust stock' in body)
        save_order('audit failure', sid, 1)
        check('failed audit insert rolls back a new order', quantity(sid) == 2 and num(f"SELECT COUNT(*) FROM orders WHERE product_name='{PREFIX} audit failure'") == 0)
        post('stock.php', action='save', product_name=PREFIX + ' opening failure', current_quantity='4')
        check('failed opening audit insert rolls back the new product', num(f"SELECT COUNT(*) FROM stock_items WHERE product_name='{PREFIX} opening failure'") == 0)
    finally:
        sql(f'DROP TRIGGER IF EXISTS {trigger}')

    save_order('delete', sid, 1)
    deleting = order_id('delete')
    before = num(f'SELECT COUNT(*) FROM stock_movements WHERE stock_item_id={sid}')
    sql(f"CREATE TRIGGER {trigger} BEFORE DELETE ON orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test delete failure'")
    try:
        body = post('orders.php', action='delete', id=str(deleting))
        check('failed deletion preserves the order, reservation and movement log', quantity(sid) == 1 and num(f'SELECT COUNT(*) FROM orders WHERE id={deleting}') == 1 and num(f'SELECT COUNT(*) FROM stock_movements WHERE stock_item_id={sid}') == before and 'Could not delete the order' in body)
        page.goto(BASE + '/stock.php')
        page.locator('input[type=search]').first.fill(PREFIX)
        page.screenshot(path=str(OUT / 'failed-delete-stock.png'), full_page=True)
    finally:
        sql(f'DROP TRIGGER IF EXISTS {trigger}')
    post('orders.php', action='delete', id=str(deleting))
    check('successful deletion returns the reservation', quantity(sid) == 2 and num(f'SELECT COUNT(*) FROM orders WHERE id={deleting}') == 0)
    browser.close()

summary = '\n'.join(('PASS ' if ok else 'FAIL ') + name for name, ok in results)
(OUT / 'results.txt').write_text(summary + '\n', encoding='utf-8')
print(f'{sum(ok for _, ok in results)}/{len(results)} passed')
raise SystemExit(0 if all(ok for _, ok in results) else 1)
