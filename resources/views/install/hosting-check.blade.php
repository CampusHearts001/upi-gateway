@php $finalCheck = $finalCheck ?? false; @endphp
<div class="hosting-check" role="status" aria-live="polite">
    <strong>{{ $finalCheck ? 'Final deployment verification' : 'Hosting environment and public URL checks' }}</strong>
    @if (isset($diagnostics))
        <dl>
            <dt>Layout</dt><dd>{{ $diagnostics['layout'] ?? 'NOT CHECKED' }}</dd>
            <dt>Public application address</dt><dd>{{ $deploymentUrl }}</dd>
            <dt>Web server</dt><dd>{{ $diagnostics['server_software'] ?? 'NOT CHECKED' }}</dd>
            <dt>PHP / handler</dt><dd>{{ $diagnostics['php_version'] ?? PHP_VERSION }} / {{ $diagnostics['php_sapi'] ?? PHP_SAPI }}</dd>
            <dt>PHP-FPM availability</dt><dd>{{ ($diagnostics['php_sapi'] ?? PHP_SAPI) === 'fpm-fcgi' ? 'PASS — PHP-FPM is executing this request' : 'NOT CHECKED — PHP handler type is not reliably identifiable' }}</dd>
            <dt>Application directory</dt><dd>{{ $diagnostics['application_path'] ?? base_path() }}</dd>
            <dt>Public directory</dt><dd>{{ $diagnostics['public_path'] ?? public_path() }}</dd>
            <dt>Document root</dt><dd>{{ !empty($diagnostics['document_root_is_public']) ? 'PASS — matches public directory' : 'WARNING — verify the public-only URL mount below' }}</dd>
            <dt>Apache rewrite module</dt><dd>{{ $diagnostics['rewrite_module'] ?? 'NOT CHECKED' }}</dd>
            <dt>PHP-FPM endpoint</dt><dd>NOT CHECKED — read the active handler in the hosting panel; never guess a socket.</dd>
        </dl>
        @if (!empty($diagnostics['wrapper_warning']))
            <p>WARNING — the application may be inside an extracted ZIP wrapper directory. Confirm the displayed URL. Move it using File Manager before installation if that directory is unintended.</p>
        @endif
        @if (($diagnostics['server_type'] ?? '') === 'apache' && empty($diagnostics['document_root_is_public']))
            <details><summary>Apache public-directory mapping (hosting-provider / virtual-host configuration)</summary><pre>{{ $diagnostics['apache_rule_snippet'] ?? '' }}</pre></details>
            <p>Preserve the existing parent .htaccess and virtual host. Folder-local rewrite rules require AllowOverride and mod_rewrite. Their effect is checked over HTTP below.</p>
        @endif
    @endif
    <ul>
        @foreach (['routes' => 'Clean routes / runtime', 'assets' => 'Public files and actual Vite CSS/JS', 'private' => 'Private sentinel, .env, vendor and storage protection', 'session' => 'Session cookie path and CSRF round trip', 'urls' => 'Login / dashboard URLs', 'lock' => 'Installer lock after installation'] as $key => $label)
            <li>{{ $label }}: <strong data-hosting-state="{{ $key }}">NOT CHECKED</strong></li>
        @endforeach
    </ul>
    <p data-hosting-result>Running HTTP checks. File existence alone is not proof of a working deployment.</p>
    <button type="button" data-hosting-recheck>Recheck hosting configuration</button>
    <noscript>Enable JavaScript to run the hosting checks before continuing.</noscript>
