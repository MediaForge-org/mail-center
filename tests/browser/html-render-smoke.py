"""Database-free Chromium smoke test using real sanitizer output and render CSP.
Run from repository root: python3 tests/browser/html-render-smoke.py
Requires Docker app container and google-chrome. Never boots Laravel or opens a DB.
"""
import json
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

fixture = json.loads(subprocess.check_output([
    "docker", "compose", "exec", "-T", "app", "php", "tests/browser/html-render-fixture.php"
]))
requests = []
sandbox = "allow-same-origin allow-popups allow-popups-to-escape-sandbox"

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        requests.append(self.path)
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        if self.path == "/render":
            self.send_header("Content-Security-Policy", fixture["csp"])
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Referrer-Policy", "no-referrer")
            self.send_header("Cache-Control", "private, no-store")
            body = '<!doctype html><title>Message</title>' + fixture["html"]
        else:
            body = ('<!doctype html><title>Shell</title><iframe src="/render" sandbox="'
                    + sandbox + '" referrerpolicy="no-referrer"></iframe>'
                    + '<script>document.querySelector("iframe").onload=function(){'
                    + 'document.body.dataset.result=this.contentDocument.title==="Message"'
                    + ' && this.contentDocument.body.textContent.includes("Allowed content")'
                    + ' ? "PASS" : "FAIL"}</script>')
        self.end_headers()
        self.wfile.write(body.encode())

    def log_message(self, *_):
        pass

server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
try:
    with tempfile.TemporaryDirectory(prefix="mailcenter-browser-") as profile:
        base = ["google-chrome", "--headless", "--no-sandbox", "--disable-gpu",
                "--disable-background-networking", "--no-first-run",
                "--user-data-dir=" + profile, "--virtual-time-budget=3000", "--dump-dom"]
        root = "http://127.0.0.1:" + str(server.server_port)
        framed = subprocess.check_output(base + [root + "/"], stderr=subprocess.DEVNULL, timeout=30).decode()
        assert 'data-result="PASS"' in framed, framed
        direct = subprocess.check_output(base + [root + "/render"], stderr=subprocess.DEVNULL, timeout=30).decode()
        assert "<title>Message</title>" in direct and "Allowed content" in direct
        assert all(path in ["/", "/render", "/favicon.ico"] for path in requests), requests
        # Sanitized content has no automatic resource-bearing attributes or elements.
        assert all(token not in fixture["html"] for token in ["src=", "srcset=", "<script", "<form", "<style", "<meta"])
        print("PASS: framed and direct HTML, no script execution/top navigation/form or resource requests.")
finally:
    server.shutdown()
