"""Realtime freshness acceptance: real Chromium, real Redis workers/Horizon, guarded PostgreSQL fixture,
synthetic IMAP IDLE events. No provider credentials.

Usage: python3 tests/browser/realtime-acceptance.py <inbox|sent> <iterations> <warm|cold> [connect_ms]

warm: watcher alive before the page loads (account reports Realtime).
cold: the page loads while no watcher is alive (account reports Polling), then the watcher starts.
      This reproduces a user opening the app around a watcher restart.
Each sample records: event->sync start, sync start->commit, commit->freshness observed,
observed->rendered, totals, plus flicker evidence sampled on every animation frame
(min visible rows, loading placeholder frames, min visible sidebar counts, sidebar Sent count).
"""
import json, math, pathlib, subprocess, sys, tempfile, time, urllib.request
from websockets.sync.client import connect

ROOT = 'http://127.0.0.1:8072'
scenario = sys.argv[1]
iterations = int(sys.argv[2])
cold = (sys.argv[3] if len(sys.argv) > 3 else 'warm') == 'cold'
connect_ms = int(sys.argv[4]) if len(sys.argv) > 4 else 0
tag = sys.argv[5] if len(sys.argv) > 5 else 'run'
warmup = int(sys.argv[6]) if len(sys.argv) > 6 else 0  # unmeasured priming events, kept in the file, excluded from percentiles
FIXTURE = 'mailcenter-m311-fixture'