</div>
<script nonce="{{ $csp_nonce ?? '' }}">
document.addEventListener('DOMContentLoaded', () => {
    const base = @json(rtrim($deploymentUrl, '/'));
    const finalCheck = @json($finalCheck);
    const installerCsrf = @json($deploymentCsrfToken ?? csrf_token());
    const output = document.querySelector('[data-hosting-result]');
    const recheck = document.querySelector('[data-hosting-recheck]');
    const links = document.querySelectorAll('[data-deployment-continue]');
    const submits = document.querySelectorAll('[data-deployment-submit]');
    const state = (key, value) => { document.querySelector('[data-hosting-state="' + key + '"]').textContent = value; };
    const snippet = document.querySelector('[data-nginx-config]');
    const handler = document.querySelector('[data-php-handler]');
    const copy = document.querySelector('[data-copy-nginx]');
    if (snippet && handler && copy) {
        const template = snippet.textContent;
        handler.addEventListener('input', () => {
            const value = handler.value.trim();
            const valid = /^(unix:\/[A-Za-z0-9_./-]+|(127\.0\.0\.1|localhost):[0-9]+)$/.test(value);
            snippet.textContent = valid ? template.replaceAll('unix:/REPLACE_WITH_PANEL_PHP_FPM_SOCKET', value) : template;
            copy.disabled = !valid;
        });
        copy.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(snippet.textContent); copy.textContent = 'Copied'; }
            catch { copy.textContent = 'Select and copy the configuration below'; }
        });
    }
    const fetchAt = (path, options = {}) => {
        const target = new URL(base + '/' + path);
        target.searchParams.set('__upi_check', Date.now() + '-' + Math.random());
        return fetch(target, {
            cache: 'no-store', credentials: 'same-origin', signal: AbortSignal.timeout(12000), ...options
        });
    };
    const check = async () => {
        links.forEach(link => { link.removeAttribute('href'); link.setAttribute('aria-disabled', 'true'); });
        submits.forEach(button => { button.disabled = true; });
        document.querySelectorAll('[data-hosting-state]').forEach(item => { item.textContent = 'NOT CHECKED'; });
        recheck.disabled = true;
        let active = 'routes';
        try {
            if (new URL(base).origin !== window.location.origin) throw new Error('The application URL does not match this browser origin. Check the public URL and HTTPS/proxy settings.');
            const route = await fetchAt('__upi_deployment_check');
            const routing = route.ok ? await route.json() : null;
            if (!routing || routing.application !== 'upi-gateway' || routing.routing !== true) throw new Error('Clean routes do not reach the application. Apply the public-only panel mapping.');
            if (new URL(base).protocol === 'https:' && routing.runtime === 'laravel' && !routing.secure) throw new Error('Laravel does not detect HTTPS. Configure only the actual trusted proxy IPs/CIDRs and recheck before proceeding.');
            const prefix = new URL(base).pathname.replace(/\/$/, '');
            if (routing.base_path !== prefix) throw new Error('The PHP script mount does not match the application URL prefix.');
            state('routes', routing.runtime === 'laravel' ? 'PASS — Laravel reached' : 'PASS — fresh installer front controller reached');
            active = 'assets';
            const assets = await fetchAt('deployment-check.json');
            if (!assets.ok || (await assets.json()).public_assets !== true) throw new Error('Static files are not mapped to public/.');
            const manifest = await fetchAt('build/manifest.json');
            if (!manifest.ok) throw new Error('The production Vite manifest cannot be loaded.');
            const entries = await manifest.json();
            for (const [entry, type] of [['resources/js/app.js', 'javascript'], ['resources/css/app.css', 'css']]) {
                const file = entries[entry]?.file;
                if (!file || file.includes('..') || file.startsWith('/')) throw new Error('The Vite manifest contains an invalid asset path.');
                const asset = await fetchAt('build/' + file);
                if (!asset.ok || !(asset.headers.get('content-type') || '').includes(type)) throw new Error('A compiled CSS/JavaScript file cannot be loaded with its correct content type.');
            }
            state('assets', 'PASS');
            active = 'private';
            for (const path of ['deployment-private-check.txt', '.env', 'vendor/autoload.php', 'storage/logs/laravel.log']) {
                // HEAD only: never download secrets while checking protection.
                const response = await fetchAt(path, {method: 'HEAD'});
                if (![403, 404].includes(response.status)) throw new Error('Private-file protection failed for ' + path + '. Stop setup and fix the public-only mount.');
            }
            state('private', 'PASS');
            active = 'session';
            const native = routing.runtime !== 'laravel';
            const endpoint = native ? 'install.php?step=deployment-check' : '__upi_deployment_check';
            const parameters = new URLSearchParams({_token: native ? installerCsrf : routing.csrf_token});
            if (!native) parameters.set('probe', routing.probe);
            const sessionResponse = await fetchAt(endpoint, {method: 'POST', body: parameters});
            const session = sessionResponse.ok ? await sessionResponse.json() : null;
            if (!session || !session.session || !session.csrf || (native ? session.cookie_path : routing.cookie_path) !== prefix + '/') throw new Error('Session cookies, cookie path or CSRF validation failed.');
            state('session', 'PASS');
            active = 'urls';
            if (native || !routing.installed) {
                state('urls', 'WARNING — login/dashboard available after database installation');
            } else {
                if (routing.login_url !== base + '/login' || routing.dashboard_url !== base + '/dashboard') throw new Error('Generated login/dashboard URLs do not match the public application URL.');
                const login = await fetchAt('login');
                if (!login.ok) throw new Error('The login URL cannot be loaded.');
                state('urls', 'PASS');
            }
            active = 'lock';
            if (finalCheck) {
                const lock = await fetchAt('install.php', {method: 'HEAD'});
                if (!routing.installed || lock.status !== 403) throw new Error('The completed installer is not locked.');
                state('lock', 'PASS');
            } else {
                state('lock', 'NOT CHECKED — verified after administrator creation');
            }
            links.forEach(link => { link.href = link.dataset.deploymentHref; link.removeAttribute('aria-disabled'); });
            submits.forEach(button => { button.disabled = false; });
            output.textContent = finalCheck ? 'PASS — final hosting verification complete. Proceed to login.' : 'PASS — required hosting checks passed. Continue setup.';
        } catch (error) {
            state(active, 'FAIL');
            output.textContent = 'FAIL — ' + error.message + ' Use the hosting-panel instructions and Recheck. No SSH access is required.';
        } finally {
            recheck.disabled = false;
        }
    };
    recheck.addEventListener('click', check);
    check();
});
</script>
