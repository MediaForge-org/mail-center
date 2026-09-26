"""Real Chromium + Laravel session resource check against the guarded test server on 8072.
Never point this test at development: only the fixed isolated fixture server is accepted.
Run with --reproduce before the auth repair to assert the reported 401 failure.
"""
import json
import pathlib
import subprocess
import sys
import tempfile
import time
import urllib.request
from websockets.sync.client import connect

ROOT = 'http://127.0.0.1:8072'
with tempfile.TemporaryDirectory(prefix='mailcenter-session-browser-') as profile:
    chrome = subprocess.Popen(['google-chrome', '--headless', '--no-sandbox', '--disable-gpu',
        '--disable-background-networking', '--disable-dev-shm-usage', '--no-proxy-server', '--no-first-run', '--remote-debugging-port=0',
        '--user-data-dir=' + profile, 'about:blank'], stderr=subprocess.DEVNULL)
    try:
        port_file = pathlib.Path(profile, 'DevToolsActivePort')
        for _ in range(100):
            if port_file.exists(): break
            time.sleep(.1)
        port = port_file.read_text().splitlines()[0]
        req = urllib.request.Request(f'http://127.0.0.1:{port}/json/new?about:blank', method='PUT')
        tab = json.load(urllib.request.urlopen(req))
        with connect(tab['webSocketDebuggerUrl'], proxy=None, open_timeout=30) as ws:
            sequence = 0
            api_metadata = []
            def cdp(method, params=None):
                global sequence
                sequence += 1
                ws.send(json.dumps({'id': sequence, 'method': method, 'params': params or {}}))
                while True:
                    answer = json.loads(ws.recv(timeout=60))
                    if answer.get('method') == 'Network.requestWillBeSent':
                        request = answer['params']['request']
                        if request['url'].endswith('/api/messages'):
                            api_metadata.append({k:v for k,v in request['headers'].items() if k.lower() in ['referer','origin']})
                    if answer.get('id') == sequence:
                        assert 'error' not in answer, answer
                        return answer.get('result', {})
            def js(expression):
                result = cdp('Runtime.evaluate', {'expression': expression, 'awaitPromise': True, 'returnByValue': True, 'userGesture': True})
                assert 'exceptionDetails' not in result, result
                return result.get('result', {}).get('value')
            cdp('Network.enable')
            navigation = cdp('Page.navigate', {'url': ROOT + '/login'})
            assert 'errorText' not in navigation, navigation
            for _ in range(100):
                if js('location.origin') == ROOT and js('document.readyState') == 'complete': break
                time.sleep(.1)
            def login(user):
                return js('''(async()=>{await fetch('/sanctum/csrf-cookie');
                    const token=decodeURIComponent(document.cookie.split('; ').find(x=>x.startsWith('XSRF-TOKEN=')).split('=')[1]);
                    const r=await fetch('/login',{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json','X-XSRF-TOKEN':token},body:JSON.stringify({email:''' + json.dumps(user + '@browser.test') + ''',password:'browser-test-password'})});return r.status})()''')
            def logout():
                return js('''(async()=>{const token=decodeURIComponent(document.cookie.split('; ').find(x=>x.startsWith('XSRF-TOKEN=')).split('=')[1]);return (await fetch('/logout',{method:'POST',headers:{'Accept':'application/json','X-XSRF-TOKEN':token}})).status})()''')
            assert login('owner') == 200
            listing = js("fetch('/api/messages').then(async r=>({status:r.status,body:await r.json()}))")
            assert listing['status'] == 200 and 'data' in listing['body'], (listing, api_metadata, js('location.href'))
            data = listing['body']['data']
            message = data[0]['id']
            other = data[1]['id']
            detail = js(f"fetch('/api/messages/{message}').then(r=>r.json())")['data']
            inline = next(a['id'] for a in detail['attachments'] if a['inline'])
            attachment = next(a['id'] for a in detail['attachments'] if not a['inline'])
            urls = [f'/api/messages/{message}/render', f'/api/messages/{message}/attachments/{attachment}', f'/api/messages/{message}/inline/{inline}']
            statuses = js('Promise.all(' + json.dumps(urls) + ".map(u=>fetch(u,{referrerPolicy:'no-referrer'}).then(r=>r.status)))")
            if '--reproduce' in sys.argv:
                assert statuses == [401,401,401], statuses
                print('REPRODUCED: JSON API authenticated; no-referrer render/download/CID all return 401 with the same session.')
                sys.exit(0)
            assert statuses == [200,200,200], statuses
            print('Authenticated browser resources: 200/200/200', flush=True)
            cdp('Browser.setDownloadBehavior', {'behavior': 'allow', 'downloadPath': profile})
            js("(()=>{const a=document.createElement('a');a.href=" + json.dumps(urls[1]) + ";a.referrerPolicy='no-referrer';a.target='_blank';a.rel='noopener noreferrer';document.body.append(a);a.click()})()")
            downloaded = pathlib.Path(profile, 'proof.txt')
            for _ in range(100):
                if downloaded.exists(): break
                time.sleep(.1)
            assert downloaded.read_bytes() == b'Actual attachment bytes'

            assert js(f"fetch('{urls[1]}',{{referrerPolicy:'no-referrer'}}).then(r=>r.text())") == 'Actual attachment bytes'
            framed = js('''new Promise(resolve=>{const f=document.createElement('iframe');f.sandbox='allow-same-origin allow-popups allow-popups-to-escape-sandbox';f.referrerPolicy='no-referrer';f.onload=()=>{const d=f.contentDocument;resolve({text:d.body.textContent,image:[...d.images].some(i=>i.complete&&i.naturalWidth===2)})};f.src=''' + json.dumps(urls[0]) + ''';document.body.append(f)})''')
            assert 'Browser session content' in framed['text'] and framed['image'], framed
            print('Actual attachment download and iframe CID decode passed', flush=True)
            cdp('Emulation.setDeviceMetricsOverride', {'width':2560, 'height':1600, 'deviceScaleFactor':1, 'mobile':False})
            cdp('Page.navigate', {'url': ROOT + f'/mail/all?message={message}'})
            for _ in range(150):
                if js("(()=>{const d=document.querySelector('.reader-html')?.contentDocument;return d?.body?.textContent.includes('Browser session content') && [...d.images].some(i=>i.complete&&i.naturalWidth===2)})()"): break
                time.sleep(.1)
            layout = js("(()=>{const row=document.querySelector('.message-row');const header=document.querySelector('.message-detail-header');return {row:row.getBoundingClientRect().height,header:header.getBoundingClientRect().height,overflow:document.documentElement.scrollWidth>innerWidth,scope:document.querySelector('.pane-toolbar').textContent}})()")
            assert layout['row'] <= 60 and layout['header'] < 240 and not layout['overflow'], layout
            assert 'GLOBAL WORKSPACE' in layout['scope']
            js("document.querySelector('[aria-label=\"Resize sidebar\"]').dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}))")
            width = js("JSON.parse(localStorage.getItem('mailcenter.pane-widths.v1')).sidebar")
            cdp('Page.reload')
            time.sleep(.5)
            for _ in range(150):
                if js("(()=>{const d=document.querySelector('.reader-html')?.contentDocument;return d?.body?.textContent.includes('Browser session content') && [...d.images].some(i=>i.complete&&i.naturalWidth===2)})()"): break
                time.sleep(.1)
            assert js("Number(document.querySelector('[aria-label=\"Resize sidebar\"]').getAttribute('aria-valuenow'))") == width
            assert js("document.querySelector('.reader-html').contentDocument.body.textContent.includes('Browser session content')")
            assert js("[...document.querySelector('.reader-html').contentDocument.images].some(i=>i.complete&&i.naturalWidth===2)")
            def wait_for(expression):
                for _ in range(150):
                    if js(expression): return
                    time.sleep(.1)
                raise AssertionError(expression)
            def click_label(label):
                js("[...document.querySelectorAll('button')].find(b=>b.textContent.trim()===" + json.dumps(label) + ").click()")
            wait_for("document.querySelectorAll('.conversation-summary').length === 2")
            assert js("document.querySelector('.conversation-selected').textContent.includes('Selected message')")
            js("document.querySelector('.conversation-summary[aria-expanded=false]').click()")
            wait_for("document.querySelectorAll('.reader-html').length === 2")
            js("[...document.querySelectorAll('.conversation-message')].find(e=>!e.classList.contains('conversation-selected')).querySelector('button').click()")
            account = detail['mail_account_id']
            for path in ['/mail/all','/mail/inbox','/mail/unread', f'/mail/account/{account}/all', f'/mail/account/{account}/inbox', f'/mail/account/{account}/unread', '/mail/sent', '/mail/archive']:
                cdp('Page.navigate', {'url': ROOT + path})
                wait_for("document.querySelectorAll('.message-row').length > 0")
                assert js("location.pathname") == path
                assert ('ACCOUNT MAILBOX' if '/account/' in path else 'GLOBAL WORKSPACE') in js("document.querySelector('.pane-toolbar').textContent")
                assert not js("document.querySelector('.sidebar').textContent.includes('Coming later')")
            cdp('Page.navigate', {'url': ROOT + '/mail/all'})
            wait_for("document.querySelectorAll('.message-row').length === 50")
            click_label('Load more')
            wait_for("document.querySelectorAll('.message-row').length === 67")
            js("document.querySelector('.message-row').click()")
            wait_for("document.querySelector('.reader-current-state')?.textContent.trim() === 'Unread'")
            counts_before = js("fetch('/api/mailbox-counts').then(r=>r.json())")
            click_label('Mark read')
            wait_for("document.querySelector('.reader-current-state')?.textContent.trim() === 'Read'")
            counts_after = js("fetch('/api/mailbox-counts').then(r=>r.json())")
            assert counts_after['views']['all']['total'] == counts_before['views']['all']['total']
            assert counts_after['views']['all']['unread'] == counts_before['views']['all']['unread'] - 1
            assert js("document.querySelector('.message-selected')?.classList.contains('message-unread')") is False
            click_label('Mark unread')
            wait_for("document.querySelector('.reader-current-state')?.textContent.trim() === 'Unread'")
            js("[...document.querySelectorAll('.message-row')].find(b=>b.textContent.includes('Acceptance plain')).click()")
            wait_for("document.querySelector('.reader-body')?.textContent.includes('<script>This stays text</script>')")
            assert js("document.querySelectorAll('.reader-body script').length") == 0
            plain_url = js('location.href')
            js('history.back()')
            wait_for("document.querySelector('.reader-html') !== null")
            js('history.forward()')
            wait_for("location.href === " + json.dumps(plain_url) + " && !!document.querySelector('.reader-body')")
            print('Integrated M3: eight scopes, pagination, chronological conversation/expand, safe plain text, read/unread/counts and history passed', flush=True)
            cdp('Page.navigate', {'url': ROOT + f'/mail/all?message={message}'})
            wait_for("document.querySelector('.reader-html')?.contentDocument?.body?.textContent.includes('Browser session content')")
            shot = cdp('Page.captureScreenshot', {'format':'png'})['data']
            import base64
            pathlib.Path('/tmp/mailcenter-repair-desktop.png').write_bytes(base64.b64decode(shot))
            cdp('Emulation.setDeviceMetricsOverride', {'width':1440, 'height':900, 'deviceScaleFactor':1, 'mobile':False})
            time.sleep(.2)
            assert not js('document.documentElement.scrollWidth > innerWidth')
            print('Layout:', json.dumps(layout), 'persisted sidebar:', width)
            for suffix in [f'attachments/{attachment}', f'inline/{inline}']:
                assert js(f"fetch('/api/messages/{other}/{suffix}',{{referrerPolicy:'no-referrer'}}).then(r=>r.status)") == 404
            assert logout() in [200,204]
            assert js('Promise.all(' + json.dumps(urls) + ".map(u=>fetch(u,{referrerPolicy:'no-referrer'}).then(r=>r.status)))") == [401,401,401]
            assert login('foreign') == 200
            assert js('Promise.all(' + json.dumps(urls) + ".map(u=>fetch(u,{referrerPolicy:'no-referrer'}).then(r=>r.status)))") == [404,404,404]
            print('PASS: real session iframe/CID decode, exact attachment bytes, cross-message/foreign denial, immediate 401 after logout.')
            cdp('Browser.close')
            chrome.wait(timeout=20)
    finally:
        if chrome.poll() is None:
            chrome.terminate()
            chrome.wait(timeout=20)
