"""Printer/order stock checks. Use a disposable local database only."""
import os
import subprocess
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright

if os.environ.get('PRINTBOSS_TESTING') != '1':
    raise SystemExit('Set PRINTBOSS_TESTING=1 and use a disposable database. Tests create records and temporary failure triggers.')
BASE = os.environ['PRINTBOSS_TEST_URL'].rstrip('/')
PREFIX = 'Printer stock test ' + uuid.uuid4().hex[:8]
OUT = Path(os.environ.get('PRINTBOSS_TEST_OUTPUT', 'printer-stock-results'))
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
    uid = num("SELECT id FROM users WHERE email='demo@printboss.local'")
    page.goto(BASE + '/orders.php')
    csrf = page.locator('input[name=csrf_token]').first.input_value()

    def post(path, **data):
        response = page.request.post(BASE + '/' + path, form=dict(data, csrf_token=csrf))
        assert response.status == 200
        return response.text()

    def stock(label, qty):
        name = PREFIX + ' ' + label
        post('stock.php', action='save', product_name=name, current_quantity=str(qty))
        return num(f"SELECT id FROM stock_items WHERE user_id={uid} AND product_name='{name}'")

    def order(label, sid, qty=2, status='Quote'):
        name = PREFIX + ' ' + label
        post('orders.php', action='save', product_name=name, stock_item_id=str(sid) if sid else '', order_quantity=str(qty), status=status)
        return num(f"SELECT id FROM orders WHERE user_id={uid} AND product_name='{name}'")

    def printer(label):
        name = PREFIX + ' ' + label
        post('printers.php', action='save_printer', name=name, status='Available')
        return num(f"SELECT id FROM printers WHERE user_id={uid} AND name='{name}'")

    def start(pid, oid, when='now'):
        return post('printers.php', action='start_job', printer_id=str(pid), source_type='order', order_id=str(oid), when=when, planned_quantity='2')

    def qty(sid):
        return num(f'SELECT current_quantity FROM stock_items WHERE id={sid}')

    sid = stock('immediate', 10)
    oid = order('immediate', sid)
    pid = printer('immediate')
    start(pid, oid)
    check('starting a quote reserves stock and updates the order', qty(sid) == 8 and sql(f'SELECT status,stock_deducted FROM orders WHERE id={oid}') == 'Printing\t2')

    approved = order('approved', sid, status='Approved')
    reserved = qty(sid)
    start(printer('approved'), approved)
    check('starting an approved order does not reserve stock twice', qty(sid) == reserved and num(f'SELECT stock_deducted FROM orders WHERE id={approved}') == 2)

    limited = stock('limited', 1)
    shortage = order('shortage', limited)
    spare = printer('shortage')
    body = start(spare, shortage)
    check('shortage rejects the job without partial changes', 'Not enough stock' in body and qty(limited) == 1 and sql(f'SELECT status,stock_deducted FROM orders WHERE id={shortage}') == 'Quote\t0' and sql(f'SELECT status FROM printers WHERE id={spare}') == 'Available' and num(f'SELECT COUNT(*) FROM print_jobs WHERE printer_id={spare}') == 0)

    scheduled = order('scheduled', sid)
    scheduled_printer = printer('scheduled')
    before = qty(sid)
    start(scheduled_printer, scheduled, 'schedule')
    jid = num(f'SELECT MAX(id) FROM print_jobs WHERE printer_id={scheduled_printer}')
    check('scheduling does not reserve stock before the job starts', qty(sid) == before and sql(f'SELECT status FROM orders WHERE id={scheduled}') == 'Quote')
    post('printers.php', action='begin_job', id=str(jid))
    post('printers.php', action='begin_job', id=str(jid))
    check('beginning a scheduled job reserves stock once', qty(sid) == before - 2 and sql(f'SELECT status,stock_deducted FROM orders WHERE id={scheduled}') == 'Printing\t2')

    scheduled_short = order('scheduled shortage', limited)
    sp = printer('scheduled shortage')
    start(sp, scheduled_short, 'schedule')
    sj = num(f'SELECT MAX(id) FROM print_jobs WHERE printer_id={sp}')
    body = post('printers.php', action='begin_job', id=str(sj))
    check('scheduled shortage leaves the job and printer scheduled', qty(limited) == 1 and sql(f'SELECT status FROM print_jobs WHERE id={sj}') == 'Scheduled' and sql(f'SELECT status FROM printers WHERE id={sp}') == 'Scheduled' and 'Not enough stock' in body)

    custom = order('custom', None)
    start(printer('custom'), custom)
    check('custom orders without a stock product can still start', sql(f'SELECT status,stock_deducted FROM orders WHERE id={custom}') == 'Printing\t0')

    failing = order('failing', sid)
    fp = printer('failing')
    before = qty(sid)
    movements = num(f'SELECT COUNT(*) FROM stock_movements WHERE stock_item_id={sid}')
    trigger = 'printer_stock_test_' + uuid.uuid4().hex[:10]
    sql(f"CREATE TRIGGER {trigger} BEFORE INSERT ON print_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test job failure'")
    try:
        body = start(fp, failing)
        check('failed job insert rolls back the reservation and order status', qty(sid) == before and sql(f'SELECT status,stock_deducted FROM orders WHERE id={failing}') == 'Quote\t0' and num(f'SELECT COUNT(*) FROM stock_movements WHERE stock_item_id={sid}') == movements and sql(f'SELECT status FROM printers WHERE id={fp}') == 'Available' and 'Could not save the print job' in body)
    finally:
        sql(f'DROP TRIGGER IF EXISTS {trigger}')

    sql(f"CREATE TRIGGER {trigger} BEFORE INSERT ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test movement failure'")
    try:
        start(fp, failing)
        check('failed movement log rolls back stock and creates no job', qty(sid) == before and num(f'SELECT COUNT(*) FROM print_jobs WHERE printer_id={fp}') == 0 and sql(f'SELECT status FROM orders WHERE id={failing}') == 'Quote')
    finally:
        sql(f'DROP TRIGGER IF EXISTS {trigger}')

    page.goto(BASE + '/orders.php')
    page.locator('input[type=search]').first.fill(PREFIX)
    page.screenshot(path=str(OUT / 'printer-order-reservations.png'), full_page=True)
    browser.close()

(OUT / 'results.txt').write_text('\n'.join(('PASS ' if ok else 'FAIL ') + name for name, ok in results) + '\n', encoding='utf-8')
print(f'{sum(ok for _, ok in results)}/{len(results)} passed')
raise SystemExit(0 if all(ok for _, ok in results) else 1)
