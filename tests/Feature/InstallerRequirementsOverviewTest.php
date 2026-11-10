<?php

namespace Tests\Feature;

use App\Support\InstallationManager;
use App\Support\NativeInstallerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class InstallerRequirementsOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(config('installer.installed_path', storage_path('installed')));
        @unlink(base_path('storage/installed'));
    }

    protected function tearDown(): void
    {
        @unlink(config('installer.installed_path', storage_path('installed')));
        @unlink(base_path('storage/installed'));
        parent::tearDown();
    }

    public function test_requirements_overview_returns_all_grouped_categories_and_summary(): void
    {
        $installer = app(InstallationManager::class);
        $overview = $installer->getRequirementsOverview();

        $this->assertArrayHasKey('requirements', $overview);
        $this->assertArrayHasKey('summary', $overview);
        $this->assertArrayHasKey('groups', $overview);
        $this->assertArrayHasKey('allPassed', $overview);

        $summary = $overview['summary'];
        $this->assertGreaterThan(20, $summary['total']);
        $this->assertIsInt($summary['passed']);
        $this->assertIsInt($summary['failed']);
        $this->assertIsInt($summary['warnings']);
        $this->assertIsInt($summary['optional']);
        $this->assertIsBool($summary['ready']);

        $groups = $overview['groups'];
        $this->assertArrayHasKey('php', $groups);
        $this->assertArrayHasKey('extensions', $groups);
        $this->assertArrayHasKey('database', $groups);
        $this->assertArrayHasKey('permissions', $groups);
        $this->assertArrayHasKey('security', $groups);

        foreach ($overview['requirements'] as $req) {
            $this->assertArrayHasKey('name', $req);
            $this->assertArrayHasKey('category', $req);
            $this->assertArrayHasKey('required', $req);
            $this->assertArrayHasKey('pass', $req);
            $this->assertArrayHasKey('status', $req);
            $this->assertArrayHasKey('current_value', $req);
            $this->assertArrayHasKey('description', $req);
            $this->assertArrayHasKey('remediation', $req);
            $this->assertContains($req['status'], ['PASS', 'MISSING', 'DISABLED', 'FAILED', 'WARNING', 'OPTIONAL']);
        }
    }

    public function test_missing_extension_displays_missing_status_and_blocks_progression(): void
    {
        $installer = app(InstallationManager::class);

        // Verify missing extension detection and blocking
        $checks = $installer->checkRequirements(['BCMath Extension' => false]);
        $bcmathCheck = collect($checks)->firstWhere('name', 'BCMath Extension');

        $this->assertNotNull($bcmathCheck);
        $this->assertFalse($bcmathCheck['pass']);
        $this->assertSame('MISSING', $bcmathCheck['status']);
        $this->assertStringContainsString('cPanel', $bcmathCheck['remediation']);
        $this->assertFalse($installer->allRequirementsPassed(['BCMath Extension' => false]));

        // Render view with simulated missing extension
        $overview = $installer->getRequirementsOverview(['BCMath Extension' => false]);
        $view = view('install.requirements-overview', [
            'overview' => $overview,
            'summary' => $overview['summary'],
            'groups' => $overview['groups'],
            'requirements' => $overview['requirements'],
            'allPassed' => false,
            'nativeInstaller' => false,
            'csrfToken' => 'test-token',
        ])->render();

        $this->assertStringContainsString('MISSING', $view);
        $this->assertStringContainsString('BCMath Extension', $view);
        $this->assertStringContainsString('Resolve all failed requirements before proceeding.', $view);
        $this->assertStringContainsString('is-disabled', $view);
    }

    public function test_disabled_requirement_displays_disabled_status_and_remediation_guide(): void
    {
        $installer = app(InstallationManager::class);

        // Verify disabled requirement detection
        $checks = $installer->checkRequirements(['Cryptographic Randomness' => 'DISABLED']);
        $randomCheck = collect($checks)->firstWhere('name', 'Cryptographic Randomness');

        $this->assertNotNull($randomCheck);
        $this->assertFalse($randomCheck['pass']);
        $this->assertSame('DISABLED', $randomCheck['status']);
        $this->assertStringContainsString('disable_functions', $randomCheck['remediation']);
        $this->assertFalse($installer->allRequirementsPassed(['Cryptographic Randomness' => 'DISABLED']));

        // Render view with simulated disabled requirement
        $overview = $installer->getRequirementsOverview(['Cryptographic Randomness' => 'DISABLED']);
        $view = view('install.requirements-overview', [
            'overview' => $overview,
            'summary' => $overview['summary'],
            'groups' => $overview['groups'],
            'requirements' => $overview['requirements'],
            'allPassed' => false,
            'nativeInstaller' => false,
            'csrfToken' => 'test-token',
        ])->render();

        $this->assertStringContainsString('DISABLED', $view);
        $this->assertStringContainsString('Cryptographic Randomness', $view);
        $this->assertStringContainsString('disable_functions', $view);
        $this->assertStringContainsString("Why it's needed:", $view);
    }

    public function test_failed_permission_displays_failed_status_with_detected_permissions(): void
    {
        $installer = app(InstallationManager::class);

        // Storage writable check reports detected permissions
        $checks = $installer->checkRequirements();
        $storageCheck = collect($checks)->firstWhere('name', 'storage/ writable');

        $this->assertNotNull($storageCheck);
        $this->assertMatchesRegularExpression('/Writable \(\d{4}\)/', $storageCheck['current_value']);

        // When permission fails
        $failedChecks = $installer->checkRequirements(['storage/ writable' => false]);
        $failedStorage = collect($failedChecks)->firstWhere('name', 'storage/ writable');

        $this->assertNotNull($failedStorage);
        $this->assertFalse($failedStorage['pass']);
        $this->assertSame('FAILED', $failedStorage['status']);
        $this->assertStringContainsString('0555', $failedStorage['current_value']);
        $this->assertFalse($installer->allRequirementsPassed(['storage/ writable' => false]));

        // Render view with simulated failed permission
        $overview = $installer->getRequirementsOverview(['storage/ writable' => false]);
        $view = view('install.requirements-overview', [
            'overview' => $overview,
            'summary' => $overview['summary'],
            'groups' => $overview['groups'],
            'requirements' => $overview['requirements'],
            'allPassed' => false,
            'nativeInstaller' => false,
            'csrfToken' => 'test-token',
        ])->render();

        $this->assertStringContainsString('FAILED', $view);
        $this->assertStringContainsString('storage/ writable', $view);
        $this->assertStringContainsString('0555', $view);
        $this->assertStringContainsString('In cPanel File Manager or via SSH', $view);
    }

    public function test_optional_component_warning_does_not_block_progression(): void
    {
        $installer = app(InstallationManager::class);

        // SQLite optional missing should not block progression
        $overview = $installer->getRequirementsOverview(['PDO SQLite (optional)' => 'OPTIONAL']);
        $this->assertTrue($overview['allPassed']);
        $this->assertSame(0, $overview['summary']['failed']);
        $this->assertTrue($installer->allRequirementsPassed(['PDO SQLite (optional)' => 'OPTIONAL']));

        // Warning on memory limit should not block progression
        $overviewWarn = $installer->getRequirementsOverview(['PHP Memory Limit' => 'WARNING']);
        $this->assertTrue($overviewWarn['allPassed']);
        $this->assertSame(0, $overviewWarn['summary']['failed']);
        $this->assertGreaterThanOrEqual(1, $overviewWarn['summary']['warnings']);
        $this->assertTrue($installer->allRequirementsPassed(['PHP Memory Limit' => 'WARNING']));
    }

    public function test_recheck_endpoints_run_fresh_validation(): void
    {
        // 1. Laravel recheck endpoint
        $this->get('/install')->assertOk();
        $token = session()->token();
        $response = $this->post(route('install.recheck'), [
            '_token' => $token,
        ]);
        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $data = $response->json();
        $this->assertArrayHasKey('ready', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('groups', $data);
        $this->assertArrayHasKey('requirements', $data);

        // 2. Native install.php deployment-check endpoint
        $this->get('/install.php')->assertOk();
        $csrf = $_SESSION['_installer_csrf'] ?? '';

        $nativeResponse = $this->post('/install.php?step=deployment-check', [
            '_token' => $csrf,
            'recheck' => '1',
        ]);
        $nativeResponse->assertOk();
        $nativeData = $nativeResponse->json();
        $this->assertArrayHasKey('ready', $nativeData);
        $this->assertArrayHasKey('requirements', $nativeData);
        $this->assertArrayHasKey('summary', $nativeData);
        $this->assertArrayHasKey('groups', $nativeData);
        $this->assertArrayHasKey('allPassed', $nativeData);
    }

    public function test_installer_screens_render_five_grouped_sections_and_summary(): void
    {
        // Test Laravel installer index screen
        $res = $this->get(route('install.index'));
        $res->assertOk();
        $res->assertSee('System Ready for Installation');
        $res->assertSee('Total Checks');
        $res->assertSee('PHP Environment');
        $res->assertSee('PHP Extensions');
        $res->assertSee('Database Support');
        $res->assertSee('Filesystem Permissions');
        $res->assertSee('Hosting &amp; Security', false);
        $res->assertSee('Recheck Requirements');
        $res->assertSee('Next: Database Setup');

        // Test Native install.php screen
        $nativeRes = $this->get('/install.php');
        $nativeRes->assertOk();
        $nativeRes->assertSee('System Ready for Installation');
        $nativeRes->assertSee('Total Checks');
        $nativeRes->assertSee('PHP Environment');
        $nativeRes->assertSee('PHP Extensions');
        $nativeRes->assertSee('Database Support');
        $nativeRes->assertSee('Filesystem Permissions');
        $nativeRes->assertSee('Hosting &amp; Security', false);
        $nativeRes->assertSee('Recheck Requirements');
        $nativeRes->assertSee('Next: Database Setup');
    }

    public function test_fresh_installation_when_env_does_not_exist(): void
    {
        $installer = app(InstallationManager::class);

        // When .env does not exist, check uses project root directory permissions
        $checks = $installer->checkRequirements();
        $envCheck = collect($checks)->firstWhere('name', '.env writable');

        $this->assertNotNull($envCheck);
        $this->assertTrue($envCheck['required']);
        $this->assertStringContainsString('0', $envCheck['current_value']);
        $this->assertNotEmpty($envCheck['remediation']);
    }

    public function test_recheck_endpoints_reject_invalid_csrf_token(): void
    {
        // 1. Laravel recheck endpoint rejects invalid CSRF with 403
        $response = $this->post(route('install.recheck'), [
            '_token' => 'invalid-token-123',
        ]);
        $response->assertStatus(403);

        // 2. Native install.php rejects invalid CSRF with 403
        $nativeResponse = $this->post('/install.php?step=deployment-check', [
            '_token' => 'invalid-token-123',
            'recheck' => '1',
        ]);
        $nativeResponse->assertStatus(403);
        $nativeData = $nativeResponse->json();
        $this->assertFalse($nativeData['csrf']);
        $this->assertFalse($nativeData['ready']);

        $missingTokenResponse = $this->post('/install.php?step=deployment-check', []);
        $missingTokenResponse->assertStatus(403)->assertJson([
            'session' => false,
            'csrf' => false,
            'ready' => false,
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_native_installer_session_falls_back_to_private_storage_and_persists_csrf(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/upi-installer-session-'.bin2hex(random_bytes(6));
        $sessionsDirectory = $temporaryRoot.'/storage/framework/sessions';
        mkdir($sessionsDirectory, 0700, true);
        $unavailableSavePath = $temporaryRoot.'/missing-php-session-directory';
        ini_set('session.save_path', $unavailableSavePath);

        try {
            $this->assertTrue(NativeInstallerSession::start($temporaryRoot, '/checkout', true));
            $this->assertSame(realpath($sessionsDirectory), session_save_path());
            $this->assertSame('upi_setup_'.substr(hash('sha256', $temporaryRoot.'/checkout'), 0, 16), session_name());
            $this->assertSame('/checkout/', session_get_cookie_params()['path']);
            $this->assertTrue(session_get_cookie_params()['secure']);
            $this->assertTrue(session_get_cookie_params()['httponly']);
            $this->assertSame('Lax', session_get_cookie_params()['samesite']);
            $this->assertSame('', session_get_cookie_params()['domain']);

            $csrf = bin2hex(random_bytes(32));
            $_SESSION['_installer_csrf'] = $csrf;
            $sessionId = session_id();
            session_write_close();

            session_id($sessionId);
            $this->assertTrue(NativeInstallerSession::start($temporaryRoot, '/checkout', true));
            $this->assertSame($csrf, $_SESSION['_installer_csrf']);
            session_write_close();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            session_id('');
            $files = glob($sessionsDirectory.'/*') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($sessionsDirectory);
            @rmdir(dirname($sessionsDirectory));
            @rmdir(dirname(dirname($sessionsDirectory)));
            @rmdir($temporaryRoot);
        }
    }

    public function test_database_step_blocked_when_server_readiness_or_requirements_fail(): void
    {
        // 1. Laravel flow blocks database route when server readiness fails
        $mockServer = ['SERVER_SOFTWARE' => 'nginx', 'DOCUMENT_ROOT' => base_path(), 'PHP_SAPI' => 'fpm-fcgi'];
        $response = $this->call('GET', '/install/database', [], [], [], $mockServer);
        $response->assertRedirect(route('install.index'));

        // 2. Native install.php flow blocks database route
        $nativeResponse = $this->call('GET', '/install.php?step=database', [], [], [], $mockServer);
        $nativeResponse->assertRedirect('/install.php?step=requirements');
    }

    public function test_native_and_laravel_flows_provide_identical_requirement_structure(): void
    {
        $installer = app(InstallationManager::class);
        $laravelOverview = $installer->getRequirementsOverview([], $_SERVER);

        $this->get('/install.php')->assertOk();
        $csrf = $_SESSION['_installer_csrf'] ?? '';
        $nativeResponse = $this->post('/install.php?step=deployment-check', [
            '_token' => $csrf,
            'recheck' => '1',
        ]);
        $nativeResponse->assertOk();
        $nativeData = $nativeResponse->json();

        // Count of requirements and groups must match exactly
        $this->assertCount(count($laravelOverview['requirements']), $nativeData['requirements']);
        $this->assertSame(array_keys($laravelOverview['groups']), array_keys($nativeData['groups']));
        $this->assertSame($laravelOverview['summary']['total'], $nativeData['summary']['total']);
    }

    public function test_html_escaping_of_detected_values_and_descriptions(): void
    {
        $installer = app(InstallationManager::class);
        $overview = $installer->getRequirementsOverview([
            'cURL Extension' => false,
        ]);

        // Inject malicious strings into requirement fields
        $overview['requirements'][0]['name'] = '<script>alert("xss")</script>';
        $overview['requirements'][0]['current_value'] = '<b>dangerous</b>';
        $overview['groups']['php']['checks'][0]['name'] = '<script>alert("xss")</script>';
        $overview['groups']['php']['checks'][0]['current_value'] = '<b>dangerous</b>';
        $overview['groups']['php']['checks'][0]['remediation'] = '<img src=x onerror=alert(1)>';

        $rendered = view('install.requirements-overview', [
            'overview' => $overview,
            'summary' => $overview['summary'],
            'groups' => $overview['groups'],
            'requirements' => $overview['requirements'],
            'allPassed' => false,
            'nativeInstaller' => false,
            'csrfToken' => 'test-token',
        ])->render();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $rendered);
        $this->assertStringNotContainsString('<b>dangerous</b>', $rendered);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $rendered);
        $this->assertStringContainsString('&lt;b&gt;dangerous&lt;/b&gt;', $rendered);
    }

    public function test_litespeed_sapi_server_readiness_and_progression_allowed(): void
    {
        $installer = app(InstallationManager::class);
        $mockServer = [
            'SERVER_SOFTWARE' => 'LiteSpeed',
            'PHP_SAPI' => 'litespeed',
            'DOCUMENT_ROOT' => public_path(),
            'HTTP_HOST' => 'pay.example.com',
            'HTTPS' => 'on',
        ];

        $readiness = $installer->verifyServerReadiness($mockServer);
        $this->assertTrue($readiness['ready'], 'Readiness should pass under LiteSpeed when public root matches');
        $this->assertTrue($readiness['handler']['supported']);

        $overview = $installer->getRequirementsOverview([], $mockServer);
        $this->assertTrue($overview['allPassed']);
        $this->assertSame(0, $overview['summary']['failed']);

        // Verify progression to database step is allowed in Laravel flow
        $response = $this->call('GET', '/install/database', [], [], [], $mockServer);
        $response->assertOk();
        $response->assertViewIs('install.database');

        // Verify progression in native install.php flow
        $nativeResponse = $this->call('GET', '/install.php?step=database', [], [], [], $mockServer);
        $nativeResponse->assertOk();
        $this->assertStringContainsString('Database Configuration', $nativeResponse->getContent());
    }

    public function test_deployment_check_endpoint_supports_uninstalled_and_litespeed_proxy_https(): void
    {
        $mockServer = [
            'SERVER_SOFTWARE' => 'LiteSpeed',
            'PHP_SAPI' => 'litespeed',
            'DOCUMENT_ROOT' => public_path(),
            'HTTP_HOST' => 'pay.example.com',
            'HTTPS' => 'on',
            'REQUEST_URI' => '/__upi_deployment_check',
            'SCRIPT_NAME' => '/index.php',
        ];

        $response = $this->call('GET', 'https://pay.example.com/__upi_deployment_check', [], [], [], $mockServer);
        $response->assertOk();
        $data = $response->json();

        $this->assertSame('upi-gateway', $data['application']);
        $this->assertTrue($data['routing']);
        $this->assertFalse($data['installed']);
        $this->assertTrue($data['secure'], 'LiteSpeed HTTPS indicator should report secure = true');
        $this->assertTrue($data['php_handler']['supported']);
        $this->assertSame('LiteSpeed SAPI (LSAPI)', $data['php_handler']['name']);
        $this->assertSame('', $data['base_path']);
    }

    public function test_hosting_check_script_guards_application_urls_with_installed_flag(): void
    {
        $rendered = view('install.hosting-check', [
            'deploymentUrl' => 'https://cpanel-user-domain.example',
            'serverReady' => true,
            'nativeInstaller' => false,
            'finalCheck' => false,
        ])->render();

        $this->assertStringContainsString('if (!native && routing.installed)', $rendered);
        $this->assertStringContainsString('routing.installed && routing.base_path !== prefix', $rendered);
        $this->assertStringContainsString('routing.runtime !== \'laravel\'', $rendered);
    }

    public function test_regression_same_origin_probes_derive_from_window_location_origin_on_127_0_0_1_8004(): void
    {
        $installer = app(InstallationManager::class);
        $mockServer = [
            'HTTP_HOST' => '127.0.0.1:8004',
            'SERVER_PORT' => '8004',
            'SERVER_NAME' => '127.0.0.1',
            'REQUEST_URI' => '/install',
        ];

        $appUrl = $installer->detectApplicationUrl(null, $mockServer);
        $this->assertSame('http://127.0.0.1:8004', $appUrl);

        $rendered = view('install.hosting-check', [
            'deploymentUrl' => $appUrl,
            'serverReady' => true,
            'nativeInstaller' => false,
            'finalCheck' => false,
        ])->render();

        $this->assertStringContainsString('const pageUrl = new URL(window.location.href);', $rendered);
        $this->assertStringContainsString('const base = pageUrl.origin + basePath;', $rendered);
        $this->assertStringContainsString('new URL(cleanBase + \'/\' + cleanPath, window.location.origin)', $rendered);
        $this->assertStringNotContainsString('http://localhost', $rendered);
    }

    public function test_regression_same_origin_probes_preserve_custom_port_on_localhost_9090(): void
    {
        $installer = app(InstallationManager::class);
        $mockServer = [
            'HTTP_HOST' => 'localhost:9090',
            'SERVER_PORT' => '9090',
            'SERVER_NAME' => 'localhost',
            'REQUEST_URI' => '/install',
        ];

        $appUrl = $installer->detectApplicationUrl(null, $mockServer);
        $this->assertSame('http://localhost:9090', $appUrl);

        $rendered = view('install.hosting-check', [
            'deploymentUrl' => $appUrl,
            'serverReady' => true,
            'nativeInstaller' => false,
            'finalCheck' => false,
        ])->render();

        $this->assertStringContainsString('const base = pageUrl.origin + basePath;', $rendered);
        $this->assertStringContainsString('credentials: \'same-origin\'', $rendered);
    }

    public function test_regression_cpanel_https_domain_preserves_https_origin_without_mixed_content(): void
    {
        $installer = app(InstallationManager::class);
        $mockServer = [
            'HTTP_HOST' => 'payments.merchant.example.com',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'SERVER_NAME' => 'payments.merchant.example.com',
            'SERVER_SOFTWARE' => 'LiteSpeed',
            'PHP_SAPI' => 'litespeed',
            'DOCUMENT_ROOT' => public_path(),
        ];

        $appUrl = $installer->detectApplicationUrl(null, $mockServer);
        $this->assertSame('https://payments.merchant.example.com', $appUrl);

        $rendered = view('install.hosting-check', [
            'deploymentUrl' => $appUrl,
            'serverReady' => true,
            'nativeInstaller' => true,
            'finalCheck' => false,
        ])->render();

        $this->assertStringContainsString('pageUrl.origin + basePath', $rendered);
        $this->assertStringContainsString('https:', $rendered);
    }

    public function test_regression_subdirectory_installation_preserves_path_prefix_and_cookie_path(): void
    {
        $installer = app(InstallationManager::class);
        $mockServer = [
            'HTTP_HOST' => 'merchant.example.com',
            'SERVER_NAME' => 'merchant.example.com',
            'SCRIPT_NAME' => '/payment-subfolder/public/index.php',
            'REQUEST_URI' => '/payment-subfolder/install.php',
            'DOCUMENT_ROOT' => dirname(public_path()),
        ];

        $appUrl = $installer->detectApplicationUrl(null, $mockServer);
        $this->assertSame('http://merchant.example.com/payment-subfolder', $appUrl);

        $rendered = view('install.hosting-check', [
            'deploymentUrl' => $appUrl,
            'serverReady' => true,
            'nativeInstaller' => true,
            'finalCheck' => false,
        ])->render();

        $this->assertStringContainsString('basePath = configuredBase ? new URL(configuredBase, pageUrl.origin).pathname.replace(/\/$/, \'\') : \'\';', $rendered);
        $this->assertStringContainsString('normalizeCookiePath', $rendered);
    }

    public function test_regression_native_install_php_mode_executes_deployment_check(): void
    {
        $this->get('/install.php?step=requirements')->assertOk();
        $csrf = $_SESSION['_installer_csrf'] ?? '';
        $this->assertNotEmpty($csrf);

        $response = $this->post('/install.php?step=deployment-check', [
            '_token' => $csrf,
            'recheck' => '1',
        ]);
        $response->assertOk();
        $data = $response->json();

        $this->assertTrue($data['session']);
        $this->assertTrue($data['csrf']);
        $this->assertTrue($data['ready']);
        $this->assertCount(29, $data['requirements']);
        $this->assertSame(29, $data['summary']['total']);
        $this->assertSame(0, $data['summary']['failed']);
    }

    public function test_regression_laravel_installer_mode_renders_same_origin_and_all_29_checks(): void
    {
        $response = $this->get('/install');
        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('pageUrl.origin + basePath', $content);
        $this->assertStringContainsString('data-stat="total">29<', $content);
        $this->assertStringContainsString('data-stat="failed">0<', $content);
        $this->assertStringContainsString('System Ready for Installation', $content);
    }

    public function test_regression_preservation_of_http_host_and_https_after_laravel_bootstrap(): void
    {
        $installer = app(InstallationManager::class);
        $webServer = [
            'HTTP_HOST' => 'real-cpanel-merchant.example.com:8443',
            'SERVER_NAME' => 'real-cpanel-merchant.example.com',
            'HTTPS' => 'on',
            'SERVER_PORT' => '8443',
            'DOCUMENT_ROOT' => '/home/merchant/public',
            'SCRIPT_NAME' => '/gateway/public/index.php',
            'REQUEST_URI' => '/gateway/install.php?step=requirements',
        ];
        $cliRequestDefaults = [
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'HTTPS' => '',
            'SERVER_PORT' => '80',
            'DOCUMENT_ROOT' => '/project/public',
            'SCRIPT_NAME' => '/install.php',
            'REQUEST_URI' => '/install.php?step=requirements',
        ];
        $serverState = $installer->serverState($webServer, $cliRequestDefaults, 'fpm-fcgi');

        $this->assertSame($webServer, array_intersect_key($serverState, $webServer));
        $detected = $installer->detectApplicationUrl(null, $serverState);
        $this->assertSame('https://real-cpanel-merchant.example.com:8443/gateway', $detected);
    }

    public function test_regression_all_29_checks_passing_renders_next_button_and_unhiding_script(): void
    {
        $installer = app(InstallationManager::class);
        $overview = $installer->getRequirementsOverview();

        $this->assertSame(29, $overview['summary']['total']);
        $this->assertSame(0, $overview['summary']['failed']);
        $this->assertTrue($overview['allPassed']);

        $view = view('install.requirements-overview', [
            'overview' => $overview,
            'summary' => $overview['summary'],
            'groups' => $overview['groups'],
            'requirements' => $overview['requirements'],
            'allPassed' => true,
            'nativeInstaller' => false,
            'nextUrl' => '/install/database',
            'recheckUrl' => '/install/recheck',
            'csrfToken' => 'test-csrf-token',
        ])->render();

        $this->assertStringContainsString('System Ready for Installation', $view);
        $this->assertStringContainsString('data-deployment-continue', $view);
        $this->assertStringContainsString('data-deployment-href="/install/database"', $view);
        $this->assertStringContainsString('Next: Database Setup', $view);

        $checkView = view('install.hosting-check', [
            'deploymentUrl' => 'http://127.0.0.1:8004',
            'serverReady' => true,
            'nativeInstaller' => false,
            'finalCheck' => false,
        ])->render();

        $this->assertStringContainsString('link.hidden = false;', $checkView);
        $this->assertStringContainsString('link.removeAttribute(\'aria-disabled\');', $checkView);
        $this->assertStringContainsString('setStatus(\'ready\');', $checkView);
    }

    public function test_regression_clicking_next_opens_database_configuration(): void
    {
        $installer = app(InstallationManager::class);
        $mockServer = [
            'DOCUMENT_ROOT' => public_path(),
            'PHP_SAPI' => 'fpm-fcgi',
            'HTTP_HOST' => '127.0.0.1:8004',
        ];

        // 1. Direct navigation in Laravel mode
        $laravelResponse = $this->call('GET', '/install/database', [], [], [], $mockServer);
        $laravelResponse->assertOk();
        $laravelResponse->assertViewIs('install.database');
        $this->assertStringContainsString('Connect your database', $laravelResponse->getContent());
        $this->assertStringContainsString('name="db_host"', $laravelResponse->getContent());
        $this->assertStringContainsString('name="db_database"', $laravelResponse->getContent());
        $this->assertStringContainsString('name="db_username"', $laravelResponse->getContent());

        // 2. Direct navigation in native install.php mode
        $nativeResponse = $this->call('GET', '/install.php?step=database', [], [], [], $mockServer);
        $nativeResponse->assertOk();
        $this->assertStringContainsString('Database Configuration', $nativeResponse->getContent());
        $this->assertStringContainsString('name="db_host"', $nativeResponse->getContent());
        $this->assertStringContainsString('name="db_database"', $nativeResponse->getContent());
    }

    public function test_regression_mandatory_requirement_failure_blocks_database_progression(): void
    {
        $mockServer = [
            'DOCUMENT_ROOT' => base_path(), // Violates public directory requirement on Nginx
            'SERVER_SOFTWARE' => 'nginx',
            'PHP_SAPI' => 'fpm-fcgi',
        ];

        // 1. Laravel database route blocks and redirects to index
        $laravelResponse = $this->call('GET', '/install/database', [], [], [], $mockServer);
        $laravelResponse->assertRedirect(route('install.index'));

        // 2. Native install.php database route blocks and redirects to requirements
        $nativeResponse = $this->call('GET', '/install.php?step=database', [], [], [], $mockServer);
        $nativeResponse->assertRedirect('/install.php?step=requirements');
    }
}
