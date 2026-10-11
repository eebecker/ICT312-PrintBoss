"""Payment, account lockout and layout regressions on a disposable seeded app."""
import os
import re
import subprocess
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright

if os.environ.get('PRINTBOSS_TESTING') != '1':
    raise SystemExit('Set PRINTBOSS_TESTING=1 and use a disposable seeded database only.')
BASE = os.environ['PRINTBOSS_TEST_URL'].rstrip('/')
OUT = Path(os.environ.get('PRINTBOSS_TEST_OUTPUT', 'remaining-issues-results'))
OUT.mkdir(parents=True, exist_ok=True)
PREFIX = 'Remaining test ' + uuid.uuid4().hex[:8]
results = []


def sql(query):
    return subprocess.run([
        os.environ['PRINTBOSS_TEST_MYSQL'], '--protocol=tcp', '--host=127.0.0.1',
        '--port=' + os.environ['PRINTBOSS_TEST_DB_PORT'], '--user=root',
        '--database=' + os.environ['PRINTBOSS_TEST_DB_NAME'], '--batch',
        '--skip-column-names', '--execute', query,
    ], check=True, capture_output=True, text=True).stdout.strip()


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

    page.goto(BASE + '/invoice_form.php')
    number = page.locator('[name=invoice_number]').input_value()
    page.fill('[name=customer_name]', PREFIX)
    page.fill('[name="item_name[]"]', 'Test item')
    page.fill('[name="item_price[]"]', '100')
    page.uncheck('[name=gst_enabled]')
    page.fill('[name=amount_paid]', '101')
    page.click('#invoiceForm button[type=submit]')
    check('invoice creation rejects an overpayment', 'Amount paid cannot be more' in page.content())
    check('rejected invoice is not inserted', sql(f"SELECT COUNT(*) FROM invoices WHERE invoice_number='{number}'") == '0')
    page.fill('[name=amount_paid]', '0')
    page.click('#invoiceForm button[type=submit]')
    page.wait_for_url('**/invoice_view.php?id=*')
    iid = int(re.search(r'id=(\d+)', page.url).group(1))
    page.goto(BASE + f'/invoice_form.php?id={iid}')
    page.fill('[name=amount_paid]', '150')
    page.click('#invoiceForm button[type=submit]')
    check('editing rejects an overpayment without changing the invoice', 'Amount paid cannot be more' in page.content() and sql(f'SELECT amount_paid FROM invoices WHERE id={iid}') == '0.00')
    page.goto(BASE + '/invoices.php')
    token = page.locator('[name=csrf_token]').first.input_value()

    def payment(value):
        return page.request.post(BASE + '/invoices.php', form={
            'csrf_token': token, 'id': str(iid), 'action': 'payment', 'amount': str(value),
        }).text()

    for value in [101, 0, -10]:
        text = payment(value)
        check(f'payment {value} rejected and balance unchanged', 'cannot exceed the remaining balance' in text and sql(f'SELECT amount_paid FROM invoices WHERE id={iid}') == '0.00')
    payment(40)
    check('partial payment records the exact amount and remaining balance', sql(f'SELECT amount_paid,balance_due FROM invoices WHERE id={iid}') == '40.00\t60.00')
    text = payment(61)
    check('payment exceeding the remaining balance is rejected', 'cannot exceed the remaining balance' in text and sql(f'SELECT amount_paid FROM invoices WHERE id={iid}') == '40.00')
    payment(60)
    check('exact remaining payment marks the invoice paid', sql(f'SELECT amount_paid,balance_due,status FROM invoices WHERE id={iid}') == '100.00\t0.00\tPaid')
    check('repeated payment on a settled invoice is rejected', 'cannot exceed the remaining balance' in payment(1) and sql(f'SELECT amount_paid FROM invoices WHERE id={iid}') == '100.00')
    page.goto(BASE + f'/invoice_view.php?id={iid}')
    page.screenshot(path=str(OUT / 'paid-invoice.png'), full_page=True)

    for width in [390, 320, 1366]:
        page.set_viewport_size({'width': width, 'height': 844})
        for route in ['printers.php', 'invoice_form.php']:
            page.goto(BASE + '/' + route)
            check(f'{route} fits {width}px without page overflow', page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
            if width == 390:
                page.screenshot(path=str(OUT / (route.replace('.php', '') + '-390px.png')), full_page=True)
                if route == 'invoice_form.php':
                    check('mobile line items scroll within their table', page.locator('#lineTable').evaluate('(e) => e.parentElement.scrollWidth > e.parentElement.clientWidth && e.parentElement.getBoundingClientRect().right <= innerWidth'))
                    page.click('#addLine')
                    check('mobile add line remains usable', page.locator('#lineTable tbody tr').count() == 2)

    # Use a separate account so the demo user's existing session is unaffected.
    email = 'lock-' + uuid.uuid4().hex[:8] + '@example.test'
    sql(f"INSERT INTO users(email,password_hash,full_name) SELECT '{email}',password_hash,'Lockout test' FROM users WHERE email='demo@printboss.local'")
    try:
        def attempt(password):
            context = browser.new_context()
            fresh = context.new_page()
            fresh.goto(BASE + '/login.php')
            fresh.fill('#email', email)
            fresh.fill('#password', password)
            fresh.click('button[type=submit]')
            fresh.wait_for_load_state('networkidle')
            return context, fresh

        for _ in range(5):
            ctx, fresh = attempt('wrong-password')
            ctx.close()
        check('fresh-cookie failures share the account counter', sql(f"SELECT failed_login_attempts FROM users WHERE email='{email}'") == '5')
        ctx, fresh = attempt('Demo1234!')
        check('correct password in a fresh browser cannot bypass the lock', 'Too many failed attempts' in fresh.content() and fresh.url.endswith('/login.php'))
        fresh.screenshot(path=str(OUT / 'fresh-browser-lockout.png'), full_page=True)
        ctx.close()
        check('lock is stored with a future expiry', sql(f"SELECT login_locked_until>NOW() FROM users WHERE email='{email}'") == '1')
        sql(f"UPDATE users SET login_locked_until=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE email='{email}'")
        ctx, fresh = attempt('wrong-password')
        check('expired lock starts at one failed attempt', sql(f"SELECT failed_login_attempts,login_locked_until IS NULL FROM users WHERE email='{email}'") == '1\t1')
        ctx.close()
        ctx, fresh = attempt('Demo1234!')
        check('correct password works after expiry and resets failures', fresh.url.endswith('/dashboard.php') and sql(f"SELECT failed_login_attempts,login_locked_until IS NULL FROM users WHERE email='{email}'") == '0\t1')
        ctx.close()
    finally:
        sql(f"DELETE FROM users WHERE email='{email}'")
        browser.close()

(OUT / 'results.txt').write_text('\n'.join(('PASS ' if ok else 'FAIL ') + name for name, ok in results) + '\n', encoding='utf-8')
print(f'{sum(ok for _, ok in results)}/{len(results)} passed')
raise SystemExit(0 if all(ok for _, ok in results) else 1)
