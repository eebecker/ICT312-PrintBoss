"""Printer job conflict checks. Use a disposable local database only."""
import os
import subprocess
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright

if os.environ.get('PRINTBOSS_TESTING') != '1':
    raise SystemExit('Set PRINTBOSS_TESTING=1 and use a disposable database. Tests add records and temporary failure triggers.')
BASE = os.environ['PRINTBOSS_TEST_URL'].rstrip('/')
PREFIX = 'Printer conflict test ' + uuid.uuid4().hex[:8]
OUT = Path(os.environ.get('PRINTBOSS_TEST_OUTPUT', 'printer-conflict-results'))
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
    page.goto(BASE + '/printers.php')
    csrf = page.locator('input[name=csrf_token]').first.input_value()

    def post(**data):
        response = page.request.post(BASE + '/printers.php', form=dict(data, csrf_token=csrf))
        assert response.status == 200
        return response.text()

    def printer(label):
        name = PREFIX + ' ' + label
        post(action='save_printer', name=name, status='Available')
        return num(f"SELECT id FROM printers WHERE user_id={uid} AND name='{name}'")

    def start(pid, when='now'):
        return post(action='start_job', printer_id=str(pid), source_type='custom', custom_job_name=PREFIX, planned_quantity='1', when=when)

    def count(pid):
        return num(f'SELECT COUNT(*) FROM print_jobs WHERE printer_id={pid}')

    def state(pid):
        return sql(f'SELECT status,current_job_id FROM printers WHERE id={pid}')

    pid = printer('scheduled')
    start(pid, 'schedule')
    jid = num(f'SELECT MAX(id) FROM print_jobs WHERE printer_id={pid}')
    start(pid, 'schedule')
    check('second scheduled job is rejected', count(pid) == 1 and state(pid) == f'Scheduled\t{jid}')
    start(pid)
    check('immediate job cannot replace a scheduled job', count(pid) == 1 and state(pid) == f'Scheduled\t{jid}')
    post(action='begin_job', id=str(jid))
    check('the scheduled current job can begin normally', state(pid) == f'Printing\t{jid}' and sql(f'SELECT status FROM print_jobs WHERE id={jid}') == 'Printing')
    start(pid)
    check('another job cannot start while a printer is printing', count(pid) == 1 and state(pid) == f'Printing\t{jid}')

    # Recreate an orphan left by the old multiple-scheduling bug.
    sql(f"INSERT INTO print_jobs (user_id,printer_id,source_type,custom_job_name,status,planned_quantity,estimated_duration_minutes) VALUES ({uid},{pid},'custom','{PREFIX} orphan','Scheduled',1,60)")
    orphan = num(f'SELECT MAX(id) FROM print_jobs WHERE printer_id={pid}')
    post(action='begin_job', id=str(orphan))
    check('an old scheduled orphan cannot start alongside the current job', sql(f'SELECT status FROM print_jobs WHERE id={orphan}') == 'Scheduled' and state(pid) == f'Printing\t{jid}')
    sql(f"UPDATE print_jobs SET status='Paused' WHERE id={orphan}")
    post(action='resume_job', id=str(orphan))
    check('an old paused orphan cannot steal the printer on resume', sql(f'SELECT status FROM print_jobs WHERE id={orphan}') == 'Paused' and state(pid) == f'Printing\t{jid}')
    post(action='cancel_job', id=str(orphan))
    check('cancelling an orphan does not release the current printer', state(pid) == f'Printing\t{jid}')
    sql(f"UPDATE print_jobs SET status='Awaiting Confirmation' WHERE id={orphan}")
    post(action='confirm_job', id=str(orphan), successful_quantity='1')
    check('confirming an orphan cannot clear the current job', state(pid) == f'Printing\t{jid}' and sql(f'SELECT status FROM print_jobs WHERE id={orphan}') == 'Awaiting Confirmation')
    # Remove the injected orphan before continuing normal workflow checks.
    sql(f'DELETE FROM print_jobs WHERE id={orphan}')
    post(action='cancel_job', id=str(jid))
    start(pid)
    current = num(f'SELECT MAX(id) FROM print_jobs WHERE printer_id={pid}')
    check('cancelling the actual job allows the next job', current != jid and state(pid) == f'Printing\t{current}')

    queued = printer('queued')
    sql(f"INSERT INTO print_jobs (user_id,printer_id,source_type,custom_job_name,status,planned_quantity,estimated_duration_minutes) VALUES ({uid},{queued},'custom','{PREFIX} queued','Scheduled',1,60)")
    queued_id = num(f'SELECT MAX(id) FROM print_jobs WHERE printer_id={queued}')
    start(queued)
    running_id = num(f'SELECT MAX(id) FROM print_jobs WHERE printer_id={queued}')
    check('a waiting queue entry does not block an available printer', running_id != queued_id and state(queued) == f'Printing\t{running_id}')
    post(action='cancel_job', id=str(running_id))
    post(action='begin_job', id=str(queued_id))
    check('a queued job can begin once the current job is finished', state(queued) == f'Printing\t{queued_id}')

    trigger = 'printer_conflict_' + uuid.uuid4().hex[:10]
    spare = printer('rollback')
    sql(f"CREATE TRIGGER {trigger} BEFORE UPDATE ON printers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test printer update failure'")
    try:
        start(spare)
        check('failed printer update rolls back the new job', count(spare) == 0 and sql(f'SELECT status FROM printers WHERE id={spare}') == 'Available')
    finally:
        sql(f'DROP TRIGGER IF EXISTS {trigger}')
    sql(f"CREATE TRIGGER {trigger} BEFORE UPDATE ON print_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test job update failure'")
    try:
        post(action='pause_job', id=str(current))
        check('failed job action leaves the printer and job unchanged', state(pid) == f'Printing\t{current}' and sql(f'SELECT status FROM print_jobs WHERE id={current}') == 'Printing')
    finally:
        sql(f'DROP TRIGGER IF EXISTS {trigger}')

    page.goto(BASE + '/printers.php')
    page.screenshot(path=str(OUT / 'printer-job-conflicts.png'), full_page=True)
    browser.close()

(OUT / 'results.txt').write_text('\n'.join(('PASS ' if ok else 'FAIL ') + name for name, ok in results) + '\n', encoding='utf-8')
print(f'{sum(ok for _, ok in results)}/{len(results)} passed')
raise SystemExit(0 if all(ok for _, ok in results) else 1)
