"""Run behind an HTTPS reverse proxy; bind exclusively to loopback."""
import hmac
import json
import os
import re
import subprocess
from http.server import BaseHTTPRequestHandler, HTTPServer

TOKEN = os.environ['AGENT_TOKEN']
UNITS = set(os.environ['AGENT_UNITS'].split(','))
if len(TOKEN) < 32 or not UNITS or any(not re.fullmatch(r'[a-zA-Z0-9_-]+\.service', unit) for unit in UNITS):
    raise ValueError('Use a token of at least 32 characters and explicit .service names')

class Handler(BaseHTTPRequestHandler):
    def reply(self, code, data):
        payload = json.dumps(data).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_POST(self):
        if not hmac.compare_digest(self.headers.get('Authorization', ''), 'Bearer ' + TOKEN):
            return self.reply(401, {'error': 'Unauthorized'})
        if self.path != '/v1/action':
            return self.reply(404, {'error': 'Not found'})
        try:
            size = int(self.headers.get('Content-Length', '0'))
            if not 0 < size <= 2048:
                return self.reply(400, {'error': 'Invalid body size'})
            data = json.loads(self.rfile.read(size))
            if not isinstance(data, dict):
                return self.reply(400, {'error': 'Invalid body'})
            unit, action = data.get('unit'), data.get('action')
            if not isinstance(unit, str) or unit not in UNITS or action not in ('status', 'start', 'stop', 'restart'):
                return self.reply(403, {'error': 'Operation not allowed'})
            if action == 'status':
                result = subprocess.run(['/usr/bin/systemctl', 'show', '--property=ActiveState', '--value', unit], capture_output=True, text=True, timeout=8)
                if result.returncode:
                    return self.reply(502, {'error': 'Status query failed'})
                return self.reply(200, {'state': result.stdout.strip()})
            result = subprocess.run(['/usr/bin/sudo', '-n', '/usr/bin/systemctl', action, unit], capture_output=True, text=True, timeout=12)
            return self.reply(200 if result.returncode == 0 else 502, {'accepted': result.returncode == 0})
        except (ValueError, TypeError):
            return self.reply(400, {'error': 'Invalid body'})
        except (OSError, subprocess.TimeoutExpired):
            return self.reply(502, {'error': 'Service operation failed or timed out; check status'})

    def log_message(self, fmt, *args):
        pass

if __name__ == '__main__':
    HTTPServer(('127.0.0.1', 9081), Handler).serve_forever()
