<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class VerificationNodeIdentity
{
    public function nodeId(): string
    {
        $configured = trim((string) config('gateway.verification_node_id', ''));
        if (app()->environment('production')) {
            if ($configured === '' || $this->isPlaceholder($configured)) {
                throw new RuntimeException('GATEWAY_VERIFICATION_NODE_ID must be an explicit, unique production node identity.');
            }
        } elseif ($configured === '') {
            $configured = 'local-'.substr(hash('sha256', base_path().'|'.(gethostname() ?: php_uname('n'))), 0, 24);
        }

        if (strlen($configured) > 128) {
            throw new RuntimeException('GATEWAY_VERIFICATION_NODE_ID cannot exceed 128 characters.');
        }

        return $configured;
    }

    public function installationId(): string
    {
        $configured = trim((string) config('gateway.verification_node_installation_id', ''));
        if ($configured !== '') {
            return substr($configured, 0, 128);
        }
        if (app()->environment('testing')) {
            return 'testing-'.substr(hash('sha256', base_path()), 0, 32);
        }

        $path = storage_path('framework/verification-node-installation-id');
        $existing = trim((string) @file_get_contents($path));
        if ($existing !== '') {
            return substr($existing, 0, 128);
        }

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0750, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Unable to create the verification node identity directory.');
        }
        $identity = (string) Str::uuid();
        $handle = @fopen($path, 'x');
        if (is_resource($handle)) {
            try {
                if (fwrite($handle, $identity."\n") === false || ! fflush($handle)) {
                    throw new RuntimeException('Unable to persist the verification node installation identity.');
                }
            } finally {
                fclose($handle);
            }

            return $identity;
        }

        $existing = trim((string) @file_get_contents($path));
        if ($existing === '') {
            throw new RuntimeException('Unable to read the verification node installation identity.');
        }

        return substr($existing, 0, 128);
    }

    public function bootId(): string
    {
        $configured = trim((string) config('gateway.verification_node_boot_id', ''));
        if ($configured !== '') {
            return substr($configured, 0, 128);
        }

        $linux = trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id'));
        if ($linux !== '') {
            return 'linux-'.$linux;
        }

        $bootTime = $this->run(['sysctl', '-n', 'kern.boottime']);
        if ($bootTime !== null) {
            return 'sysctl-'.hash('sha256', $bootTime);
        }

        // The installation identity remains unique. An unknown boot ID is
        // deliberately stable, so uncertainty cannot create a second owner.
        return 'unknown-boot';
    }

    public function instanceId(): string
    {
        return hash('sha256', $this->installationId().'|'.$this->bootId());
    }

    private function isPlaceholder(string $identity): bool
    {
        $normalized = strtolower($identity);

        return in_array($normalized, [
            'default', 'localhost', 'node', 'node-1', 'upi-gateway-node-1',
            'change-me', 'change-me-unique-node-id', 'your-node-id',
        ], true) || str_contains($normalized, 'change-me');
    }

    private function run(array $command): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }
        $pipes = [];
        $process = @proc_open($command, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && $stdout !== '' ? $stdout : null;
    }
}
