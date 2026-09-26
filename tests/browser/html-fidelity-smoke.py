"""Compare a trusted transactional source, former stripped presentation, and sanitized v4 in Chromium.
Database-free; source references are replaced/blocked by the fixture and every frame has production CSP.
"""
import base64
import json
import pathlib
import subprocess
import tempfile
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from websockets.sync.client import connect

fixture = json.loads(subprocess.check_output(['docker','compose','exec','-T','app','php','tests/browser/html-render-fixture.php','https://attacker.invalid/pixel','--fidelity']))
requests = []
class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        requests.append(self.path)
        self.send_response(200)
        if self.path == '/api/messages/42/inline/1':
            self.send_header('Content-Type','image/png')
            body = base64.b64decode(fixture['images']['1'])
        elif self.path in ['/source','/before','/after']:
            self.send_header('Content-Type','text/html; charset=utf-8')
            self.send_header('Content-Security-Policy',fixture['csp'])
            if self.path == '/source': body = fixture['source'].encode()
            else:
                css = fixture['css'] if self.path == '/after' else 'body{font:14px/1.6 system-ui,sans-serif;margin:16px;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%;border-collapse:collapse}td{padding:4px}a{color:#315acb}'
                body = ('<!doctype html><style>'+css+'</style>'+fixture['html' if self.path == '/after' else 'before']).encode()
        else:
            self.send_header('Content-Type','text/html; charset=utf-8')
            body = ('<!doctype html><style>body{margin:0;font:14px Arial;background:#eee;display:flex}section{width:33.333%;min-width:0}h2{padding:0 12px}iframe{border:0;width:100%;height:900px}</style>'+''.join('<section><h2>'+label+'</h2><iframe src="/'+path+'" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe></section>' for path,label in [('source','Source template'),('before','Previously stripped'),('after','Sanitized v4')])).encode()
        self.send_header('Referrer-Policy','no-referrer')
        self.send_header('X-Content-Type-Options','nosniff')
        self.end_headers()
        self.wfile.write(body)
    def log_message(self,*_): pass

server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
root=f'http://127.0.0.1:{server.server_port}'
try:
    with tempfile.TemporaryDirectory(prefix='mailcenter-fidelity-') as profile:
        chrome=subprocess.Popen(['google-chrome','--headless','--no-sandbox','--disable-gpu','--disable-background-networking','--no-first-run','--no-proxy-server','--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'],stderr=subprocess.DEVNULL)
        try:
            port_file=pathlib.Path(profile,'DevToolsActivePort')
            for _ in range(100):
                if port_file.exists(): break
                time.sleep(.1)
            port=port_file.read_text().splitlines()[0]
            tab=json.load(urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}/json/new?about:blank',method='PUT')))
            with connect(tab['webSocketDebuggerUrl'],proxy=None) as ws:
                seq=0
                network=[]
                def cdp(method,params=None):
                    global seq
                    seq+=1
                    ws.send(json.dumps({'id':seq,'method':method,'params':params or {}}))
                    while True:
                        r=json.loads(ws.recv(timeout=30))
                        if r.get('method')=='Network.requestWillBeSent': network.append(r['params']['request']['url'])
                        if r.get('id')==seq:
                            assert 'error' not in r,r
                            return r.get('result',{})
                def js(expression):
                    r=cdp('Runtime.evaluate',{'expression':expression,'returnByValue':True,'awaitPromise':True})
                    assert 'exceptionDetails' not in r,r
                    return r['result'].get('value')
                cdp('Network.enable')
                cdp('Emulation.setDeviceMetricsOverride',{'width':1800,'height':1000,'deviceScaleFactor':1,'mobile':False})
                cdp('Page.navigate',{'url':root})
                for _ in range(150):
                    if js("[...document.querySelectorAll('iframe')].length===3 && [...document.querySelectorAll('iframe')].every(f=>f.contentDocument?.querySelector('h1') && f.contentDocument.images[0]?.naturalWidth===2)"): break
                    time.sleep(.1)
                metrics=js("""[...document.querySelectorAll('iframe')].map(f=>{const d=f.contentDocument,w=f.contentWindow,h=d.querySelector('h1'),a=d.querySelector('a'),t=d.querySelectorAll('table')[1],i=d.images[0],cs=e=>w.getComputedStyle(e);return {heading:cs(h).fontSize,color:cs(h).color,align:cs(h).textAlign.replace('-webkit-',''),button:cs(a).backgroundColor,padding:cs(a).padding,radius:cs(t).borderRadius,border:cs(t).borderTopWidth,tableWidth:t.getBoundingClientRect().width,tableLeft:t.getBoundingClientRect().left,imageWidth:i.getBoundingClientRect().width,imageTop:i.getBoundingClientRect().top}})""")
                source,before,after=metrics
                for key in ['heading','color','align','button','padding','radius','border','tableWidth','tableLeft','imageWidth','imageTop']:
                    assert source[key]==after[key],(key,metrics)
                assert before['heading']!=source['heading'] and before['button']!=source['button'] and before['padding']!=source['padding'],metrics
                assert all(u.startswith(root) for u in network),network
                shot=cdp('Page.captureScreenshot',{'format':'png'})['data']
                pathlib.Path('/tmp/mailcenter-html-fidelity.png').write_bytes(base64.b64decode(shot))
                # Each iframe becomes 320px wide: app stays contained even when tables need inner scrolling.
                cdp('Emulation.setDeviceMetricsOverride',{'width':960,'height':1000,'deviceScaleFactor':1,'mobile':False})
                assert not js('document.documentElement.scrollWidth > innerWidth')
                assert all(p in ['/','/source','/before','/after','/favicon.ico','/api/messages/42/inline/1'] for p in requests),requests
                print('PASS: source/v4 match 11 layout/style metrics; prior stripped output differs; CID decoded; zero external requests; narrow layout contained. '+json.dumps(metrics))
                cdp('Browser.close')
                chrome.wait(timeout=20)
        finally:
            if chrome.poll() is None: chrome.terminate(); chrome.wait(timeout=20)
finally: server.shutdown()
