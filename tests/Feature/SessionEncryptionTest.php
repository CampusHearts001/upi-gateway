<?php

namespace Tests\Feature;

use App\Support\InstallationManager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_session_payload_is_encrypted_when_enabled(): void
    {
        config([
            'session.driver' => 'database',
            'session.encrypt' => true,
        ]);

        $sessionManager = app('session');
        $sessionManager->forgetDrivers();

        $session = $sessionManager->driver('database');
        $this->assertInstanceOf(EncryptedStore::class, $session);

        $sessionId = Str::random(40);
        $session->setId($sessionId);
        $session->start();
        $session->put('sensitive_data', 'secret_credential_value_xyz');
        $session->put('user_id', 42);
        $session->save();

        $record = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertNotNull($record);

        // Raw database string must not expose plaintext
        $this->assertStringNotContainsString('secret_credential_value_xyz', $record->payload);

        // Decoded base64 payload from DatabaseSessionHandler must not expose plaintext
        $handlerPayload = base64_decode($record->payload);
        $this->assertStringNotContainsString('secret_credential_value_xyz', $handlerPayload);

        // Decrypting via the application encrypter recovers the serialized session data
        $decrypted = app('encrypter')->decrypt($handlerPayload);
        $this->assertStringContainsString('secret_credential_value_xyz', $decrypted);
        $this->assertStringContainsString('42', $decrypted);
    }

    public function test_session_attributes_round_trip_correctly_under_encryption(): void
    {
        config([
            'session.driver' => 'database',
            'session.encrypt' => true,
        ]);

        $sessionManager = app('session');
        $sessionManager->forgetDrivers();

        $session = $sessionManager->driver('database');
        $sessionId = Str::random(40);
        $session->setId($sessionId);
        $session->start();

        $testData = [
            'merchant_id' => 101,
            'api_token' => 'tok_live_abc123',
            'is_admin' => false,
            'preferences' => ['theme' => 'dark', 'notifications' => true],
        ];

        foreach ($testData as $key => $value) {
            $session->put($key, $value);
        }
        $session->save();

        // Fresh session manager instance reading the stored session
        $sessionManager->forgetDrivers();
        $readSession = $sessionManager->driver('database');
        $readSession->setId($sessionId);
        $readSession->start();

        $this->assertSame(101, $readSession->get('merchant_id'));
        $this->assertSame('tok_live_abc123', $readSession->get('api_token'));
        $this->assertFalse($readSession->get('is_admin'));
        $this->assertSame(['theme' => 'dark', 'notifications' => true], $readSession->get('preferences'));
    }

    public function test_legacy_plaintext_sessions_fail_gracefully_without_http_500(): void
    {
        // 1. Manually insert a legacy plaintext session as stored by SESSION_ENCRYPT=false
        $sessionId = Str::random(40);
        $plainData = serialize([
            '_token' => Str::random(40),
            'login_web_59ba36addc2b2f9401560f0c40d5b3b4' => 999,
            'legacy_key' => 'legacy_plaintext_value',
        ]);

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => 999,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode($plainData),
            'last_activity' => time(),
        ]);

        // 2. Enable encryption
        config([
            'session.driver' => 'database',
            'session.encrypt' => true,
        ]);

        $sessionManager = app('session');
        $sessionManager->forgetDrivers();

        // Direct store check: EncryptedStore catches DecryptException and returns empty session
        $session = $sessionManager->driver('database');
        $session->setId($sessionId);
        $session->start();

        $this->assertNull($session->get('legacy_key'));
        $this->assertNull($session->get('login_web_59ba36addc2b2f9401560f0c40d5b3b4'));

        // 3. HTTP Request check: request with the legacy session cookie must not throw HTTP 500
        $cookieName = config('session.cookie');

        // Accessing a protected route should redirect to login (302), not crash with 500
        $response = $this->withCookie($cookieName, $sessionId)
            ->get('/dashboard');

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertRedirect('/login');

        // Accessing login route should render 200, not crash with 500
        $loginResponse = $this->withCookie($cookieName, $sessionId)
            ->get('/login');

        $loginResponse->assertOk();
    }

    public function test_app_key_rotation_with_app_previous_keys_preserves_existing_encrypted_sessions(): void
    {
        $cipher = config('app.cipher');
        $oldKeyRaw = Encrypter::generateKey($cipher);
        $oldKeyString = 'base64:'.base64_encode($oldKeyRaw);

        $newKeyRaw = Encrypter::generateKey($cipher);
        $newKeyString = 'base64:'.base64_encode($newKeyRaw);

        // 1. Initial state with oldKey
        config([
            'app.key' => $oldKeyString,
            'app.previous_keys' => [],
            'session.driver' => 'database',
            'session.encrypt' => true,
        ]);
        $encrypterOld = new Encrypter($oldKeyRaw, $cipher);
        $this->app->instance('encrypter', $encrypterOld);

        $sessionManager = app('session');
        $sessionManager->forgetDrivers();

        $sessionId = Str::random(40);
        $session = $sessionManager->driver('database');
        $session->setId($sessionId);
        $session->start();
        $session->put('rotation_test_token', 'token_created_under_old_key');
        $session->save();

        // 2. Rotate to newKey, with oldKey in previous_keys
        config([
            'app.key' => $newKeyString,
            'app.previous_keys' => [$oldKeyString],
        ]);
        $encrypterRotated = new Encrypter($newKeyRaw, $cipher);
        $encrypterRotated->previousKeys([$oldKeyRaw]);
        $this->app->instance('encrypter', $encrypterRotated);

        $sessionManager->forgetDrivers();
        $sessionAfterRotation = $sessionManager->driver('database');
        $sessionAfterRotation->setId($sessionId);
        $sessionAfterRotation->start();

        // Session was successfully decrypted using APP_PREVIOUS_KEYS
        $this->assertSame('token_created_under_old_key', $sessionAfterRotation->get('rotation_test_token'));

        // Save session (which re-encrypts with new primary key)
        $sessionAfterRotation->save();

        // 3. Verify session in DB can now be decrypted using ONLY newKey (without previous keys)
        $strictNewEncrypter = new Encrypter($newKeyRaw, $cipher);
        $record = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertNotNull($record);

        $decryptedWithNewKey = $strictNewEncrypter->decrypt(base64_decode($record->payload));
        $this->assertStringContainsString('token_created_under_old_key', $decryptedWithNewKey);
    }

    public function test_session_encrypt_false_retains_backward_compatibility(): void
    {
        config([
            'session.driver' => 'database',
            'session.encrypt' => false,
        ]);

        $sessionManager = app('session');
        $sessionManager->forgetDrivers();

        $session = $sessionManager->driver('database');
        $this->assertInstanceOf(Store::class, $session);
        $this->assertNotInstanceOf(EncryptedStore::class, $session);

        $sessionId = Str::random(40);
        $session->setId($sessionId);
        $session->start();
        $session->put('plain_key', 'plain_value_98765');
        $session->save();

        $record = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertNotNull($record);

        // When encrypt=false, payload is base64(serialize($data))
        $unserialized = unserialize(base64_decode($record->payload));
        $this->assertIsArray($unserialized);
        $this->assertSame('plain_value_98765', $unserialized['plain_key']);

        // Attributes round-trip correctly
        $sessionManager->forgetDrivers();
        $readSession = $sessionManager->driver('database');
        $readSession->setId($sessionId);
        $readSession->start();
        $this->assertSame('plain_value_98765', $readSession->get('plain_key'));
    }

    public function test_installation_manager_preserves_session_encrypt_setting(): void
    {
        $installer = app(InstallationManager::class);

        // Case A: Fresh installation starting from .env.example template
        $tempEnvA = tempnam(sys_get_temp_dir(), 'env_test_a_');
        @unlink($tempEnvA);
        config(['installer.env_path' => $tempEnvA]);

        try {
            $installer->writeEnvironment([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'production_gateway',
                'db_username' => 'gateway_user',
                'db_password' => 'secret_db_pass',
                'app_name' => 'Acme Gateway',
                'app_url' => 'https://payments.example.com',
            ]);

            $contentA = file_get_contents($tempEnvA);
            $this->assertStringContainsString('SESSION_ENCRYPT=true', $contentA);
        } finally {
            if (file_exists($tempEnvA)) {
                @unlink($tempEnvA);
            }
        }

        // Case B: Updating existing .env that has SESSION_ENCRYPT=false
        $tempEnvB = tempnam(sys_get_temp_dir(), 'env_test_b_');
        file_put_contents($tempEnvB, "APP_NAME=Old\nSESSION_ENCRYPT=false\n");
        config(['installer.env_path' => $tempEnvB]);

        try {
            $installer->writeEnvironment([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'production_gateway',
                'db_username' => 'gateway_user',
                'db_password' => 'secret_db_pass',
                'app_name' => 'Acme Gateway',
                'app_url' => 'https://payments.example.com',
            ]);

            $contentB = file_get_contents($tempEnvB);
            $this->assertStringContainsString('SESSION_ENCRYPT=false', $contentB);
            $this->assertStringNotContainsString('SESSION_ENCRYPT=true', $contentB);
        } finally {
            if (file_exists($tempEnvB)) {
                @unlink($tempEnvB);
            }
        }
    }

    public function test_config_session_encrypt_configuration(): void
    {
        $sessionConfig = require config_path('session.php');
        $this->assertArrayHasKey('encrypt', $sessionConfig);
    }
}
