"""Private MySQL daemon for real browser installation tests; never connects to an existing server."""
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time

root = Path(__file__).resolve().parents[2]
area = root / 'storage/framework/testing/hosting'
area.mkdir(parents=True, exist_ok=True)
mysqld = shutil.which('mysqld')
mysql = shutil.which('mysql')
assert mysql, 'NOT RUN: a MySQL client is required'
assert os.environ.get('TEST_BROWSER_MODULE'), 'NOT RUN: real browser test dependencies are required'

if os.environ.get('TEST_MYSQL_CONTAINER') == '1':
    # CI uses a private MySQL 8 container instead of changing host AppArmor or
    # reusing a system database. Pin the Docker endpoint to the local socket.
    docker = [shutil.which('docker') or 'docker', '--host', 'unix:///var/run/docker.sock']
    with tempfile.TemporaryDirectory(prefix='mysql-container-', dir=area) as folder:
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        name = 'upi-hosting-test-' + secrets.token_hex(8)
        password = secrets.token_urlsafe(36)
        environment = os.environ.copy()
        environment.update({'MYSQL_DATABASE': 'upi_hosting_disposable', 'MYSQL_USER': 'hosting_test', 'MYSQL_PASSWORD': password, 'MYSQL_ROOT_PASSWORD': secrets.token_urlsafe(36)})
        started = subprocess.run(docker + ['run', '--detach', '--rm', '--name', name, '-p', f'127.0.0.1:{port}:3306', '--env', 'MYSQL_DATABASE', '--env', 'MYSQL_USER', '--env', 'MYSQL_PASSWORD', '--env', 'MYSQL_ROOT_PASSWORD', 'mysql:8.0'], env=environment, capture_output=True)
        assert started.returncode == 0, 'Private MySQL container could not start; no existing server was used.'
        try:
            probe_environment = os.environ.copy()
            probe_environment['MYSQL_PWD'] = password
            for attempt in range(180):
                probe = subprocess.run([mysql, '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', f'--port={port}', '--user=hosting_test', '-e', 'SELECT 1'], env=probe_environment, capture_output=True)
                if probe.returncode == 0:
                    break
                time.sleep(0.5)
            else:
                raise RuntimeError('Private MySQL container startup timed out.')
            config = Path(folder) / 'test-connection.json'
            fd = os.open(config, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, 'w') as stream:
                json.dump({'port': port, 'database': 'upi_hosting_disposable', 'user': 'hosting_test', 'password': password}, stream)
            child_environment = os.environ.copy()
            child_environment['TEST_MYSQL_CONFIG'] = str(config)
            subprocess.run(['python3', str(root / 'tests/Hosting/verify_nginx.py')], env=child_environment, check=True)
        finally:
            subprocess.run(docker + ['rm', '-f', name], check=True, capture_output=True)
    print('PASS: disposable MySQL 8 container removed; existing servers untouched')
    sys.exit(0)

assert mysqld, 'NOT RUN: a private MySQL daemon binary is required'
with tempfile.TemporaryDirectory(prefix='mysql-', dir=area) as folder:
    work = Path(folder)
    data = work / 'data'
    data.mkdir(mode=0o700)
    assert not list(data.iterdir()), 'Refusing a nonempty MySQL data directory'
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    log = work / 'mysql.log'
    init = subprocess.run([mysqld, '--no-defaults', '--initialize-insecure', f'--datadir={data}', f'--log-error={log}'], capture_output=True)
    assert init.returncode == 0, 'Private MySQL initialization failed; no existing server was used.'
    server = subprocess.Popen([mysqld, '--no-defaults', f'--datadir={data}', f'--port={port}', '--bind-address=127.0.0.1', f'--socket={work}/s', f'--pid-file={work}/mysql.pid', f'--log-error={log}', '--skip-log-bin', '--mysqlx=OFF'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    try:
        for attempt in range(100):
            if server.poll() is not None:
                raise RuntimeError('Private MySQL server exited; no fallback to an existing server is allowed.')
            ping = subprocess.run([mysql, '--no-defaults', '--protocol=socket', f'--socket={work}/s', '-u', 'root', '-e', 'SELECT 1'], capture_output=True)
            if ping.returncode == 0:
                break
            time.sleep(0.1)
        else:
            raise RuntimeError('Private MySQL startup timed out.')
        password = secrets.token_urlsafe(36)
        root_password = secrets.token_urlsafe(36)
        schema = 'upi_hosting_disposable'
        user = 'hosting_test'
        sql = f"ALTER USER 'root'@'localhost' IDENTIFIED BY '{root_password}'; CREATE DATABASE `{schema}`; CREATE USER '{user}'@'localhost' IDENTIFIED BY '{password}'; GRANT ALL PRIVILEGES ON `{schema}`.* TO '{user}'@'localhost';"
        subprocess.run([mysql, '--no-defaults', '--protocol=socket', f'--socket={work}/s', '-u', 'root'], input=sql, text=True, check=True, capture_output=True)
        config = work / 'test-connection.json'
        fd = os.open(config, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'w') as stream:
            json.dump({'port': port, 'database': schema, 'user': user, 'password': password}, stream)
        environment = os.environ.copy()
        environment['TEST_MYSQL_CONFIG'] = str(config)
        subprocess.run(['python3', str(root / 'tests/Hosting/verify_nginx.py')], env=environment, check=True)
        print('PASS: private disposable MySQL browser installation; existing server/data/credentials untouched')
    finally:
        server.terminate()
        try:
            server.wait(timeout=15)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
