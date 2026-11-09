# Domains, subdomains and subdirectories

The same production package supports a main domain, a subdomain or a URL prefix. The application URL is the public address, for example `https://example.com`, `https://pay.example.com` or `https://example.com/payment`. Never append `/public` to that URL.

Only the package's **public directory** may be served. Keep the application, `.env`, dependencies, database files, logs and private storage outside the publicly served tree. PHP cannot protect files that a web server serves without invoking PHP. Nginx ignores `.htaccess`; uploading a ZIP alone cannot configure Nginx or prove private files are protected.

## Before entering database credentials

1. Extract the production package using the panel File Manager. Do not copy an existing installation's `.env` into a new public directory.
2. Configure the public directory or public-only URL mount below. Leave the parent website's document root and unrelated rules unchanged.
3. Open the installer. Check the detected URL, including its subdirectory prefix. The requirements screen checks clean routing, the Vite manifest and actual CSS/JS files, and a harmless private sentinel. The Continue link is enabled only after these browser checks pass. These checks are diagnostic evidence, not a replacement for correct server configuration.
4. Finish installation. The installer writes `APP_URL`, `ASSET_URL`, a prefix-scoped `SESSION_PATH`, and the HTTPS cookie setting. Never share `.env`, passwords or server access with the application vendor.

Diagnostics use **PASS**, **WARNING**, **FAIL** and **NOT CHECKED** explicitly. A fresh installer probe identifies the PHP front controller; after database setup, the diagnostic route must identify Laravel. The session check performs a real CSRF-protected POST. The final page checks the configured login/dashboard addresses and that the native installer returns 403 before enabling its login link. A final-check failure does not reopen an already locked installer.

Missing storage/cache directories are created with restricted defaults only within the canonical application tree. Existing uploads, directories and permissions are preserved; an external symlink is not followed to create missing directories. Requests and files must still be writable by the hosting PHP user. The installer cannot fix a read-only account or change ownership automatically.

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

## cPanel main domain

Use File Manager to extract the package into its own application directory. In Domains, point the dedicated domain's document root to that directory's `public` folder. Keep `.env` and `vendor` outside the served directory. Open the public domain address and run the installer checks. If the primary domain root is fixed by the plan, ask the provider to map it to `public`; do not move private files into an unrestricted root.

## cPanel subdomain

Create/select the subdomain in Domains and set its document root to the application's `public` folder. It can share a hosting account with the main website without sharing a document root or session cookie. Install at the subdomain's HTTPS address. No `/public` belongs in the URL.

## cPanel subdirectory

Keep the main domain's document root unchanged. Prefer a provider-managed Alias/context from the chosen URL prefix to the application's `public` directory; the installer shows an Apache example for the actual path. On Apache plans that support folder-local overrides, the package's own `.htaccess` can internally map that folder to `public` and deny private files. Do not overwrite the main site's `.htaccess`, and do not assume overrides are active: the browser checks must pass before entering credentials. If overrides are disabled or a front-end Nginx serves files directly, ask the provider to configure a public-only mount.

## aaPanel Nginx main domain

For a dedicated application domain, choose Website → domain → Site directory and set Running directory to the application's `public` folder. Enable Laravel clean routing using the panel's supported configuration. Inspect the active PHP handler rather than guessing its socket. Save a backup before editing and validate the configuration before reloading. Then open the domain's HTTPS address and run Recheck in the installer.

## aaPanel Nginx subdomain

Create/select the subdomain as a separate website in aaPanel. Its Running directory is the application's `public` folder and its PHP version must meet the displayed requirements. Keep the parent domain's site configuration unchanged. The installer detects the subdomain address; the routing requirements are the same as a dedicated domain.

## aaPanel Nginx subdirectory

1. Keep the existing website's root and Running directory unchanged. Extract the application into a separate directory and note its physical `public` path.
2. Open the installer to obtain the detected prefix and physical path. If no installer entry point is reachable, ask the provider to map the chosen prefix to that public path first.
3. In Website → domain → Config, inspect the active PHP include. Open that included handler through the panel/File Manager and copy its `fastcgi_pass` value into the installer's PHP-FPM field. Do not send passwords, SSH access or private keys to the application vendor.
4. Back up the active domain configuration using the panel or save its complete text. Add the generated locations **inside that domain's existing server block**. Replace earlier conflicting locations for this application prefix only. Do not replace the whole server block, main root, SSL configuration, global PHP handler or unrelated static locations.
5. Validate with the panel's Nginx configuration test before reloading. If the panel cannot perform validation, ask the hosting provider to run `nginx -t` and reload only after it succeeds. No terminal commands are required from the customer.
6. Return to the installer and select Recheck. Verify the main website separately. Never add `/public` to `APP_URL` or create a public symlink to the entire project to bypass a failure.

The configuration shown by the installer is generated from the current installation, not a hardcoded staging path. An unresolved PHP-FPM address is explicitly marked and disables the Copy button until supplied. Nginx configuration is never edited automatically.

## Assets, payments and storage

Do not move `build` into the project root or rebuild assets for each folder. `public/build/manifest.json` identifies the assets; their public URLs are `<APP_URL>/build/...`. Runtime routes, API endpoints, payment pages and provider return URLs all retain the configured prefix. Merchant webhook destinations remain the HTTPS URLs configured by the merchant; they are not rewritten to the gateway's host.

Each installation gets a distinct Laravel session-cookie name derived from its canonical URL, as well as the appropriate cookie path. The native installer uses its own installation-scoped session name. This keeps separate installations and the parent website's PHP session separate. A hostname alone cannot reliably identify whether it is a registered domain or subdomain without a public-suffix source; both use the same prefix-free mapping, and the installer displays that equivalence rather than guessing.

Files written to the **public** filesystem disk are served at `<APP_URL>/storage/...` through a restricted controller when no public symlink exists. No `storage:link` command is required. Private storage and logs have no public URL. Do not create a symlink to the entire `storage` directory or enable execution of uploaded scripts.

## Browser acceptance checks

For each chosen domain/prefix, verify the application root, login, dashboard, docs, a manifest-listed CSS file and JS file, an authenticated API request, a payment link and provider return URL, and a public file saved by the application. Check that a login submission without a CSRF token fails and a legitimate login persists across navigation. An administrator uses the same login page and retains the administrator controls.

Verify `.env`, `vendor/`, `app/`, `config/`, private storage and `deployment-private-check.txt` return 403 or 404. Confirm the parent website still works. Do not treat a styled login page alone as proof of safe deployment. Clear relevant CDN caches after configuration changes.

If `.env` was previously publicly readable, block access first and review access logs and exposed credentials. Do not blindly rotate `APP_KEY`: encrypted data requires a coordinated migration/recovery plan.

## Verification limits

Automated checks cover application URLs and isolated local Nginx/PHP-FPM and Apache/PHP-FPM deployments. These do not certify every control-panel version. Live aaPanel, cPanel, CyberPanel, Plesk and DirectAdmin verification requires an approved isolated hosting installation; the product does not silently edit their configurations.

Developer-only hosting tests are in `tests/Hosting`. They exercise domains, subdomains, subdirectories and nested subdirectories using private server processes, real Chromium checks and disposable databases. The MySQL installer test creates a separate local daemon and data directory; it never reuses a running MySQL server. Both production and GitHub-style archives are checked for runtime files, storage skeletons, compiled assets and forbidden content before extraction tests. These tools and their locked browser dependencies are excluded from distribution ZIPs and are not customer installation prerequisites.
