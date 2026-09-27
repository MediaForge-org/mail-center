"""Chromium + real Redis workers + guarded PostgreSQL fixture; no provider credentials.
Usage: python3 tests/browser/sync-latency.py [manual|idle] [iterations=30]
Synthetic remote bytes/events; actual queue, engine, commits, API, Vue and browser rendering.
"""
import json, math, pathlib, subprocess, sys, tempfile, time, urllib.request
from websockets.sync.client import connect
ROOT = 'http://127.0.0.1:8072'
mode = sys.argv[1] if len(sys.argv)>1 else 'manual'
iterations = int(sys.argv[2]) if len(sys.argv)>2 else 30
connect_ms = int(sys.argv[3]) if len(sys.argv)>3 else 0
samples = {}
traces = {}
with tempfile.TemporaryDirectory(prefix='m311-chrome-', ignore_cleanup_errors=True) as profile:
    chrome = subprocess.Popen(['google-chrome', '--headless', '--no-sandbox', '--disable-gpu', '--disable-background-networking', '--disable-dev-shm-usage', '--no-proxy-server', '--no-first-run', '--remote-debugging-port=0', '--user-data-dir='+profile, 'about:blank'], stderr=subprocess.DEVNULL)
    try:
        portfile = pathlib.Path(profile, 'DevToolsActivePort')
        for _ in range(100):
            if portfile.exists(): break
            time.sleep(.1)
        port = portfile.read_text().splitlines()[0]
        tab = json.load(urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}/json/new?about:blank', method='PUT')))
        with connect(tab['webSocketDebuggerUrl'], proxy=None) as ws:
            sequence=0
            def cdp(method, params=None):
                global sequence
                sequence+=1
                ws.send(json.dumps({'id':sequence,'method':method,'params':params or {}}))
                while True:
                    result=json.loads(ws.recv(timeout=60))
                    if result.get('id')==sequence:
                        assert 'error' not in result, result
                        return result.get('result',{})
            def js(code):
                result=cdp('Runtime.evaluate',{'expression':code,'awaitPromise':True,'returnByValue':True,'userGesture':True})
                assert 'exceptionDetails' not in result, result
                return result.get('result',{}).get('value')
            cdp('Page.enable')
            cdp('Page.addScriptToEvaluateOnNewDocument', {'source': '''
                window.journal=[]; const original=window.fetch;
                window.fetch=async(...args)=>{const start=Date.now()/1000; const r=await original(...args);
                    const url=new URL(String(args[0]),location.href).pathname + new URL(String(args[0]),location.href).search; if(url.startsWith('/api/')) {
                    r.clone().json().then(data=>window.journal.push({url,start,at:Date.now()/1000,status:r.status,data,serverStart:Number(r.headers.get('X-M311-Request-Start')),bootEnd:Number(r.headers.get('X-M311-Boot-End'))})).catch(()=>{});
                    } return r;};
            '''})
            cdp('Page.navigate',{'url':ROOT+'/login'})
            for _ in range(100):
                if js('document.readyState')=='complete': break
                time.sleep(.05)
            assert js('''(async()=>{await fetch('/sanctum/csrf-cookie');const token=decodeURIComponent(document.cookie.split('; ').find(x=>x.startsWith('XSRF-TOKEN=')).split('=')[1]);return (await fetch('/login',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':token},body:JSON.stringify({email:'latency@browser.test',password:'browser-test-password'})})).status})()''')==200
            state=js("fetch('/__fixture/setup?case=status').then(r=>r.json())")
            account=state['account']['id']
            cdp('Page.navigate',{'url':ROOT+f'/mail/account/{account}/all'})
            for _ in range(150):
                if js("!!document.querySelector('.message-row')"): break
                time.sleep(.05)
            cases=['A','B','C','D','E','F','G'] if mode=='manual' else ['idle-inbox','idle-sent']
            if len(sys.argv)>4: cases=sys.argv[4].split(',')
            for case in cases:
                samples[case]=[]
                traces[case]=[]
                for iteration in range(iterations):
                    for _ in range(300):
                        state=js("fetch('/__fixture/setup?case=status').then(r=>r.json())")
                        if state['account']['sync_requested_generation']==state['account']['sync_completed_generation']: break
                        time.sleep(.05)
                    else: raise AssertionError(('previous work did not finish',state))
                    if case=='G':
                        js("fetch('/__fixture/setup?case=scheduled').then(r=>r.json())")
                        for _ in range(100):
                            state=js("fetch('/__fixture/setup?case=status').then(r=>r.json())")
                            if state['connect_started']: break
                            time.sleep(.01)
                    js('window.journal=[]')
                    fixture=js(f"fetch('/__fixture/setup?case={case}&connect_ms={connect_ms}').then(r=>r.json())")
                    subject=fixture['subject']
                    start=js('Date.now()/1000') if mode=='manual' else fixture['at']
                    js('''(()=>{window.renderedAt=null;window.rowObserver?.disconnect();const match=()=>[...document.querySelectorAll('.message-row')].some(r=>r.textContent.includes('''+json.dumps(subject)+'''));window.rowObserver=new MutationObserver(()=>{if(match()&&!window.renderedAt)requestAnimationFrame(()=>window.renderedAt??=Date.now()/1000)});window.rowObserver.observe(document.body,{childList:true,subtree:true,characterData:true});if(match())window.renderedAt=Date.now()/1000})()''')
                    if mode=='manual':
                        assert js("(()=>{const b=[...document.querySelectorAll('button')].find(b=>b.textContent.trim()==='Sync now');if(!b||b.disabled)return false;b.click();return true})()"), 'Sync now unavailable'
                    accepted=None
                    for _ in range(300):
                        journal=js('window.journal')
                        accepts=[e for e in journal if e['url'].endswith('/sync') and e['status']==202]
                        if accepts: accepted=accepts[0]
                        if case=='E' and accepted and len(accepts)==1:
                            js(f'''(async()=>{{const token=decodeURIComponent(document.cookie.split('; ').find(x=>x.startsWith('XSRF-TOKEN=')).split('=')[1]); await Promise.all([1,2,3].map(()=>fetch('/api/accounts/{account}/sync',{{method:'POST',headers:{{Accept:'application/json','X-XSRF-TOKEN':token}}}})));}})()''')
                        rendered=js('window.renderedAt')
                        if case=='A' and accepted:
                            complete=[e for e in journal if e['url']=='/api/accounts' and any(a['id']==account and a.get('sync_request',{}).get('completed_generation',0)>=accepted['data']['data']['generation'] for a in e['data'].get('data',[]))]
                            if complete and js("document.querySelector('.sync-summary')?.textContent.includes('Up to date')"):
                                rendered=js('Date.now()/1000')
                        if rendered and (accepted or mode=='idle'): break
                        time.sleep(.02)
                    else: raise AssertionError((case,'not rendered',journal[-5:],js('document.body.innerText')[:1500]))
                    # Read completed telemetry only after rendering; this does not refresh the Vue list.
                    for _ in range(400):
                        state=js("fetch('/__fixture/setup?case=status').then(r=>r.json())")
                        if state['account']['sync_requested_generation']==state['account']['sync_completed_generation']: break
                        time.sleep(.02)
                    runs=[]
                    for run in state['runs']:
                        timing=json.loads(run['timings']) if isinstance(run['timings'],str) else run['timings']
                        if timing.get('first_message_committed', timing.get('run_finished',0))>=start-.05:
                            runs.append(timing)
                    assert runs,(case,state)
                    timing=min(runs,key=lambda t:t.get('first_message_committed',t.get('run_finished',1e30)))
                    commit=timing.get('first_message_committed',timing['run_finished'])
                    journal=js('window.journal')
                    changes=[e['at'] for e in journal if e['url'].startswith('/api/changes') and e['data'].get('invalidate') and e['at']>=commit-.001]
                    # A reload triggered just before commit can already include the new row.
                    observed=min(rendered, min(changes)) if changes else rendered
                    accepted_at=accepted['data']['data']['accepted_at'] if accepted else start
                    result={'api_ms':(accepted['at']-accepted['start'])*1000 if accepted else 0,
                        'accepted_to_worker_ms':max(0,timing['worker_started']-accepted_at)*1000,
                        'worker_to_commit_ms':(commit-timing['worker_started'])*1000,
                        'lock_ms':(timing['lock_acquired']-timing['worker_started'])*1000,
                        'connect_ms':(timing['connected']-timing['connect_started'])*1000,
                        'ingestion_ms':(commit-timing.get('first_ingestion_started',commit))*1000,
                        'commit_to_poll_ms':max(0,observed-commit)*1000,
                        'poll_to_render_ms':max(0,rendered-observed)*1000,
                        'total_ms':(rendered-start)*1000}
                    samples[case].append(result)
                    traces[case].append({'run':timing,'click_or_event':start,'accepted':accepted_at,'api_response':accepted['at'] if accepted else None,'server_start':accepted.get('serverStart') if accepted else None,'boot_end':accepted.get('bootEnd') if accepted else None,'freshness_observed':observed,'rendered':rendered})
                def percent(values,p):
                    return sorted(values)[math.ceil(len(values)*p)-1]
                summary={key:{'p50':round(percent([s[key] for s in samples[case]],.5),2),'p95':round(percent([s[key] for s in samples[case]],.95),2)} for key in samples[case][0]}
                print(json.dumps({'case':case,'iterations':iterations,'metrics':summary}),flush=True)
                pathlib.Path('/tmp/m311-'+mode+'-samples.json').write_text(json.dumps(samples,indent=2))
                pathlib.Path('/tmp/m311-'+mode+'-traces.json').write_text(json.dumps(traces,indent=2))
    finally:
        chrome.terminate()
        chrome.wait(timeout=10)
