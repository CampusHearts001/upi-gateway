"""Developer integration check: private Nginx/PHP-FPM processes and disposable SQLite only.

Never reads the project's .env or changes a system/panel configuration.
Run with python3 tests/Hosting/verify_nginx.py (requires nginx and PHP-FPM).
"""
import html
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
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
HTTPD = os.environ.get("TEST_HTTPD", "/usr/sbin/httpd")
FPM = os.environ.get("TEST_PHP_FPM") or shutil.which("php-fpm")
if not FPM or subprocess.run([FPM, "-v"], capture_output=True).stdout.startswith(b"PHP 8.0"):
    FPM = "/opt/homebrew/opt/php/sbin/php-fpm"
assert NGINX and Path(FPM).is_file(), "Nginx and PHP-FPM >=8.2 are required."


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


with tempfile.TemporaryDirectory(prefix="upi-hosting-") as temporary:
    temp = Path(temporary)
    app = temp / "private-app"
    site = temp / "parent-site"
    site.mkdir()
    (site / "index.html").write_text("PARENT WEBSITE UNCHANGED")
    app.mkdir()
    if os.environ.get("TEST_PACKAGE"):
        with zipfile.ZipFile(os.environ["TEST_PACKAGE"]) as package:
            package.extractall(app)
        subdirs = [d for d in app.iterdir() if d.is_dir()]
        if len(list(app.iterdir())) == 1 and subdirs:
            root_sub = subdirs[0]
            for item in list(root_sub.iterdir()):
                shutil.move(str(item), str(app))
            root_sub.rmdir()
    else:
        for directory in ["app", "config", "routes", "resources", "public", "database"]:
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
    (app / ".env").write_text("\n".join([
        "APP_ENV=local", "APP_DEBUG=false", "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
        "APP_URL=http://localhost", "DB_CONNECTION=sqlite", f"DB_DATABASE={database}", "DB_URL=",
        "CACHE_STORE=array", "SESSION_DRIVER=file", "SESSION_SECURE_COOKIE=false", "QUEUE_CONNECTION=sync",
        "LOG_CHANNEL=single", "GATEWAY_VERIFICATION_NODE_ID=local-hosting-test", "ALLOW_REGISTRATION=false",
    ]))
    environment = os.environ.copy()
    for key in list(environment):
        if key.startswith(("DB_", "APP_", "SESSION_", "CACHE_", "QUEUE_")):
            environment.pop(key)
    migration = subprocess.run(["php", "artisan", "migrate", "--force"], cwd=app, env=environment, capture_output=True)
    assert migration.returncode == 0, migration.stdout.decode() + migration.stderr.decode()
    seed = r'''require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $u = \App\Models\User::create(['name'=>'Hosting Test','email'=>'hosting@example.test','password'=>'Hosting-test-password','role'=>'admin']);
    $u->regenerateApiCredentials();
    $u->paymentAccounts()->create(['provider'=>'manual','label'=>'Manual','upi_id'=>'hosting@upi','payee_name'=>'Hosting','is_default'=>true]);
    echo json_encode(['key'=>$u->api_key,'secret'=>$u->api_secret]);'''
    credentials = json.loads(subprocess.run(["php", "-r", seed], cwd=app, env=environment, check=True, capture_output=True).stdout)
    (app / "storage/installed").write_text("local test only")
    (app / "storage/app/public/receipt.txt").write_text("PUBLIC RECEIPT")
    fpm_port, nginx_port = port(), port()
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
""")
    servers = []
    deployments = [("main.test", ""), ("pay.test", ""), ("parent.test", "/payment"), ("nested.test", "/services/pay")]
    for host, prefix in deployments:
        url = f"http://{host}:{nginx_port}{prefix}"
        generator = "require 'vendor/autoload.php'; echo \\App\\Support\\DeploymentConfiguration::nginx($argv[1], $argv[2], $argv[3]);"
        mount = subprocess.run(["php", "-r", generator, url, str(app / "public"), f"127.0.0.1:{fpm_port}"], cwd=app, check=True, capture_output=True, text=True).stdout
        mount = mount.replace('fastcgi_param HTTP_PROXY "";', f'''fastcgi_param HTTP_PROXY "";
    fastcgi_param APP_URL "{url}";
    fastcgi_param SESSION_PATH "{prefix}/";
    fastcgi_param APP_ENV local;
    fastcgi_param DB_CONNECTION sqlite;
    fastcgi_param DB_DATABASE "{database}";
    fastcgi_param CACHE_STORE array;
    fastcgi_param SESSION_DRIVER file;''')
        parent = 'location / { try_files $uri $uri/ =404; }' if prefix else ''
        servers.append(f"""server {{
listen 127.0.0.1:{nginx_port}; server_name {host}; root {site}; index index.html;
{mount}
{parent}
# Simulate aaPanel generic PHP and static regex locations. The app mount must win.
location ~ \\.php$ {{ return 418; }}
location ~* \\.(css|js)$ {{ return 418; }}
}}""")
    nginx_config = temp / "nginx.conf"
    mime = Path(NGINX).resolve().parents[2] / "etc/nginx/mime.types"
    if not mime.exists():
        mime = Path("/opt/homebrew/etc/nginx/mime.types")
    nginx_config.write_text(f"""pid {temp}/nginx.pid;
