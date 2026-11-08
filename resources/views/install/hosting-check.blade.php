<div class="hosting-check" role="status" aria-live="polite">
    <strong>Public URL and file protection check</strong>
    <p data-hosting-result>Checking clean routes, production assets and private-file protection before setup…</p>
    <button type="button" data-hosting-recheck>Recheck hosting configuration</button>
    <noscript>Enable JavaScript to run the hosting checks before continuing.</noscript>
</div>
<script nonce="{{ $csp_nonce ?? '' }}">
document.addEventListener('DOMContentLoaded', () => {
    const base = @json(rtrim($deploymentUrl, '/'));
    const output = document.querySelector('[data-hosting-result]');
    const recheck = document.querySelector('[data-hosting-recheck]');
    const links = document.querySelectorAll('[data-deployment-continue]');
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
    const get = (path) => fetch(base + '/' + path, {
        cache: 'no-store', credentials: 'same-origin', signal: AbortSignal.timeout(12000)
    });
    const check = async () => {
        links.forEach(link => { link.removeAttribute('href'); link.setAttribute('aria-disabled', 'true'); });
        recheck.disabled = true;
        try {
            if (new URL(base).origin !== window.location.origin) throw new Error('The detected application URL does not match this browser origin. Check HTTPS/proxy settings.');
            const route = await get('__upi_deployment_check');
            const routing = route.ok ? await route.json() : null;
            if (!routing || routing.application !== 'upi-gateway' || routing.routing !== true) throw new Error('Clean routes do not reach the application. Apply the panel routing configuration.');
            const assets = await get('deployment-check.json');
            if (!assets.ok || (await assets.json()).public_assets !== true) throw new Error('Public files are not mapped to the application public directory.');
            const manifest = await get('build/manifest.json');
            if (!manifest.ok) throw new Error('The production Vite manifest is missing or unreachable.');
            const entries = await manifest.json();
            for (const [entry, type] of [['resources/js/app.js', 'javascript'], ['resources/css/app.css', 'css']]) {
                const file = entries[entry]?.file;
                if (!file || file.includes('..') || file.startsWith('/')) throw new Error('The production asset manifest is invalid.');
                const asset = await get('build/' + file);
                if (!asset.ok || !(asset.headers.get('content-type') || '').includes(type)) throw new Error('A compiled CSS/JavaScript file cannot be loaded from its public URL.');
            }
            const privateFile = await get('deployment-private-check.txt');
            if (![403, 404].includes(privateFile.status)) throw new Error('Private application files may be exposed. Stop setup and correct the public-only mount.');
            links.forEach(link => { link.href = link.dataset.deploymentHref; link.removeAttribute('aria-disabled'); });
            output.textContent = 'Passed: clean routes, CSS/JavaScript and private-file protection. Continue with database setup.';
        } catch (error) {
            output.textContent = 'Hosting configuration not verified: ' + error.message + ' This requires a panel or hosting-provider configuration change, not SSH access.';
        } finally {
            recheck.disabled = false;
        }
    };
    recheck.addEventListener('click', check);
    check();
});
</script>
