const fs = require('node:fs');
const { chromium } = require(process.env.TEST_BROWSER_MODULE);

(async () => {
    const { urls, phase = 'fresh', cookies = [], mysql } = JSON.parse(fs.readFileSync(0, 'utf8'));
    const options = {
        headless: true,
        args: ['--no-proxy-server', '--host-resolver-rules=MAP *.test 127.0.0.1'],
    };
    if (process.env.TEST_BROWSER_EXECUTABLE) options.executablePath = process.env.TEST_BROWSER_EXECUTABLE;
    const browser = await chromium.launch(options);
    let assertions = 0;
    try {
        for (const [index, base] of urls.entries()) {
            const context = await browser.newContext();
            context.setDefaultTimeout(30000);
            context.setDefaultNavigationTimeout(30000);
            if (phase === 'final') await context.addCookies([cookies[index]]);
            await context.route('**/*', route => new URL(route.request().url()).hostname.endsWith('.test') ? route.continue() : route.abort());
            const page = await context.newPage();
            const errors = [];
            const responses = [];
            page.on('response', response => {
                if (response.url().includes('__upi_deployment_check')) responses.push(`${response.request().method()} ${response.status()} ${response.headers()['content-type']} ${response.headers()['location'] || ''} ${response.url()}`);
            });
            page.on('pageerror', error => errors.push(error.message));
            if (phase === 'install') {
                let stage = 'requirements';
                const waitPass = async () => {
                    await page.waitForFunction(() => ['All checks passed', 'Setup not ready'].includes(document.querySelector('[data-hosting-result]')?.textContent || ''));
                    const result = await page.locator('[data-hosting-result]').innerText();
                    if (result !== 'All checks passed') throw new Error(result);
                };
                try {
                if (!mysql) throw new Error('A disposable MySQL configuration is required.');
                await page.goto(base + '/install.php');
                await waitPass();
                stage = 'database';
                await page.locator('[data-deployment-continue]').click();
                const invalidCsrf = async step => {
                    const status = await page.evaluate(async url => (await fetch(url, {method: 'POST', body: new URLSearchParams({_token: 'invalid'})})).status, base + '/install.php?step=' + step);
                    if (status !== 403) throw new Error(`Invalid installer CSRF was accepted at ${step}`);
                    assertions++;
                };
                await invalidCsrf('database');
                await page.locator('input[name="db_host"]').fill('127.0.0.1');
                await page.locator('input[name="db_port"]').fill(String(mysql.port));
                await page.locator('input[name="db_database"]').fill(mysql.database);
                await page.locator('input[name="db_username"]').fill(mysql.user);
                await page.locator('input[name="db_password"]').fill(mysql.password);
                await page.locator('input[name="app_url"]').fill(base);
                await page.getByRole('button', {name: 'Test Connection & Save Configuration'}).click();
                stage = 'migrations';
                await invalidCsrf('migrate');
                await page.getByRole('button', {name: 'Run Database Migrations Now'}).click();
                stage = 'administrator preflight';
                await waitPass();
                await invalidCsrf('admin');
                await page.locator('input[name="name"]').fill('Hosting Administrator');
                await page.locator('input[name="email"]').fill('fresh@example.test');
                await page.locator('input[name="password"]').fill('Fresh-hosting-test-password');
                await page.locator('input[name="password_confirmation"]').fill('Fresh-hosting-test-password');
                await page.getByRole('button', {name: 'Finalize Installation'}).click();
                stage = 'final preflight';
                await waitPass();
                if ((await page.locator('body').innerText()).includes('PHP-FPM endpoint')) throw new Error('Technical diagnostics are visible.');
                assertions++;
                await page.getByRole('link', {name: 'Go to Login'}).click();
                if (page.url() !== base + '/login') throw new Error('Fresh login URL is incorrect.');
                assertions++;
                await page.locator('input[name="email"]').fill('fresh@example.test');
                await page.locator('input[name="password"]').fill('Fresh-hosting-test-password');
                await page.getByRole('button', {name: 'Log in', exact: true}).click();
                await page.waitForURL(base + '/dashboard');
                assertions++;
                await page.goto(base + '/admin/merchants');
                if (![base + '/admin/merchants', base + '/confirm-password'].includes(page.url())) throw new Error('Administrator navigation lost its application prefix.');
                assertions++;
                const locked = await page.evaluate(async url => (await fetch(url, {redirect: 'manual'})).status, base + '/install.php');
                if (locked !== 403) throw new Error('Fresh installation permits re-entry.');
                assertions++;
                await context.close();
                continue;
                } catch (error) {
                    const result = await page.locator('[data-hosting-result]').textContent().catch(() => 'no diagnostic response');
                    throw new Error(`${base} ${stage}: ${result}; ${error.message.split('Call log:')[0]}; probes=${responses.join('; ')}`);
                }
            }
            await page.goto(base + '/install.php' + (phase === 'final' ? '?step=done' : ''), { waitUntil: 'domcontentloaded' });
            try {
                await page.waitForFunction(() => ['All checks passed', 'Setup not ready'].includes(document.querySelector('[data-hosting-result]')?.textContent || ''), null, { timeout: 30000 });
            } catch (error) {
                throw new Error(`${base} ${phase}: ${await page.locator('[data-hosting-result]').textContent()} ${errors.join('; ')}`);
            }
            const result = await page.locator('[data-hosting-result]').textContent();
            if (result !== 'All checks passed') throw new Error(`${base} ${phase}: ${result}`);
            const next = page.locator('[data-deployment-continue]');
            const target = await next.evaluate(element => element.href);
            const expected = base + (phase === 'final' ? '/login' : '/install.php?step=database');
            if (target !== expected) throw new Error(`Incorrect enabled Continue URL: ${target}, expected ${expected}`);
            assertions++;
            const visibleText = await page.locator('body').innerText();
            if (['Nginx configuration', 'PHP-FPM endpoint', 'Application directory', 'Public application address', 'NOT CHECKED'].some(value => visibleText.includes(value))) throw new Error('Technical diagnostics are visible.');
            assertions++;
            // Verify that a failed CSS/JS response disables progression, and Recheck recovers.
            const javascriptAssets = /\/build\/assets\/[^/]+\.js(?:\?.*)?$/;
            await page.route(javascriptAssets, route => route.fulfill({ status: 404, body: 'missing test asset' }));
            await page.evaluate(() => document.querySelector('[data-hosting-recheck]').click());
            await page.waitForFunction(() => document.querySelector('[data-hosting-result]')?.textContent === 'Setup not ready', null, { timeout: 30000 });
            if (await next.getAttribute('href') !== null || !(await next.isHidden())) throw new Error('Failed checks left Next available');
            if ((await page.locator('body').innerText()).includes('compiled frontend')) throw new Error('Raw diagnostic error is visible.');
            assertions++;
            await page.unroute(javascriptAssets);
            await page.evaluate(() => document.querySelector('[data-hosting-recheck]').click());
            await page.waitForFunction(() => document.querySelector('[data-hosting-result]')?.textContent === 'All checks passed', null, { timeout: 30000 });
            if (!(await next.getAttribute('href'))) throw new Error('Recheck did not restore Continue');
            assertions++;
            await context.close();
        }
        console.log(`PASS browser ${phase} installer diagnostics: ${assertions} assertions across ${urls.length} layouts`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