error_log {temp}/nginx.log;
events {{ worker_connections 128; }}
http {{ include {mime}; default_type application/octet-stream; access_log off;
client_body_temp_path {temp}/client-body;
fastcgi_temp_path {temp}/fastcgi;
{''.join(servers)}
}}
""")
    syntax = subprocess.run([NGINX, "-t", "-p", str(temp), "-c", str(nginx_config)], capture_output=True)
    assert syntax.returncode == 0, syntax.stderr.decode()
    server_command = [NGINX, "-p", str(temp), "-c", str(nginx_config), "-g", "daemon off;"]
    if WEBSERVER == "apache":
        modules = Path(os.environ.get("TEST_APACHE_MODULES", "/usr/libexec/apache2"))
        loads = "\n".join(f'LoadModule {module}_module "{modules}/mod_{module}.so"' for module in [
            "mpm_prefork", "unixd", "authz_core", "authz_host", "mime", "dir", "alias", "rewrite", "proxy", "proxy_fcgi", "env",
        ])
        vhosts = []
        for host, prefix in deployments:
            url = f"http://{host}:{nginx_port}{prefix}"
            alias = f'Alias "{prefix}/" "{app}/public/"' if prefix else ''
            docroot = site if prefix else app / "public"
            vhosts.append(f'''<VirtualHost 127.0.0.1:{nginx_port}>
ServerName {host}
DocumentRoot "{docroot}"
{alias}
SetEnv APP_URL "{url}"
SetEnv SESSION_PATH "{prefix}/"
SetEnv APP_ENV local
SetEnv DB_CONNECTION sqlite
SetEnv DB_DATABASE "{database}"
SetEnv CACHE_STORE array
SetEnv SESSION_DRIVER file
</VirtualHost>
''')
        apache_config = temp / "httpd.conf"
        apache_config.write_text(f'''ServerRoot "{temp}"
DefaultRuntimeDir "{temp}"
Mutex file:{temp} default
PidFile "{temp}/httpd.pid"
Listen 127.0.0.1:{nginx_port}
ServerName localhost
ErrorLog "{temp}/apache.log"
{loads}
TypesConfig /etc/apache2/mime.types
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
        for attempt in range(50):
            try:
                with socket.create_connection(("127.0.0.1", nginx_port), timeout=0.1):
                    break
            except OSError:
                time.sleep(0.1)
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
            expect(f"path={prefix}/" in " ".join(headers.get_all("Set-Cookie", [])), f"{host}: cookie mount")
            manifest = json.loads((app / "public/build/manifest.json").read_text())
            for entry, content_type in [("resources/js/app.js", "javascript"), ("resources/css/app.css", "css")]:
                asset = manifest[entry]["file"]
                status, headers, _ = request(prefix + "/build/" + asset)
                expect(status == 200 and content_type in headers.get("Content-Type", ""), f"{host}: asset {asset} {status}")
            expect(request(prefix + "/docs")[0] == 200, f"{host}: docs avoids private-directory collision")
            for path in ["/.env", "/vendor/autoload.php", "/vendor/", "/app/", "/config/app.php", "/storage/logs/laravel.log", "/deployment-private-check.txt"]:
                expect(request(prefix + path)[0] in [403, 404], f"{host}: private path {path}")
            if prefix:
                expect(request("/")[2] == b"PARENT WEBSITE UNCHANGED", f"{host}: main website preserved")
            status, _, diagnostic = request(prefix + "/__upi_deployment_check")
            expect(status == 200 and json.loads(diagnostic)["base_path"] == prefix, f"{host}: browser route probe")
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
            print(f"PASS {host}{prefix}: routes, assets, login, cookies, CSRF, API, storage and private-file isolation")
        # Start fresh using the identical source tree; all data here is disposable.
        (app / ".env").unlink()
        (app / "storage/installed").unlink()
        database.unlink()
        database.touch()
        for host, prefix in deployments:
            opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            status, headers, _ = request(prefix + "/login")
            expect(status == 302 and headers["Location"] == prefix + "/install.php", f"{host}: fresh deep-link redirect")
            status, _, body = request(prefix + "/install.php?step=database")
            url = f"http://{host}:{nginx_port}{prefix}"
            expect(status == 200 and f'value="{url}"'.encode() in body, f"{host}: fresh installer base URL {status}")
            status, _, body = request(prefix + "/__upi_deployment_check")
            expect(status == 200 and json.loads(body)["base_path"] == prefix, f"{host}: fresh browser route probe")
        print(f"PASS: {checks} real {WEBSERVER}/PHP-FPM assertions; isolated disposable SQLite; server syntax check passed")
    except Exception:
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
