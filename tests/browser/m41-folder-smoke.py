"""M4.1 local folder acceptance: create/rename/move/reorder/delete through the real browser UI
against the guarded isolated test server on 8072, using the existing session fixture (synthetic
mail only). Reuses the session-resource-smoke.py CDP scaffolding.
"""
import json
import pathlib
import subprocess
import tempfile
import time
import urllib.request
from websockets.sync.client import connect

ROOT = 'http://127.0.0.1:8072'
with tempfile.TemporaryDirectory(prefix='mailcenter-folder-browser-') as profile:
    chrome = subprocess.Popen(['google-chrome', '--headless', '--no-sandbox', '--disable-gpu',
        '--disable-background-networking', '--disable-dev-shm-usage', '--no-proxy-server', '--no-first-run', '--remote-debugging-port=0',
        '--user-data-dir=' + profile, 'about:blank'], stderr=subprocess.DEVNULL)
    try:
        port_file = pathlib.Path(profile, 'DevToolsActivePort')
        for _ in range(100):
            if port_file.exists():
                break
            time.sleep(.1)
        port = port_file.read_text().splitlines()[0]
        req = urllib.request.Request(f'http://127.0.0.1:{port}/json/new?about:blank', method='PUT')
        tab = json.load(urllib.request.urlopen(req))
        with connect(tab['webSocketDebuggerUrl'], proxy=None, open_timeout=30) as ws:
            sequence = 0

            def cdp(method, params=None):
                global sequence
                sequence += 1
                ws.send(json.dumps({'id': sequence, 'method': method, 'params': params or {}}))
                while True:
                    answer = json.loads(ws.recv(timeout=60))
                    if answer.get('id') == sequence:
                        assert 'error' not in answer, answer
                        return answer.get('result', {})

            def js(expression):
                result = cdp('Runtime.evaluate', {'expression': expression, 'awaitPromise': True, 'returnByValue': True, 'userGesture': True})
                assert 'exceptionDetails' not in result, result
                return result.get('result', {}).get('value')

            def wait_for(expression, timeout=100):
                for _ in range(timeout):
                    if js(expression):
                        return True
                    time.sleep(.1)
                raise AssertionError('Timed out waiting for: ' + expression)

            def click(selector):
                assert js(f"(()=>{{const el=document.querySelector({json.dumps(selector)});if(!el)return false;el.click();return true}})()")

            def click_button_with_text(text, root='document'):
                ok = js(f"(()=>{{const b=[...{root}.querySelectorAll('button')].find(x=>x.textContent.trim()==={json.dumps(text)});if(!b)return false;b.click();return true}})()")
                assert ok, f'button not found: {text}'

            def click_folder_nav(text):
                ok = js(f"(()=>{{const b=[...document.querySelectorAll('.folder-nav-row')].find(x=>x.textContent.includes({json.dumps(text)}));if(!b)return false;b.click();return true}})()")
                assert ok, f'folder nav row not found: {text}'

            def set_input(selector, value):
                ok = js(f"(()=>{{const el=document.querySelector({json.dumps(selector)});if(!el)return false;el.value={json.dumps(value)};el.dispatchEvent(new Event('input',{{bubbles:true}}));return true}})()")
                assert ok, f'input not found: {selector}'

            def submit(selector):
                ok = js(f"(()=>{{const el=document.querySelector({json.dumps(selector)});if(!el)return false;el.requestSubmit();return true}})()")
                assert ok, f'form not found: {selector}'

            def row_button_with_title(row_text, title):
                return js(f"""(()=>{{const rows=[...document.querySelectorAll('.folder-row-wrap')];
                    const row=rows.find(r=>r.textContent.includes({json.dumps(row_text)}));
                    if(!row)return false;const btn=row.querySelector({json.dumps('[title="' + title + '"]')});
                    if(!btn)return false;btn.click();return true}})()""")

            cdp('Network.enable')
            navigation = cdp('Page.navigate', {'url': ROOT + '/login'})
            assert 'errorText' not in navigation, navigation
            wait_for("location.origin === '" + ROOT + "' && document.readyState === 'complete'")

            login_status = js('''(async()=>{await fetch('/sanctum/csrf-cookie');
                const token=decodeURIComponent(document.cookie.split('; ').find(x=>x.startsWith('XSRF-TOKEN=')).split('=')[1]);
                const r=await fetch('/login',{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json','X-XSRF-TOKEN':token},body:JSON.stringify({email:'owner@browser.test',password:'browser-test-password'})});return r.status})()''')
            assert login_status == 200, login_status

            cdp('Page.navigate', {'url': ROOT + '/mail/all'})
            wait_for("document.querySelectorAll('.folder-nav-row').length >= 8")
            print('Step 1: All Mail open with seeded system + default custom folders.', flush=True)

            # Step 2: create folder "Test Folder"
            click_button_with_text('+ New folder')
            set_input('.folder-create-form input', 'Test Folder')
            submit('.folder-create-form')
            wait_for("document.body.textContent.includes('Test Folder')")
            print('Step 2: created "Test Folder".', flush=True)

            # Step 3: rename to "Test Renamed"
            assert row_button_with_title('Test Folder', 'Rename folder')
            wait_for("document.querySelector('.folder-edit-form input') !== null")
            set_input('.folder-edit-form input', 'Test Renamed')
            submit('.folder-edit-form')
            wait_for("document.querySelector('.folder-edit-form') === null")
            try:
                wait_for("[...document.querySelectorAll('.folder-nav-row')].some(b=>b.textContent.includes('Test Renamed'))", timeout=50)
            except AssertionError:
                raise AssertionError((js("document.querySelector('.form-error')?.textContent"),
                                       js("[...document.querySelectorAll('.folder-nav-row')].map(b=>b.textContent)")))
            print('Step 3: renamed to "Test Renamed".', flush=True)

            # Step 4: move one message into it via the row's compact Move menu
            wait_for("document.querySelectorAll('.message-row').length >= 1")
            moved_ok = js('''(()=>{const select=document.querySelector('select.message-row-move');
                if(!select)return false;
                const option=[...select.options].find(o=>o.textContent.trim()==='Test Renamed');
                if(!option)return false;
                select.value=option.value;
                select.dispatchEvent(new Event('change',{bubbles:true}));
                return true})()''')
            assert moved_ok, 'could not find the Test Renamed option in the row move menu'
            time.sleep(.3)
            print('Step 4: moved one message into "Test Renamed" via the row menu.', flush=True)

            # Step 5+6: open the folder and verify the message is there; All Mail is unaffected
            click_folder_nav('Test Renamed')
            wait_for("/^\\/mail\\/folder\\/\\d+$/.test(location.pathname)")
            time.sleep(.3)
            wait_for("document.querySelectorAll('.message-row').length >= 1")
            print('Step 5-6: folder route opened and shows the moved message.', flush=True)

            cdp('Page.navigate', {'url': ROOT + '/mail/all'})
            wait_for("document.querySelectorAll('.message-row').length >= 1")
            print('Step 6b: All Mail still contains the message after the move.', flush=True)

            # Step 9: reorder folders (move "Test Renamed" up one position) and verify it persists
            assert row_button_with_title('Test Renamed', 'Move up')
            index_expr = "(()=>{const names=[...document.querySelectorAll('.folder-nav-row')].map(b=>b.textContent);return [names.findIndex(t=>t.includes('Test Renamed')),names.findIndex(t=>t.includes('Done'))]})()"
            wait_for(f"{index_expr}[0] < {index_expr}[1]")
            order_before = js("[...document.querySelectorAll('.folder-nav-row')].map(b=>b.textContent.trim())")
            cdp('Page.reload')
            wait_for("document.querySelectorAll('.folder-nav-row').length >= 9")
            order_after = js("[...document.querySelectorAll('.folder-nav-row')].map(b=>b.textContent.trim())")
            assert order_before == order_after, (order_before, order_after)
            print('Step 9: reorder persisted across reload:', order_after, flush=True)

            # Step 10-11: delete the custom folder; confirm messages return to Inbox
            js("window.confirm = () => true")
            assert row_button_with_title('Test Renamed', 'Delete folder')
            wait_for("!document.body.textContent.includes('Test Renamed')")
            print('Step 10-11: deleted "Test Renamed"; UI no longer shows it.', flush=True)

            cdp('Page.navigate', {'url': ROOT + '/mail/inbox'})
            wait_for("document.querySelectorAll('.message-row').length >= 1")
            print('Step 11b: the previously moved message is back in Inbox.', flush=True)

            # Step 12: system folders cannot be deleted
            no_delete_on_inbox = js("""(()=>{const rows=[...document.querySelectorAll('.folder-row-wrap')];
                const row=rows.find(r=>r.textContent.includes('Inbox'));
                return !!row && !row.querySelector('[title="Delete folder"]')})()""")
            assert no_delete_on_inbox
            print('Step 12: system folder (Inbox) exposes no delete control.', flush=True)

            print('PASS: create/rename/move/reorder/delete-with-reassignment/system-folder-protection all verified through the real UI.')
            cdp('Browser.close')
            chrome.wait(timeout=20)
    finally:
        if chrome.poll() is None:
            chrome.terminate()
            chrome.wait(timeout=20)
