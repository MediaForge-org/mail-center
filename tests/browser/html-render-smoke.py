"""Database-free Chromium test of production sanitization, CID resolution and CSP.
Run: python3 tests/browser/html-render-smoke.py
Requires Docker app container and google-chrome; never boots Laravel or opens a DB.
Endpoint authorization is covered separately by isolated backend tests.
"""
import base64
import json
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

requests = []
remote_requests = []
sandbox = "allow-same-origin allow-popups allow-popups-to-escape-sandbox"
fixture = {}


class RemoteHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        remote_requests.append(self.path)
        self.send_response(200)
        self.end_headers()

    def log_message(self, *_):
        pass


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        requests.append(self.path)
        self.send_response(200)
        image_id = self.path.removeprefix("/api/messages/42/inline/")
        if self.path.startswith("/api/messages/42/inline/") and image_id in fixture["images"]:
            body = base64.b64decode(fixture["images"][image_id])
            self.send_header("Content-Type", "image/png" if image_id == "1" else "image/jpeg")
            self.send_header("Content-Length", str(len(body)))
        elif self.path in ["/render", "/csp-probe"]:
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Security-Policy", fixture["csp"])
            body = '<!doctype html><title>Message</title>' + fixture["html"]
            if self.path == "/csp-probe":
                # Prove the unchanged CSP also blocks a remote source if sanitization misses it.
                body += '<img src="' + remote_url + '">'
            body = body.encode()
        else:
            self.send_header("Content-Type", "text/html; charset=utf-8")
            body = ('<!doctype html><title>Shell</title><iframe src="/render" sandbox="'
                    + sandbox + '" referrerpolicy="no-referrer"></iframe>'
                    + '<script>document.querySelector("iframe").onload=function(){'
                    + 'const d=this.contentDocument;const images=[...d.querySelectorAll("img[src]")];'
                    + 'document.body.dataset.result=d.title==="Message"'
                    + ' && d.body.textContent.includes("Allowed content")'
                    + ' && images.length===2 && images.every(i=>i.complete&&i.naturalWidth===2&&i.naturalHeight===3)'
                    + ' ? "PASS" : "FAIL"}</script>').encode()
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Cache-Control", "private, no-store")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_):
        pass


server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
remote_server = ThreadingHTTPServer(("127.0.0.1", 0), RemoteHandler)
for service in [server, remote_server]:
    threading.Thread(target=service.serve_forever, daemon=True).start()
remote_url = "http://127.0.0.1:" + str(remote_server.server_port) + "/trap"
try:
    fixture = json.loads(subprocess.check_output([
        "docker", "compose", "exec", "-T", "app", "php", "tests/browser/html-render-fixture.php", remote_url
    ]))
    with tempfile.TemporaryDirectory(prefix="mailcenter-browser-") as profile:
        base = ["google-chrome", "--headless", "--no-sandbox", "--disable-gpu",
                "--disable-background-networking", "--no-first-run",
                "--user-data-dir=" + profile, "--virtual-time-budget=3000", "--dump-dom"]
        root = "http://127.0.0.1:" + str(server.server_port)
        framed = subprocess.check_output(base + [root + "/"], stderr=subprocess.DEVNULL, timeout=30).decode()
        assert 'data-result="PASS"' in framed, framed
        for path in ["/render", "/csp-probe"]:
            direct = subprocess.check_output(base + [root + path], stderr=subprocess.DEVNULL, timeout=30).decode()
            assert "<title>Message</title>" in direct and "Allowed content" in direct
        assert not remote_requests, remote_requests
        allowed = ["/", "/render", "/csp-probe", "/favicon.ico",
                   "/api/messages/42/inline/1", "/api/messages/42/inline/2"]
        assert all(path in allowed for path in requests), requests
        assert all(token not in fixture["html"] for token in ["srcset=", "<script", "<form", "<style", "<meta", "cid:", "attacker.invalid"])
        assert fixture["remote_count"] == 2
        print("PASS: PNG/JPEG decoded in sandboxed iframe; same-origin image routes only; zero remote requests, including CSP bypass probe; no script execution/navigation.")
finally:
    server.shutdown()
    remote_server.shutdown()