def watcher(start):
    if start:
        subprocess.run(['docker', 'exec', '-d', FIXTURE, 'sh', '-c', 'php tests/performance/sync-worker.php watch > /tmp/m311-watch.log 2>&1'], check=True)
    else:
        subprocess.run(['docker', 'exec', FIXTURE, 'sh', '-c', 'for d in /proc/[0-9]*; do if tr "\\0" " " < $d/cmdline 2>/dev/null | grep -q "^php tests/performance/sync-worke[r].php watch"; then kill ${d#/proc/}; fi; done'], check=False)
        # SIGTERM must release the leases (finally -> close()); a failure here is a product defect, not noise.
        time.sleep(1.5)
        owned = subprocess.run(['docker', 'exec', 'mailcenter-postgres-test-1', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d mailcenter_test -X -t -A -c "select count(*) from sync_watchers where owner is not null"'], check=True, capture_output=True, text=True).stdout.strip()
        assert owned == '0', f'watcher leases were not released on SIGTERM ({owned} still owned)'


def pct(values, p):
    return sorted(values)[math.ceil(len(values) * p) - 1]


samples, traces = [], []
with tempfile.TemporaryDirectory(prefix='m311-chrome-', ignore_cleanup_errors=True) as profile:
    chrome = subprocess.Popen(['google-chrome', '--headless', '--no-sandbox', '--disable-gpu', '--disable-background-networking', '--disable-dev-shm-usage', '--no-proxy-server', '--no-first-run', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'], stderr=subprocess.DEVNULL)
    try:
        portfile = pathlib.Path(profile, 'DevToolsActivePort')
        for _ in range(100):
            if portfile.exists():
                break
            time.sleep(.1)
        port = portfile.read_text().splitlines()[0]
        tab = json.load(urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}/json/new?about:blank', method='PUT')))
        with connect(tab['webSocketDebuggerUrl'], proxy=None) as ws:
            sequence = 0

            def cdp(method, params=None):
                global sequence
                sequence += 1
                ws.send(json.dumps({'id': sequence, 'method': method, 'params': params or {}}))
                while True:
                    result = json.loads(ws.recv(timeout=90))
                    if result.get('id') == sequence:
                        assert 'error' not in result, result
                        return result.get('result', {})

            def js(code):
                result = cdp('Runtime.evaluate', {'expression': code, 'awaitPromise': True, 'returnByValue': True, 'userGesture': True})
                assert 'exceptionDetails' not in result, result
                return result.get('result', {}).get('value')

            cdp('Page.enable')
            cdp('Page.addScriptToEvaluateOnNewDocument', {'source': '''
                window.journal=[]; const original=window.fetch;
                window.fetch=async(...args)=>{const start=Date.now()/1000; const r=await original(...args);
                    const u=new URL(String(args[0]),location.href); const url=u.pathname+u.search;
                    if(url.startsWith('/api/')) r.clone().json().then(data=>window.journal.push({url,start,at:Date.now()/1000,status:r.status,data})).catch(()=>{});
                    return r;};
                // Per-frame flicker recorder: what is actually painted while a refresh happens.
                window.flick={active:false,frames:0,rowsMin:1e9,rowsMax:0,loadingFrames:0,countsMin:1e9,countsMax:0,readerLoadingFrames:0,startRows:null};
                const tick=()=>{const f=window.flick; if(f.active){const rows=document.querySelectorAll('.message-row').length;
                    f.frames++; f.rowsMin=Math.min(f.rowsMin,rows); f.rowsMax=Math.max(f.rowsMax,rows);
                    if((document.querySelector('.message-feed-footer')?.textContent||'').includes('Loading mailbox')) f.loadingFrames++;
                    if((document.querySelector('.conversation-reader')?.textContent||'').includes('Loading conversation')) f.readerLoadingFrames++;
                    const c=document.querySelectorAll('.mailbox-count').length; f.countsMin=Math.min(f.countsMin,c); f.countsMax=Math.max(f.countsMax,c);}
                    requestAnimationFrame(tick)}; requestAnimationFrame(tick);
            '''})
            cdp('Page.navigate', {'url': ROOT + '/login'})
            for _ in range(100):
                if js('document.readyState') == 'complete':
                    break
                time.sleep(.05)
            assert js('''(async()=>{await fetch('/sanctum/csrf-cookie');const token=decodeURIComponent(document.cookie.split('; ').find(x=>x.startsWith('XSRF-TOKEN=')).split('=')[1]);return (await fetch('/login',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':token},body:JSON.stringify({email:'latency@browser.test',password:'browser-test-password'})})).status})()''') == 200
            state = js("fetch('/__fixture/setup?case=status').then(r=>r.json())")
            account = state['account']['id']
            view = 'sent' if scenario == 'sent' else 'inbox'
            want_roles = 2

            def load_page():
                cdp('Page.navigate', {'url': ROOT + f'/mail/account/{account}/{view}'})
                for _ in range(200):
                    if js("!!document.querySelector('.message-row') && !!document.querySelector('.mailbox-count')"):
                        return
                    time.sleep(.05)
                raise AssertionError(('page did not render', js('document.body.innerText')[:800]))

            def wait_watching():
                for _ in range(300):
                    data = js("fetch('/api/accounts').then(r=>r.json())")['data'][0]
                    if data['realtime']['state'] == 'watching' and len(data['realtime'].get('roles', data['realtime']['folders'])) >= want_roles:
                        return
                    time.sleep(.1)
                raise AssertionError('watcher leases did not become healthy')

            def sidebar_count(label):
                return js("(()=>{const b=[...document.querySelectorAll('.nav-button')].find(b=>b.textContent.includes(%s));return b?.querySelector('.mailbox-count')?.textContent??null})()" % json.dumps(label))

            watcher(False)
            if not cold:
                watcher(True)
                wait_watching()
            load_page()
            expected_sent = int(sidebar_count('Sent'))
            initial_sent = expected_sent
            for iteration in range(iterations + warmup):
                if cold and iteration > 0:
                    watcher(False)
                    load_page()
                if cold:
                    ui_state = js("document.querySelector('.sync-summary')?.textContent||''")
                    watcher(True)
                    for _ in range(300):  # server-side leases healthy; the already-open page is unaware
                        data = js("fetch('/api/accounts').then(r=>r.json())")['data'][0]
                        if data['realtime']['state'] == 'watching' and len(data['realtime'].get('roles', data['realtime']['folders'])) >= want_roles:
                            break
                        time.sleep(.1)
                    time.sleep(1)
                for _ in range(300):
                    state = js("fetch('/__fixture/setup?case=status').then(r=>r.json())")
                    if state['account']['sync_requested_generation'] == state['account']['sync_completed_generation']:
                        break
                    time.sleep(.05)
                time.sleep(.6)
                sent_before = sidebar_count('Sent')
                assert sent_before == str(expected_sent), ('Sent count before', sent_before, expected_sent)
                js('window.journal=[]; window.renderedAt=null; window.flick.frames=0;window.flick.rowsMin=1e9;window.flick.rowsMax=0;window.flick.loadingFrames=0;window.flick.readerLoadingFrames=0;window.flick.countsMin=1e9;window.flick.countsMax=0;window.flick.active=true')
                start_rows = js("document.querySelectorAll('.message-row').length")
                fixture = js(f"fetch('/__fixture/setup?case=idle-{scenario}&connect_ms={connect_ms}').then(r=>r.json())")
                subject, event_at = fixture['subject'], fixture['at']
                js('''(()=>{window.rowObserver?.disconnect();const match=()=>[...document.querySelectorAll('.message-row')].some(r=>r.textContent.includes('''+json.dumps(subject)+'''));window.rowObserver=new MutationObserver(()=>{if(match()&&!window.renderedAt)requestAnimationFrame(()=>window.renderedAt??=Date.now()/1000)});window.rowObserver.observe(document.body,{childList:true,subtree:true,characterData:true});if(match())window.renderedAt=Date.now()/1000})()''')
                deadline = time.time() + 75
                while time.time() < deadline and not js('window.renderedAt'):
                    time.sleep(.02)
                rendered = js('window.renderedAt')
                assert rendered, ('not rendered within 75s', js('document.body.innerText')[:600])
                expected_sent += 1 if scenario == 'sent' else 0
                # Let the burst of follow-up refreshes/counts settle so flicker evidence is complete.
                for _ in range(200):
                    counted = sidebar_count('Sent')
                    if counted == str(expected_sent):
                        break
                    time.sleep(.05)
                time.sleep(1.5)
                flick = js('JSON.parse(JSON.stringify(window.flick))')
                js('window.flick.active=false')
                sent_after = sidebar_count('Sent')
                all_after = sidebar_count('All')
                for _ in range(400):
                    state = js("fetch('/__fixture/setup?case=status').then(r=>r.json())")
                    if state['account']['sync_requested_generation'] == state['account']['sync_completed_generation']:
                        break
                    time.sleep(.02)
                runs = []
                for run in state['runs']:
                    timing = json.loads(run['timings']) if isinstance(run['timings'], str) else run['timings']
                    if timing.get('first_message_committed', 0) >= event_at - .05:
                        runs.append(timing)
                assert runs, ('no committing run', state['runs'])
                timing = min(runs, key=lambda t: t['first_message_committed'])
                commit = timing['first_message_committed']
                journal = js('window.journal')
                changes = [e['at'] for e in journal if e['url'].startswith('/api/changes') and e['data'].get('invalidate') and e['at'] >= commit - .001]
                observed = min(rendered, min(changes)) if changes else rendered
                result = {
                    'event_to_sync_start_ms': (timing['worker_started'] - event_at) * 1000,
                    'sync_start_to_commit_ms': (commit - timing['worker_started']) * 1000,
                    'commit_to_observed_ms': max(0, observed - commit) * 1000,
                    'observed_to_rendered_ms': max(0, rendered - observed) * 1000,
                    'commit_to_rendered_ms': (rendered - commit) * 1000,
                    'event_to_commit_ms': (commit - event_at) * 1000,
                    'event_to_rendered_ms': (rendered - event_at) * 1000,
                    'ui_realtime_at_event': None,
                    'flicker': {'start_rows': start_rows, 'rows_min': flick['rowsMin'], 'rows_max': flick['rowsMax'], 'loading_frames': flick['loadingFrames'], 'reader_loading_frames': flick['readerLoadingFrames'], 'counts_min': flick['countsMin'], 'counts_max': flick['countsMax'], 'frames': flick['frames']},
                    'sidebar_sent_after': sent_after, 'sidebar_all_after': all_after,
                    'change_polls': len([e for e in journal if e['url'].startswith('/api/changes')]),
                    'list_fetches': len([e for e in journal if e['url'].startswith('/api/messages?')]),
                    'account_fetches': len([e for e in journal if e['url'] == '/api/accounts']),
                    'count_fetches': len([e for e in journal if e['url'].startswith('/api/mailbox-counts')]),
                }
                if cold:
                    result['ui_realtime_at_event'] = ui_state
                result['warmup'] = iteration < warmup
                samples.append(result)
                traces.append({'run': timing, 'event': event_at, 'observed': observed, 'rendered': rendered, 'subject': subject})
                if scenario == 'sent' and iteration == 0:
                    js("[...document.querySelectorAll('.message-row')].find(r=>r.textContent.includes(%s))?.click()" % json.dumps(subject))
                    for _ in range(100):
                        text = js("document.querySelector('.conversation-reader')?.innerText||''")
                        if 'Initial 2' in text and subject in text:
                            break
                        time.sleep(.05)
                    else:
                        raise AssertionError(('sent reply is not in the received conversation', text[:600]))
                    result['conversation_contains_parent'] = True
                    js("history.back()")
                    time.sleep(.5)
            measured = [s for s in samples if not s['warmup']]
            keys = [k for k in samples[0] if isinstance(samples[0][k], (int, float)) and not isinstance(samples[0][k], bool)]
            summary = {k: {'p50': round(pct([s[k] for s in measured], .5), 1), 'p95': round(pct([s[k] for s in measured], .95), 1)} for k in keys}
            flick_all = {'min_rows_ever_below_start': any(s['flicker']['rows_min'] < s['flicker']['start_rows'] for s in samples),
                         'loading_frames_total': sum(s['flicker']['loading_frames'] for s in samples),
                         'reader_loading_frames_total': sum(s['flicker']['reader_loading_frames'] for s in samples),
                         'counts_disappeared': any(s['flicker']['counts_min'] < s['flicker']['counts_max'] for s in samples)}
            out = {'scenario': scenario, 'mode': 'cold' if cold else 'warm', 'iterations': iterations, 'warmup_events_excluded': warmup, 'connect_ms': connect_ms, 'summary': summary, 'flicker': flick_all, 'initial_sent_count': initial_sent, 'sent_counts_exact': expected_sent == initial_sent + (iterations + warmup if scenario == 'sent' else 0)}
            print(json.dumps(out, indent=1), flush=True)
            pathlib.Path(f'/tmp/m311-rt-{tag}-{scenario}-{"cold" if cold else "warm"}.json').write_text(json.dumps({'result': out, 'samples': samples, 'traces': traces}, indent=2))
    finally:
        watcher(False)
        chrome.terminate()
        chrome.wait(timeout=10)
