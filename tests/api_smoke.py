"""Integration checks against a fresh temporary database; never uses school data.

Run: python tests/api_smoke.py [path/to/php]
"""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[1]
php = sys.argv[1] if len(sys.argv) > 1 else str(root / '.tools/php/php.exe')
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
base = f'http://127.0.0.1:{port}'
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(path, data=None, headers=None, method=None):
    if isinstance(data, dict):
        data = json.dumps(data).encode()
    req = urllib.request.Request(base + path, data=data, headers=headers or {}, method=method)
    try:
        response = client.open(req, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read().decode(errors='replace')

def api(resource, data=None, headers=None, expected=200):
    status, body = request('/api.php?resource=' + resource, data, headers)
    assert status == expected, (resource, status, body)
    return json.loads(body)

(root / '.tools').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='school-api-', dir=root / '.tools') as storage:
    with open(Path(storage) / 'server.log', 'w+') as log:
        server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'), str(root / 'router.php')],
                                  cwd=root, env={**os.environ, 'APP_STORAGE_PATH': storage}, stdout=log, stderr=log)
        try:
            for _ in range(60):
                try:
                    status, page = request('/')
                    break
                except urllib.error.URLError:
                    time.sleep(.1)
            else:
                raise AssertionError('Server did not start')
            assert status == 200 and 'loginForm' in page
            csrf = re.search(r'csrf:"([a-f0-9]+)"', page).group(1)
            assert api('health')['status'] == 'ok'
            for path in ['/private/api.php', '/storage/northstar.sqlite', '/.git/config',
                         '/student_mobile_app/pubspec.yaml', '/mobile_api/login.php',
                         '/%2e%2e/private/api.php', '/assets/../../private/bootstrap.php']:
                assert request(path)[0] == 404, path
            for path in ['/app.js', '/styles.css', '/assets/wg-logo.png']:
                assert request(path)[0] == 200, path
            api('bootstrap', expected=401)
            api('mobile-dashboard', expected=401)
            api('login', {'email':'admin@scschool.local','password':'admin123'}, expected=419)
            admin_headers = {'X-CSRF-Token': csrf, 'Content-Type': 'application/json'}
            assert api('login', {'email':'admin@scschool.local','password':'admin123'}, admin_headers)['ok']
            assert 'students' in api('bootstrap')
            api('mobile-login', b'{', expected=400)
            api('mobile-login', {'student_id':'ST-2041','password':'wrong'}, expected=401)
            login = api('mobile-login', {'student_id':'ST-2041','password':'5550142210'})
            token_headers = {'Authorization': 'Bearer ' + login['token']}
            dashboard = api('mobile-dashboard', headers=token_headers)
            assert dashboard['student']['id'] == 'ST-2041'
            assert api('mobile-logout', {}, token_headers)['ok']
            api('mobile-dashboard', headers=token_headers, expected=401)
            assert request('/api.php', headers={'Origin':'http://localhost:8080'}, method='OPTIONS')[0] == 204
            print('PASS: web/assets, private paths, CSRF, admin login/bootstrap, JSON validation, student login/dashboard/logout and CORS preflight')
        finally:
            server.terminate()
            server.wait(timeout=10)
