"""Developer integration check: private Nginx/PHP-FPM processes and disposable SQLite only.

Never reads the project's .env or changes a system/panel configuration.
Run with python3 tests/Hosting/verify_nginx.py (requires nginx and PHP-FPM).
"""
import html
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import socket
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile


PROJECT = Path(__file__).resolve().parents[2]
NGINX = shutil.which("nginx")
WEBSERVER = os.environ.get("TEST_WEBSERVER", "nginx")
HTTPD = os.environ.get("TEST_HTTPD") or ("/opt/homebrew/opt/httpd/bin/httpd" if Path("/opt/homebrew/opt/httpd/bin/httpd").exists() else "/usr/sbin/apache2")
FPM = os.environ.get("TEST_PHP_FPM") or shutil.which("php-fpm")
if not FPM or subprocess.run([FPM, "-v"], capture_output=True).stdout.startswith(b"PHP 8.0"):
    FPM = "/opt/homebrew/opt/php/sbin/php-fpm"
assert NGINX and Path(FPM).is_file(), "Nginx and PHP-FPM >=8.2 are required."
PHP = os.environ.get('TEST_PHP_CLI') or str(Path(FPM).parent.parent / 'bin/php')


def port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


checks = 0


def expect(condition, message):
    global checks
    assert condition, message
    checks += 1


