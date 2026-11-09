"""Validate a production ZIP or a GitHub-style ZIP with one wrapper directory."""
import json
from pathlib import PurePosixPath
import sys
import zipfile


def check(filename):
    with zipfile.ZipFile(filename) as archive:
        names = archive.namelist()
        prefix = ''
        if 'artisan' not in names:
            candidates = [name[:-len('artisan')] for name in names if name.endswith('/artisan') and len(PurePosixPath(name).parts) == 2]
            assert len(candidates) == 1, 'Missing or ambiguous package root'
            prefix = candidates[0]
        files = {name[len(prefix):] for name in names if name.startswith(prefix)}
        required = [
            'artisan', 'vendor/autoload.php', 'public/build/manifest.json', 'index.php', 'install.php',
            'public/index.php', 'public/install.php', '.htaccess', 'public/.htaccess', '.env.example',
            'bootstrap/cache/packages.php', 'docs/DEPLOYMENT.md',
            'app/Support/DeploymentUrl.php', 'app/Support/DeploymentFilesystem.php',
            'app/Support/DeploymentConfiguration.php', 'app/Http/Middleware/SetDeploymentBasePath.php',
            'public/deployment-check.json', 'deployment-private-check.txt',
        ]
        for name in required:
            assert name in files, f'Missing runtime file: {name}'
        forbidden = ['.git/', 'tests/', 'node_modules/', 'vendor/phpunit/', 'vendor/mockery/', 'vendor/fakerphp/']
        for name in files:
            path = PurePosixPath(name)
            assert not path.is_absolute() and '..' not in path.parts, f'Unsafe archive path: {name}'
            assert not any(name.startswith(value) for value in forbidden), f'Development/private content: {name}'
            assert name == '.env.example' or (name != '.env' and not name.startswith('.env.')), f'Environment file: {name}'
            assert not name.lower().endswith(('.sqlite', '.sqlite3', '.zip', '.tar.gz', '.log')), f'Runtime data/archive: {name}'
            assert '.sqlite' not in name.lower() and name != 'auth.json', f'Private database/credentials: {name}'
            assert name != 'storage/installed' and not name.startswith('storage/installer-'), f'Installer state: {name}'
        manifest = json.loads(archive.read(prefix + 'public/build/manifest.json'))
        for entry in manifest.values():
            if 'file' in entry:
                assert 'public/build/' + entry['file'] in files, 'Manifest references a missing asset'
        for directory in ['storage/app/public', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']:
            assert any(name.startswith(directory + '/') for name in files), f'Missing writable skeleton: {directory}'
        assert b'upi-gateway-private-deployment-sentinel' in archive.read(prefix + 'deployment-private-check.txt'), 'Invalid private probe'
        assert json.loads(archive.read(prefix + 'public/deployment-check.json'))['public_assets'] is True
        print(f'PASS {filename}: {len(files)} entries; runtime, assets, skeleton and exclusions verified')


if __name__ == '__main__':
    for package in sys.argv[1:]:
        check(package)
