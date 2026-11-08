<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        return view('settings', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request)
    {
        $request->user()->update($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'business_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]));

        return back()->with('status', 'Profile updated.');
    }

    public function updateWebhook(Request $request)
    {
        $data = $request->validate([
            'webhook_url' => ['nullable', 'string', 'max:500'],
        ]);

        $url = $data['webhook_url'] ?? null;

        if ($url !== null && $url !== '') {
            try {
                SafeUrl::validate($url);
            } catch (ValidationException $e) {
                throw $e;
            }
        } else {
            $url = null;
        }

        $request->user()->update(['webhook_url' => $url]);

        return back()->with('status', 'Webhook URL saved.');
    }

    public function updateSandboxWebhook(Request $request)
    {
        $data = $request->validate([
            'sandbox_webhook_url' => ['nullable', 'string', 'max:500'],
        ]);

        $url = ($data['sandbox_webhook_url'] ?? '') ?: null;
        if ($url !== null) {
            SafeUrl::validate($url);

            if ($request->user()->webhook_url
                && hash_equals(rtrim($request->user()->webhook_url, '/'), rtrim($url, '/'))) {
                throw ValidationException::withMessages([
                    'sandbox_webhook_url' => 'Use a separate endpoint for Sandbox webhooks.',
                ]);
            }
        }

        $request->user()->update(['sandbox_webhook_url' => $url]);

        return back()->with('status', 'Sandbox webhook URL saved.');
    }

    public function regenerateSandboxKeys(Request $request)
    {
        $credentials = $request->user()->regenerateSandboxApiCredentials();

        return back()
            ->with('status', 'Sandbox credentials generated. Copy the secret now; it will not be shown again.')
            ->with('sandbox_api_secret_once_encrypted', Crypt::encryptString($credentials['secret']));
    }

    public function revokeSandboxKeys(Request $request)
    {
        $request->user()->revokeSandboxApiCredentials();

        return back()->with('status', 'Sandbox credentials revoked.');
    }

    public function updateRotation(Request $request)
    {
        $request->user()->update($request->validate([
            'rotation_mode' => ['required', Rule::in(array_keys(User::ROTATION_MODES))],
        ]));

        return back()->with('status', 'Account rotation updated.');
    }

    /**
     * Update the list of allowed redirect URL domains.
     * Each entry is a bare hostname (no scheme, no path).
     */
    public function updateRedirectDomains(Request $request)
    {
        $data = $request->validate([
            'redirect_domains' => ['nullable', 'string', 'max:2000'],
        ]);

        // Parse newline/comma-separated list into an array of clean hostnames.
        $raw = $data['redirect_domains'] ?? '';
        $domains = array_values(array_filter(
            array_map(
                fn ($d) => preg_replace('#^https?://#', '', strtolower(trim($d))),
                preg_split('/[\n,]+/', $raw)
            ),
            fn ($d) => $d !== '' && preg_match('/^[a-z0-9.\-]+$/', $d)
        ));

        $request->user()->update(['redirect_domains' => $domains ?: null]);

        return back()->with('status', 'Allowed redirect domains updated.');
    }

    public function regenerateKeys(Request $request)
    {
        $credentials = $request->user()->regenerateApiCredentials();

        return back()
            ->with('status', 'New API credentials generated. Copy the secret now; it will not be shown again.')
            ->with('live_api_secret_once_encrypted', Crypt::encryptString($credentials['secret']));
    }

    public function regenerateWebhookSecret(Request $request)
    {
        $secret = $request->user()->regenerateWebhookSecret();

        return back()
            ->with('status', 'New Live webhook signing secret generated. Copy it now; it will not be shown again.')
            ->with('live_webhook_secret_once_encrypted', Crypt::encryptString($secret));
    }

    public function regenerateSandboxWebhookSecret(Request $request)
    {
        $secret = $request->user()->regenerateSandboxWebhookSecret();

        return back()
            ->with('status', 'New Sandbox webhook signing secret generated. Copy it now; it will not be shown again.')
            ->with('sandbox_webhook_secret_once_encrypted', Crypt::encryptString($secret));
    }
}
