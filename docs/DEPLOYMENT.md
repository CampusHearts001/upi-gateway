# Domains, subdomains and subdirectories

The same production package supports a main domain, a subdomain or a URL prefix. The application URL is the public address, for example `https://example.com`, `https://pay.example.com` or `https://example.com/payment`. Never append `/public` to that URL.

Only the package's **public directory** may be served. Keep the application, `.env`, dependencies, database files, logs and private storage outside the publicly served tree. PHP cannot protect files that a web server serves without invoking PHP. Nginx ignores `.htaccess`; uploading a ZIP alone cannot configure Nginx or prove private files are protected.

## Before entering database credentials

1. Extract the production package using the panel File Manager. Do not copy an existing installation's `.env` into a new public directory.
2. Configure the public directory or public-only URL mount below. Leave the parent website's document root and unrelated rules unchanged.
3. Open the installer. Check the detected URL, including its subdirectory prefix. The requirements screen checks clean routing, the Vite manifest and actual CSS/JS files, and a harmless private sentinel. The Continue link is enabled only after these browser checks pass. These checks are diagnostic evidence, not a replacement for correct server configuration.
4. Finish installation. The installer writes `APP_URL`, `ASSET_URL`, a prefix-scoped `SESSION_PATH`, and the HTTPS cookie setting. Never share `.env`, passwords or server access with the application vendor.

## Panel configuration

| Hosting | Main domain or subdomain | Subdirectory under an existing site |
|---|---|---|
| aaPanel / Nginx | Website → Site directory → Running directory: select the application's `public` directory; configure Laravel routing. | Website → Config: back up the active domain configuration, then add the installer-generated public-only mount. Use the `fastcgi_pass` address from the **active PHP handler**, not an address guessed from the PHP version. |
| cPanel / Apache | Set the domain/subdomain document root to the application's `public` directory. | Have the panel or hosting provider map the chosen URL prefix to the application's `public` directory. Enable `.htaccess`/mod_rewrite for that directory. |
| CyberPanel / OpenLiteSpeed | Set the virtual host document root to `public` and enable rewrite rules. | Add a static context mapping the chosen URI prefix to `public`, enable rewrite rules in that context and inherit the site's PHP handler. Reload through the panel. |
| Plesk | Hosting Settings: set document root to `public`. | Configure a public-only mount in the active serving layer. With Nginx serving static files, Apache rewrite rules alone are insufficient; use the generated Nginx mount or ask the provider to configure the prefix. |
| DirectAdmin | Set the domain/subdomain document root to `public` using the panel/provider's supported override. | Ask the provider to configure a prefix mount in the active Nginx, Apache or OpenLiteSpeed virtual host. Apache rules alone do not protect a front-end Nginx static location. |
| Nginx VPS | Serve only `public`, with Laravel front-controller forwarding. | Use the same generated Nginx mount in the parent server block. |
| Apache VPS | Serve only `public`, with mod_rewrite and AllowOverride enabled. | Use an Apache Alias/context mapping only `public`; keep the parent DocumentRoot unchanged. |

Panel names and permissions vary. If a shared-hosting account cannot edit virtual hosts, the hosting provider must configure the mount. This is a control-panel/provider task; customers do not need to supply SSH credentials or run terminal commands. Do not select the entire project directory as an unrestricted public document root.

## Generated Nginx configuration

On Nginx, the installer detects the application URL prefix and the actual physical `public` path. Its configuration panel asks for the PHP-FPM socket or local TCP address from the active handler. Enter that value to obtain a copy-ready configuration; the application cannot discover an arbitrary external Nginx include from PHP.

The generated configuration:

- Uses a `^~` prefix with an alias to **public only**, so parent static/PHP regex locations cannot intercept app requests.
- Rejects hidden files and unintended PHP execution inside that mount.
- Tries only public files and forwards other requests to a named PHP location. A private `docs/` directory therefore cannot shadow the `/docs` route.
- Sets `SCRIPT_FILENAME` to the physical `public/index.php`, `SCRIPT_NAME` to the virtual prefix plus `/index.php`, and preserves the original `REQUEST_URI`, query and HTTP method.
- Gives `public/install.php` its own exact PHP handler; it does not expose arbitrary project PHP files.

Back up the active domain configuration before applying it. Remove conflicting locations for this prefix only. Use the panel's configuration validation (`nginx -t` equivalent) before reloading. If validation fails, retain the backup and do not reload. A generic rewrite field may not allow `location` blocks; use the domain server configuration or ask the provider.

## Assets, payments and storage

Do not move `build` into the project root or rebuild assets for each folder. `public/build/manifest.json` identifies the assets; their public URLs are `<APP_URL>/build/...`. Runtime routes, API endpoints, payment pages and provider return URLs all retain the configured prefix. Merchant webhook destinations remain the HTTPS URLs configured by the merchant; they are not rewritten to the gateway's host.

Files written to the **public** filesystem disk are served at `<APP_URL>/storage/...` through a restricted controller when no public symlink exists. No `storage:link` command is required. Private storage and logs have no public URL. Do not create a symlink to the entire `storage` directory or enable execution of uploaded scripts.

## Browser acceptance checks

For each chosen domain/prefix, verify the application root, login, dashboard, docs, a manifest-listed CSS file and JS file, an authenticated API request, a payment link and provider return URL, and a public file saved by the application. Check that a login submission without a CSRF token fails and a legitimate login persists across navigation. An administrator uses the same login page and retains the administrator controls.

Verify `.env`, `vendor/`, `app/`, `config/`, private storage and `deployment-private-check.txt` return 403 or 404. Confirm the parent website still works. Do not treat a styled login page alone as proof of safe deployment. Clear relevant CDN caches after configuration changes.

If `.env` was previously publicly readable, block access first and review access logs and exposed credentials. Do not blindly rotate `APP_KEY`: encrypted data requires a coordinated migration/recovery plan.

## Verification limits

Automated checks cover application URLs and isolated local Nginx/PHP-FPM and Apache/PHP-FPM deployments. These do not certify every control-panel version. Live aaPanel, cPanel, CyberPanel, Plesk and DirectAdmin verification requires an approved isolated hosting installation; the product does not silently edit their configurations.