work_area = PROJECT / 'storage/framework/testing/hosting'
work_area.mkdir(parents=True, exist_ok=True)
with tempfile.TemporaryDirectory(prefix="run-", dir=work_area) as temporary:
    temp = Path(temporary)
    app = temp / "private-app"
    site = temp / "parent-site"
    site.mkdir()
    (site / "index.html").write_text("PARENT WEBSITE UNCHANGED")
    app.mkdir()
    if os.environ.get("TEST_PACKAGE"):
        with zipfile.ZipFile(os.environ["TEST_PACKAGE"]) as package:
            assert all(not Path(name).is_absolute() and '..' not in Path(name).parts for name in package.namelist()), 'Unsafe archive paths'
            package.extractall(app)
        if not (app / 'artisan').exists():
            wrappers = [child for child in app.iterdir() if child.is_dir()]
            assert len(wrappers) == 1 and (wrappers[0] / 'artisan').exists(), 'Unsupported archive wrapper'
            app = wrappers[0]
        subdirs = [d for d in app.iterdir() if d.is_dir()]
        if len(list(app.iterdir())) == 1 and subdirs:
            root_sub = subdirs[0]
            for item in list(root_sub.iterdir()):
                shutil.move(str(item), str(app))
            root_sub.rmdir()
    else:
        for directory in ["app", "config", "routes", "resources", "public", "database", "docs"]:
            shutil.copytree(PROJECT / directory, app / directory, symlinks=True, ignore=shutil.ignore_patterns("*.sqlite", "*.sqlite-*"))
        (app / "bootstrap/cache").mkdir(parents=True)
        shutil.copy(PROJECT / "bootstrap/app.php", app / "bootstrap/app.php")
        shutil.copy(PROJECT / "bootstrap/providers.php", app / "bootstrap/providers.php")
        for filename in ["artisan", "install.php", "index.php", "composer.json", ".htaccess", ".env.example", "deployment-private-check.txt"]:
            shutil.copy(PROJECT / filename, app / filename)
        (app / "vendor").symlink_to(PROJECT / "vendor", target_is_directory=True)
    for directory in ["framework/cache/data", "framework/sessions", "framework/views", "logs", "app/public", "app/private"]:
        (app / "storage" / directory).mkdir(parents=True, exist_ok=True)
    database = temp / "test.sqlite"
    database.touch()
    mysql = json.loads(Path(os.environ['TEST_MYSQL_CONFIG']).read_text()) if os.environ.get('TEST_MYSQL_CONFIG') else None
    connection = 'mysql' if mysql else 'sqlite'
    db_name = mysql['database'] if mysql else str(database)
    db_host = '127.0.0.1'
    db_port = mysql['port'] if mysql else 3306
    db_user = mysql['user'] if mysql else ''
    db_password = mysql['password'] if mysql else ''
    session_driver = 'database' if mysql else 'file'

    def reset_mysql():
        if not mysql:
            return
        sql = f'DROP DATABASE IF EXISTS `{db_name}`; CREATE DATABASE `{db_name}`;'
        mysql_environment = os.environ.copy()
        mysql_environment['MYSQL_PWD'] = db_password
        subprocess.run(['mysql', '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', f'--port={db_port}', f'--user={db_user}'], input=sql, text=True, env=mysql_environment, check=True, capture_output=True)

    reset_mysql()
    (app / ".env").write_text("\n".join([
        "APP_ENV=local", "APP_DEBUG=false", "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
        "APP_URL=http://localhost", f"DB_CONNECTION={connection}", f'DB_DATABASE="{db_name}"', "DB_URL=",
        f'DB_HOST={db_host}', f'DB_PORT={db_port}', f'DB_USERNAME={db_user}', f'DB_PASSWORD={db_password}',
        "CACHE_STORE=array", f"SESSION_DRIVER={session_driver}", "SESSION_SECURE_COOKIE=false", "QUEUE_CONNECTION=sync",
        "LOG_CHANNEL=single", "GATEWAY_VERIFICATION_NODE_ID=local-hosting-test", "ALLOW_REGISTRATION=false",
    ]))
    environment = os.environ.copy()
    for key in list(environment):
        if key.startswith(("DB_", "APP_", "SESSION_", "CACHE_", "QUEUE_")):
            environment.pop(key)
    migration = subprocess.run([PHP, "artisan", "migrate", "--force"], cwd=app, env=environment, capture_output=True)
    assert migration.returncode == 0, migration.stdout.decode() + migration.stderr.decode()
    seed = r'''require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $u = \App\Models\User::create(['name'=>'Hosting Test','email'=>'hosting@example.test','password'=>'Hosting-test-password','role'=>'admin']);
    $u->regenerateApiCredentials();
    $u->paymentAccounts()->create(['provider'=>'manual','label'=>'Manual','upi_id'=>'hosting@upi','payee_name'=>'Hosting','is_default'=>true]);
    echo json_encode(['key'=>$u->api_key,'secret'=>$u->api_secret]);'''
    credentials = json.loads(subprocess.run([PHP, "-r", seed], cwd=app, env=environment, check=True, capture_output=True).stdout)
    (app / "storage/installed").write_text("local test only")
    (app / "storage/app/public/receipt.txt").write_text("PUBLIC RECEIPT")
    (app / "public/unintended.php").write_text("<?php echo 'UNINTENDED PHP EXECUTION';")
    fpm_port, nginx_port, tls_port = port(), port(), port()
    native_sessions = temp / 'native-sessions'
    native_sessions.mkdir()
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=secure.test', '-keyout', str(temp / 'tls.key'), '-out', str(temp / 'tls.crt')], check=True, capture_output=True)
    fpm_config = temp / "php-fpm.conf"
    fpm_config.write_text(f"""[global]
pid = {temp}/php-fpm.pid
error_log = {temp}/php-fpm.log
daemonize = no
[hosting]
listen = 127.0.0.1:{fpm_port}
pm = static
pm.max_children = 2
clear_env = yes
catch_workers_output = yes
php_admin_value[display_errors] = Off
php_admin_value[log_errors] = On
php_admin_value[error_log] = {temp}/php.log
php_admin_value[session.save_path] = {native_sessions}
""")
    servers = []
    deployed_apps = {app}
    app_mounts = {}
    deployments = [("main.test", ""), ("pay.test", ""), ("parent.test", "/payment"), ("nested.test", "/services/pay")]
    for host, prefix in deployments:
        app_mounts[host] = app
        url = f"http://{host}:{nginx_port}{prefix}"
        generator = "require 'vendor/autoload.php'; echo \\App\\Support\\DeploymentConfiguration::nginx($argv[1], $argv[2], $argv[3]);"
        mount = subprocess.run([PHP, "-r", generator, url, str(app / "public"), f"127.0.0.1:{fpm_port}"], cwd=app, check=True, capture_output=True, text=True).stdout
        mount = mount.replace('fastcgi_param HTTP_PROXY "";', f'''fastcgi_param HTTP_PROXY "";
    fastcgi_param APP_URL "{url}";
    fastcgi_param SESSION_PATH "{prefix}/";
    fastcgi_param APP_ENV local;
    fastcgi_param DB_CONNECTION {connection};
    fastcgi_param DB_DATABASE "{db_name}";
    fastcgi_param DB_HOST {db_host};
    fastcgi_param DB_PORT {db_port};
    fastcgi_param DB_USERNAME "{db_user}";
    fastcgi_param DB_PASSWORD "{db_password}";
    fastcgi_param CACHE_STORE array;
    fastcgi_param SESSION_DRIVER {session_driver};''')
        mount = mount.replace(f'fastcgi_param SESSION_DRIVER {session_driver};', f'fastcgi_param SESSION_DRIVER {session_driver};\n    fastcgi_param GATEWAY_VERIFICATION_NODE_ID "hosting-{host}";')
        parent = 'location / { try_files $uri $uri/ =404; }' if prefix else ''
        servers.append(f"""server {{
listen 127.0.0.1:{nginx_port}; server_name {host}; root "{site}"; index index.html;
{mount}
{parent}
# Simulate aaPanel generic PHP and static regex locations. The app mount must win.
location ~ \\.php$ {{ return 418; }}
location ~* \\.(css|js)$ {{ return 418; }}
}}""")
    nginx_config = temp / "nginx.conf"
    tls_url = f'https://secure.test:{tls_port}'
    secure_mount = subprocess.run([PHP, '-r', generator, tls_url, str(app / 'public'), f'127.0.0.1:{fpm_port}'], cwd=app, check=True, capture_output=True, text=True).stdout
    secure_mount = secure_mount.replace('fastcgi_param HTTP_PROXY "";', f'''fastcgi_param HTTP_PROXY "";
    fastcgi_param APP_URL "{tls_url}";
    fastcgi_param SESSION_PATH /;
    fastcgi_param SESSION_SECURE_COOKIE true;
    fastcgi_param TRUSTED_PROXIES 127.0.0.1;''')
    secure_backend = f'server {{ listen 127.0.0.1:{nginx_port}; server_name secure.test; {secure_mount} }}'
    tls_front = f'''server {{
listen 127.0.0.1:{tls_port} ssl;
server_name secure.test;
ssl_certificate "{temp}/tls.crt";
ssl_certificate_key "{temp}/tls.key";
location / {{
    proxy_pass http://127.0.0.1:{nginx_port};
    proxy_set_header Host secure.test:{tls_port};
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-For $remote_addr;
}}
}}'''
    mime = Path(NGINX).resolve().parents[2] / "etc/nginx/mime.types"
    if not mime.exists():
        mime = Path("/opt/homebrew/etc/nginx/mime.types")
    nginx_config.write_text(f"""pid "{temp}/nginx.pid";
error_log "{temp}/nginx.log";
events {{ worker_connections 128; }}
http {{ include {mime}; default_type application/octet-stream; access_log off;
client_body_temp_path "{temp}/client-body";
fastcgi_temp_path "{temp}/fastcgi";
{tls_front}
{''.join(servers) + secure_backend if WEBSERVER == 'nginx' else ''}
}}
""")
    syntax = subprocess.run([NGINX, "-t", "-p", str(temp), "-c", str(nginx_config)], capture_output=True)
    assert syntax.returncode == 0, syntax.stderr.decode()
    server_command = [NGINX, "-p", str(temp), "-c", str(nginx_config), "-g", "daemon off;"]
    if WEBSERVER.startswith("apache"):
        modules = Path(os.environ.get("TEST_APACHE_MODULES", "/opt/homebrew/lib/httpd/modules" if HTTPD.startswith('/opt/homebrew') else "/usr/lib/apache2/modules"))
        loads = "\n".join(f'LoadModule {module}_module "{modules}/mod_{module}.so"' for module in [
            "mpm_prefork", "unixd", "authz_core", "authz_host", "mime", "dir", "alias", "rewrite", "proxy", "proxy_fcgi", "env",
        ] if (modules / f'mod_{module}.so').exists())
        vhosts = []
        for host, prefix in deployments:
            url = f"http://{host}:{nginx_port}{prefix}"
            apache_generator = "require 'vendor/autoload.php'; echo \\App\\Support\\DeploymentConfiguration::apache($argv[1], $argv[2]);"
            alias = subprocess.run([PHP, '-r', apache_generator, url, str(app / 'public')], cwd=app, check=True, capture_output=True, text=True).stdout
            docroot = site if prefix else app / "public"
            if WEBSERVER == 'apache-folder':
                # Fixed shared-hosting DocumentRoot: exercise the package's own
                # folder-local .htaccess without replacing a parent site's rules.
                if prefix:
                    docroot = temp / ('parent-' + host)
                    target = docroot / prefix.lstrip('/')
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copytree(app, target, symlinks=True)
                    (docroot / 'index.html').write_text('PARENT WEBSITE UNCHANGED')
                    deployed_apps.add(target)
                    app_mounts[host] = target
                else:
                    docroot = target = app
                alias = f'<Directory "{target}">\nOptions +FollowSymLinks -Indexes\nAllowOverride All\nRequire all granted\n</Directory>'
                if prefix:
                    alias = f'<Directory "{docroot}">\nAllowOverride None\nRequire all granted\n</Directory>\n' + alias
            vhosts.append(f'''<VirtualHost 127.0.0.1:{nginx_port}>
ServerName {host}
DocumentRoot "{docroot}"
{alias}
SetEnv APP_URL "{url}"
SetEnv SESSION_PATH "{prefix}/"
SetEnv APP_ENV local
SetEnv DB_CONNECTION {connection}
SetEnv DB_DATABASE "{db_name}"
SetEnv DB_HOST {db_host}
SetEnv DB_PORT {db_port}
SetEnv DB_USERNAME "{db_user}"
SetEnv DB_PASSWORD "{db_password}"
SetEnv CACHE_STORE array
SetEnv SESSION_DRIVER {session_driver}
SetEnv GATEWAY_VERIFICATION_NODE_ID "hosting-{host}"
</VirtualHost>
''')
        vhosts.append(f'''<VirtualHost 127.0.0.1:{nginx_port}>
ServerName secure.test
DocumentRoot "{app}/public"
SetEnv APP_URL "{tls_url}"
SetEnv SESSION_PATH /
SetEnv SESSION_SECURE_COOKIE true
SetEnv TRUSTED_PROXIES 127.0.0.1
</VirtualHost>''')
        apache_config = temp / "httpd.conf"
        apache_config.write_text(f'''ServerRoot "{temp}"
DefaultRuntimeDir "{temp}"
Mutex "file:{temp}" default
PidFile "{temp}/httpd.pid"
Listen 127.0.0.1:{nginx_port}
ServerName localhost
ErrorLog "{temp}/apache.log"
{"LogLevel warn rewrite:trace3" if os.environ.get('TEST_APACHE_TRACE') else "LogLevel warn"}
{loads}
TypesConfig {os.environ.get('TEST_APACHE_MIME', '/opt/homebrew/etc/httpd/mime.types' if HTTPD.startswith('/opt/homebrew') else '/etc/mime.types')}
DirectoryIndex index.php index.html
<Directory />
AllowOverride None
Require all denied
</Directory>
<Directory "{site}">
Require all granted
</Directory>
<Directory "{app}/public">
Options +FollowSymLinks -Indexes
AllowOverride All
Require all granted
</Directory>
<FilesMatch "\\.php$">
SetHandler "proxy:fcgi://127.0.0.1:{fpm_port}"
</FilesMatch>
{''.join(vhosts)}
''')
        syntax = subprocess.run([HTTPD, "-t", "-f", str(apache_config)], capture_output=True)
        assert syntax.returncode == 0, syntax.stderr.decode()
        server_command = [HTTPD, "-f", str(apache_config), "-DFOREGROUND"]
    processes = []
    try:
        processes.append(subprocess.Popen([FPM, "-y", str(fpm_config), "-F"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True))
        processes.append(subprocess.Popen(server_command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True))
        if WEBSERVER != 'nginx':
            processes.append(subprocess.Popen([NGINX, '-p', str(temp), '-c', str(nginx_config), '-g', 'daemon off;'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True))
        for required_port in [fpm_port, nginx_port, tls_port]:
            for attempt in range(100):
                try:
                    with socket.create_connection(("127.0.0.1", required_port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError(f'Test service did not become ready on its isolated port {required_port}')
        for host, prefix in deployments:
            url = f"http://{host}:{nginx_port}{prefix}"
            opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

            def request(path, data=None, headers=None):
                values = {"Host": f"{host}:{nginx_port}"}
                values.update(headers or {})
                req = urllib.request.Request(f"http://127.0.0.1:{nginx_port}{path}", data=data, headers=values)
                try:
                    response = opener.open(req, timeout=10)
                except urllib.error.HTTPError as response:
                    return response.code, response.headers, response.read()
                return response.status, response.headers, response.read()

            status, _, body = request(prefix + "/")
            expect(status == 200, f"{host}: application root {status}")
            status, headers, login = request(prefix + "/login")
            expect(status == 200, f"{host}: login {status}")
            expect((url + "/login").encode() in login and b"/public/" not in login, f"{host}: clean form URLs")
            marker = app_mounts[host] / 'storage/installed'
            marker_content = marker.read_bytes()
            marker.unlink()
            try:
                expect(request(prefix + '/login')[0] == 200, f'{host}: database guard preserves login after marker loss')
                expect(request(prefix + '/install.php')[0] == 403, f'{host}: marker loss cannot reopen installer')
            finally:
                marker.write_bytes(marker_content)
            expect(f"path={prefix}/" in " ".join(headers.get_all("Set-Cookie", [])), f"{host}: cookie mount")
            manifest = json.loads((app / "public/build/manifest.json").read_text())
            for entry, content_type in [("resources/js/app.js", "javascript"), ("resources/css/app.css", "css")]:
                asset = manifest[entry]["file"]
                status, headers, _ = request(prefix + "/build/" + asset)
                expect(status == 200 and content_type in headers.get("Content-Type", ""), f"{host}: asset {asset} {status}")
            docs_status, docs_headers, _ = request(prefix + '/docs')
            expect(docs_status == 200, f'{host}: docs avoids private-directory collision ({docs_status}, {docs_headers.get("Location")})')
            for path in ["/.env", "/vendor/autoload.php", "/vendor/", "/app/", "/config/app.php", "/storage/logs/laravel.log", "/deployment-private-check.txt"]:
                expect(request(prefix + path)[0] in [403, 404], f"{host}: private path {path}")
            if prefix:
                expect(request("/")[2] == b"PARENT WEBSITE UNCHANGED", f"{host}: main website preserved")
            status, _, diagnostic = request(prefix + "/__upi_deployment_check")
            expect(status == 200 and json.loads(diagnostic)["base_path"] == prefix, f"{host}: browser route probe")
            probe = json.loads(diagnostic)
            expect(probe['runtime'] == 'laravel' and probe['installed'], f'{host}: actual Laravel runtime and installer lock')
            parameters = urllib.parse.urlencode({'_token': probe['csrf_token'], 'probe': probe['probe']}).encode()
            expect(request(prefix + '/__upi_deployment_check', parameters, {'Content-Type': 'application/x-www-form-urlencoded'})[0] == 200, f'{host}: session-bound CSRF probe')
            expect(request(prefix + '/install.php')[0] == 403, f'{host}: native installer lock')
            expect(request(prefix + '/unintended.php')[0] in [403, 404], f'{host}: arbitrary PHP denied')
            expect(request(prefix + "/storage/receipt.txt")[2] == b"PUBLIC RECEIPT", f"{host}: public storage without symlink")
            expect(request(prefix + "/login", b"email=hosting%40example.test&password=wrong")[0] == 419, f"{host}: CSRF enforcement")
            token = html.unescape(re.search(r'name="_token" value="([^"]+)"', login.decode())[1])
            form = urllib.parse.urlencode({"_token": token, "email": "hosting@example.test", "password": "Hosting-test-password"}).encode()
            status, headers, _ = request(prefix + "/login", form, {"Content-Type": "application/x-www-form-urlencoded"})
            expect(status == 302 and headers["Location"] == url + "/dashboard", f"{host}: login redirect")
            expect(request(prefix + "/dashboard?environment=sandbox")[0] == 200, f"{host}: dashboard")
            expect(request(prefix + "/login")[1].get("Location") == url + "/dashboard", f"{host}: authenticated guest redirect")
            payload = json.dumps({"amount": 10, "client_order_id": host.replace('.', '-')}).encode()
            status, _, body = request(prefix + "/api/v1/orders", payload, {"Content-Type": "application/json", "Accept": "application/json", "X-Api-Key": credentials["key"], "X-Api-Secret": credentials["secret"]})
            expect(status == 201 and json.loads(body)["data"]["payment_url"].startswith(url + "/pay/"), f"{host}: API/payment URL {status}")
            payment = json.loads(body)['data']
            status, _, checkout = request(urllib.parse.urlparse(payment['payment_url']).path)
            status_match = re.search(rb'const statusUrl = (".*?");', checkout)
            expect(status == 200 and status_match is not None and json.loads(status_match[1]) == payment['payment_url'] + '/status', f'{host}: checkout/status URL prefix')
            qr_status, qr_headers, _ = request(urllib.parse.urlparse(payment['payment_url']).path + '/qr.svg')
            expect(qr_status == 200 and 'image/svg+xml' in qr_headers.get('Content-Type', ''), f'{host}: QR URL prefix')
            status, headers, _ = request(prefix + '/pay/' + payment['order_id'] + '/return', b'STATUS=TXN_FAILURE', {'Content-Type': 'application/x-www-form-urlencoded'})
            expect(status == 302 and headers.get('Location') == payment['payment_url'], f'{host}: provider return URL and CSRF exemption ({status}, {headers.get("Location")}, expected {payment["payment_url"]})')
            print(f"PASS {host}{prefix}: routes, assets, login, cookies, CSRF, API, storage and private-file isolation")
        # Real TLS edge -> HTTP origin, with an explicit proxy allowlist.
        context = ssl.create_default_context(cafile=str(temp / 'tls.crt'))
        context.check_hostname = False  # TCP connects to loopback; trust only this ephemeral test certificate.
        secure_opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPSHandler(context=context), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        probe_request = urllib.request.Request(f'https://127.0.0.1:{tls_port}/__upi_deployment_check', headers={'Host': f'secure.test:{tls_port}'})
        with secure_opener.open(probe_request, timeout=10) as response:
            secure_probe = json.loads(response.read())
            expect(response.headers.get('Strict-Transport-Security') is not None, 'TLS proxy HSTS')
            expect(secure_probe['secure'] is True, 'TLS proxy secure scheme')
            expect(secure_probe['login_url'] == tls_url + '/login', 'TLS proxy canonical HTTPS URL')
            expect('secure' in ' '.join(response.headers.get_all('Set-Cookie', [])).lower(), 'TLS proxy secure cookies')
        status, headers, plain_probe = request(prefix + '/__upi_deployment_check', headers={'X-Forwarded-Proto': 'https'})
        expect(status == 200 and json.loads(plain_probe)['secure'] is False and not headers.get('Strict-Transport-Security'), 'Untrusted forwarded HTTPS is ignored')
        def browser_checks(phase, selected=None):
            browser_module = os.environ.get('TEST_BROWSER_MODULE')
            if not browser_module:
                print('NOT CHECKED: real browser UI (set TEST_BROWSER_MODULE to the locked test-only Playwright installation)')
                return
            browser_environment = os.environ.copy()
            browser_environment['TEST_BROWSER_MODULE'] = str(Path(browser_module).resolve())
            selected = selected or deployments
            urls = [f'http://{name}:{nginx_port}{path}' for name, path in selected]
            cookies = []
            if phase == 'final':
                # Seed only the disposable native completion session, equivalent
                # to the session created after the seeded administrator is saved.
                for name, path in deployments:
                    cookie_name = 'upi_setup_' + hashlib.sha256((str(app_mounts[name].resolve()) + path).encode()).hexdigest()[:16]
                    session_id = secrets.token_hex(24)
                    session_seed = 'ini_set("session.save_path", $argv[1]); session_name($argv[2]); session_id($argv[3]); session_start(); $_SESSION["_installer_completed"]=true; session_write_close();'
                    subprocess.run([PHP, '-r', session_seed, str(native_sessions), cookie_name, session_id], check=True, capture_output=True)
                    cookies.append({'name': cookie_name, 'value': session_id, 'domain': name, 'path': path + '/', 'httpOnly': True, 'secure': False, 'sameSite': 'Lax'})
            browser = subprocess.Popen(['node', str(PROJECT / 'tests/Hosting/browser_check.cjs')], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=browser_environment)
            try:
                stdout, stderr = browser.communicate(json.dumps({'urls': urls, 'phase': phase, 'cookies': cookies, 'mysql': mysql}), timeout=180)
            except subprocess.TimeoutExpired:
                # Only this runner's descendants: never terminate a user's browser.
                rows = [line.split() for line in subprocess.check_output(['ps', '-axo', 'pid=,ppid='], text=True).splitlines()]
                descendants = {browser.pid}
                while True:
                    children = {int(pid) for pid, parent in rows if int(parent) in descendants}
                    if children <= descendants:
                        break
                    descendants |= children
                for pid in descendants:
                    try:
                        os.kill(pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                browser.communicate(timeout=10)
                raise AssertionError('Real browser verification exceeded 180 seconds; disposable browser processes closed. No check was skipped.')
            browser.stdout, browser.stderr = stdout, stderr
            expect(browser.returncode == 0, browser.stdout + browser.stderr)
            print(browser.stdout.strip())

        browser_checks('final')
        # Start fresh using the identical source tree; all data here is disposable.
        for deployed in deployed_apps:
            (deployed / ".env").unlink()
            (deployed / "storage/installed").unlink()
        database.unlink()
        database.touch()
        reset_mysql()
        for host, prefix in deployments:
            opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            status, headers, _ = request(prefix + "/login")
            expect(status == 302 and headers["Location"] == prefix + "/install.php", f"{host}: fresh deep-link redirect")
            status, _, body = request(prefix + "/install.php?step=database")
            url = f"http://{host}:{nginx_port}{prefix}"
            expect(status == 200 and f'value="{url}"'.encode() in body, f"{host}: fresh installer base URL {status}")
            status, _, body = request(prefix + "/__upi_deployment_check")
            expect(status == 200 and json.loads(body)["base_path"] == prefix, f"{host}: fresh browser route probe")
            status, _, page = request(prefix + '/install.php')
            expect(status == 200 and b'NOT CHECKED' in page and b'Public application address' in page, f'{host}: fresh environment diagnostics')
            native_token = html.unescape(re.search(r'name="_token" value="([^"]+)"', request(prefix + '/install.php?step=database')[2].decode())[1])
            parameters = urllib.parse.urlencode({'_token': native_token}).encode()
            expect(request(prefix + '/install.php?step=deployment-check', parameters, {'Content-Type': 'application/x-www-form-urlencoded'})[0] == 200, f'{host}: native session CSRF round trip')
            expect(request(prefix + '/install.php?step=deployment-check', b'_token=invalid', {'Content-Type': 'application/x-www-form-urlencoded'})[0] == 403, f'{host}: native invalid CSRF denied')
        browser_checks('fresh')
        if mysql:
            for topology in deployments:
                for deployed in deployed_apps:
                    (deployed / '.env').unlink(missing_ok=True)
                    (deployed / 'storage/installed').unlink(missing_ok=True)
                reset_mysql()
                browser_checks('install', [topology])
        version = subprocess.run([PHP, '-r', 'echo PHP_VERSION;'], capture_output=True, text=True).stdout
        print(f"PASS: {checks} real {WEBSERVER}/PHP-FPM {version} assertions; isolated disposable {connection}; server syntax check passed")
    except Exception:
        print('Fixture environment present:', (app / '.env').exists(), '; installer marker:', (app / 'storage/installed').exists())
        for log in ["nginx.log", "apache.log", "php-fpm.log", "php.log"]:
            if (temp / log).exists():
                print((temp / log).read_text()[-3000:])
        raise
    finally:
        for process in reversed(processes):
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
