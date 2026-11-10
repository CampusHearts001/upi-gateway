@php
    $finalCheck = $finalCheck ?? false;
    $serverReady = $serverReady ?? true;
    $nativeInstaller = $nativeInstaller ?? false;
@endphp
<style nonce="{{ $csp_nonce ?? '' }}">
    .installer-readiness { text-align: center; padding: 1.5rem 0 1.25rem; }
    .installer-readiness__icon { width: 4.75rem; height: 4.75rem; margin: 0 auto 1.125rem; border: 1px solid #c7d2fe; border-radius: 1.35rem; display: grid; place-items: center; background: #f5f7ff; color: #4f46e5; box-shadow: 0 10px 24px rgba(79,70,229,.1); transition: background .2s ease, color .2s ease, border-color .2s ease, box-shadow .2s ease; }
    .installer-readiness__spinner { width: 2rem; height: 2rem; border: 3px solid #c7d2fe; border-top-color: #4f46e5; border-radius: 9999px; animation: installer-spin .8s linear infinite; }
    .installer-readiness[data-state="ready"] .installer-readiness__icon { border-color: #a7f3d0; background: #ecfdf5; color: #059669; box-shadow: 0 10px 24px rgba(5,150,105,.1); }
    .installer-readiness[data-state="failed"] .installer-readiness__icon { border-color: #fecaca; background: #fef2f2; color: #dc2626; box-shadow: 0 10px 24px rgba(220,38,38,.08); }
    .installer-readiness__symbol { display: none; width: 2.25rem; height: 2.25rem; }
    .installer-readiness[data-state="ready"] .installer-readiness__spinner,
    .installer-readiness[data-state="failed"] .installer-readiness__spinner { display: none; }
    .installer-readiness[data-state="ready"] [data-ready-icon],
    .installer-readiness[data-state="failed"] [data-failed-icon] { display: block; }
    .installer-readiness__title { margin: 0; color: #0f172a; font-size: 1.2rem; font-weight: 750; letter-spacing: -.02em; }
    .installer-readiness__message { max-width: 27rem; margin: .55rem auto 0; color: #64748b; font-size: .9rem; line-height: 1.6; }
    [data-deployment-continue][hidden], [data-deployment-submit][hidden], [data-hosting-recheck] { display: none !important; }
    @keyframes installer-spin { to { transform: rotate(360deg); } }
</style>

<div class="installer-readiness" data-installer-readiness data-state="checking" role="status" aria-live="polite">
    <div class="installer-readiness__icon" aria-hidden="true">
        <span class="installer-readiness__spinner"></span>
        <svg data-ready-icon class="installer-readiness__symbol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 7"/></svg>
        <svg data-failed-icon class="installer-readiness__symbol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 8v5"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
    </div>
    <p class="installer-readiness__title" data-hosting-result>Checking...</p>
    <p class="installer-readiness__message" data-hosting-message>We’re making sure everything is ready.</p>
    <button type="button" data-hosting-recheck tabindex="-1" aria-hidden="true">Check again</button>
    <noscript><p class="installer-readiness__message">JavaScript is required to verify your server safely.</p></noscript>
</div>

<script nonce="{{ $csp_nonce ?? '' }}">
document.addEventListener('DOMContentLoaded', () => {
    // Derive probe target origin strictly from the active browser origin
    // (window.location.origin) while preserving any configured application
    // subdirectory mount path. Host aliases (e.g. 127.0.0.1 vs localhost, custom
    // ports, cPanel domains) must never turn same-site probes into cross-origin CORS requests.
    const pageUrl = new URL(window.location.href);
    const configuredBase = @json(rtrim($deploymentUrl ?? '', '/'));
    const basePath = configuredBase ? new URL(configuredBase, pageUrl.origin).pathname.replace(/\/$/, '') : '';
    const base = pageUrl.origin + basePath;
    const finalCheck = @json($finalCheck);
    const serverReady = @json($serverReady);
    const nativeInstaller = @json($nativeInstaller);
    const installerCsrf = @json($deploymentCsrfToken ?? csrf_token());
    const installerDiagToken = @json($deploymentDiagnosticToken ?? (function_exists('session') ? session('installer.diagnostic_token') : null) ?? '');
    const panel = document.querySelector('[data-installer-readiness]');
    const output = panel.querySelector('[data-hosting-result]');
    const message = panel.querySelector('[data-hosting-message]');
    const recheck = panel.querySelector('[data-hosting-recheck]');
    const links = document.querySelectorAll('[data-deployment-continue]');
    const submits = document.querySelectorAll('[data-deployment-submit]');
    const setStatus = (state, reason = null) => {
        panel.dataset.state = state;
        output.textContent = state === 'ready' ? 'All checks passed' : state === 'failed' ? 'Setup not ready' : 'Checking...';
        message.textContent = state === 'ready'
            ? 'Your server is ready. You can continue safely.'
            : state === 'failed'
                ? reason === 'public_directory'
                    ? 'Open your hosting panel and set the website’s Running Directory to /public, then refresh this page.'
                    : 'Your server is not ready for installation. Please contact your hosting administrator.'
                : 'We’re making sure everything is ready.';
    };
    const fetchAt = (path, options = {}) => {
        const cleanBase = base.replace(/\/$/, '');
        const cleanPath = String(path || '').replace(/^\//, '');
        const target = new URL(cleanBase + '/' + cleanPath, window.location.origin);
        target.searchParams.set('__upi_check', Date.now() + '-' + Math.random());
        return fetch(target, {
            cache: 'no-store', credentials: 'same-origin', signal: AbortSignal.timeout(12000), ...options
        });
    };
    let routing = null;
    const reportFailure = async (stage, error) => {
        const diagToken = nativeInstaller ? installerDiagToken : routing?.diagnostic_token;
        if (!diagToken) return;
        const endpoint = nativeInstaller ? 'install.php?step=deployment-check' : '__upi_deployment_check';
        const parameters = new URLSearchParams({
            _token: nativeInstaller ? installerCsrf : (routing?.csrf_token || installerCsrf),
            diagnostic_report: '1',
            diagnostic_token: diagToken,
            stage,
            message: String(error?.message || 'Unknown readiness failure').slice(0, 500),
        });
        try { await fetchAt(endpoint, {method: 'POST', body: parameters}); } catch (_) {}
    };
    const check = async () => {
        links.forEach(link => { link.hidden = true; link.removeAttribute('href'); link.setAttribute('aria-disabled', 'true'); });
        submits.forEach(button => { button.hidden = true; button.disabled = true; });
        setStatus('checking');
        let stage = 'server';
        try {
            if (!serverReady) throw new Error('A required server capability or writable directory is unavailable.');
            if (new URL(base).origin !== window.location.origin) throw new Error('The configured application origin does not match the browser origin.');
            stage = 'public_directory';
            const privateSentinel = await fetchAt('deployment-private-check.txt', {method: 'HEAD'});
            if (![403, 404].includes(privateSentinel.status)) throw new Error('The website root exposes application-private files.');
            stage = 'routing';
            const route = await fetchAt('__upi_deployment_check');
            routing = route.ok ? await route.json() : null;
            if (!routing || routing.application !== 'upi-gateway' || routing.routing !== true) throw new Error('The application routing probe failed.');
            const handler = routing.php_handler || {
                status: routing.php_fpm ? 'supported' : 'unknown',
                supported: routing.php_fpm === true,
                name: routing.php_fpm ? 'PHP-FPM' : 'Unknown',
            };
            if (!handler.supported) {
                if (handler.status === 'unsupported') {
                    throw new Error('The detected PHP handler (' + (handler.name || 'unsupported') + ') is not supported.');
                }
                throw new Error('Unrecognized PHP handler (' + (handler.name || 'unknown') + '). PHP-FPM or a compatible handler is required.');
            }
            if (new URL(base).protocol === 'https:' && routing.runtime === 'laravel' && !routing.secure && window.location.protocol !== 'https:') throw new Error('HTTPS was not detected behind the configured proxy.');
            const prefix = new URL(base).pathname.replace(/\/$/, '');
            if (routing.installed && routing.base_path !== prefix) throw new Error('The application URL prefix does not match the PHP mount.');
            stage = 'assets';
            const assets = await fetchAt('deployment-check.json');
            if (!assets.ok || (await assets.json()).public_assets !== true) throw new Error('The public directory probe failed.');
            const manifest = await fetchAt('build/manifest.json');
            if (!manifest.ok) throw new Error('The frontend asset manifest is unavailable.');
            const entries = await manifest.json();
            for (const [entry, type] of [['resources/js/app.js', 'javascript'], ['resources/css/app.css', 'css']]) {
                const file = entries[entry]?.file;
                if (!file || file.includes('..') || file.startsWith('/')) throw new Error('The frontend asset manifest contains an invalid path.');
                const asset = await fetchAt('build/' + file);
                if (!asset.ok || !(asset.headers.get('content-type') || '').includes(type)) throw new Error('A compiled frontend asset failed its HTTP check.');
            }
            stage = 'private_files';
            for (const path of ['.env', 'vendor/autoload.php', 'storage/logs/laravel.log']) {
                const response = await fetchAt(path, {method: 'HEAD'});
                if (![403, 404].includes(response.status)) throw new Error('Private-file protection failed for ' + path + '.');
            }
            stage = 'session';
            const native = routing.runtime !== 'laravel';
            const endpoint = native ? 'install.php?step=deployment-check' : '__upi_deployment_check';
            const parameters = new URLSearchParams({_token: native ? installerCsrf : routing.csrf_token});
            if (!native) parameters.set('probe', routing.probe);
            const sessionResponse = await fetchAt(endpoint, {method: 'POST', body: parameters});
            const session = sessionResponse.ok ? await sessionResponse.json() : null;
            const cookiePath = native ? session?.cookie_path : routing.cookie_path;
            const normalizeCookiePath = val => {
                if (!val) return '/';
                const p = '/' + String(val).replace(/^\/+|\/+$/g, '');
                return p === '/' ? '/' : p + '/';
            };
            const normalizedCookie = normalizeCookiePath(cookiePath);
            const normalizedExpected = normalizeCookiePath(prefix);
            if (!session || !session.session || !session.csrf || (cookiePath && normalizedCookie !== '/' && normalizedCookie !== normalizedExpected)) throw new Error('The session or CSRF round-trip failed.');
            stage = 'application_urls';
            if (!native && routing.installed) {
                if (routing.login_url !== base + '/login' || routing.dashboard_url !== base + '/dashboard') throw new Error('Generated application URLs do not match the public URL.');
                const login = await fetchAt('login');
                if (!login.ok) throw new Error('The login route is unavailable.');
            }
            stage = 'installer_lock';
            if (finalCheck) {
                const lock = await fetchAt('install.php', {method: 'HEAD'});
                if (!routing.installed || lock.status !== 403) throw new Error('The completed installer is not locked.');
            }
            links.forEach(link => { link.href = link.dataset.deploymentHref; link.hidden = false; link.removeAttribute('aria-disabled'); });
            submits.forEach(button => { button.hidden = false; button.disabled = false; });
            setStatus('ready');
        } catch (error) {
            setStatus('failed', stage);
            await reportFailure(stage, error);
        }
    };
    recheck.addEventListener('click', check);
    check();
});
</script>
