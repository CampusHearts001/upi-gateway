<?php

namespace App\Services;

class VerificationProcessInspector
{
    public const ALIVE = 'alive';

    public const DEAD = 'dead';

    public const UNKNOWN = 'unknown';

    public function __construct(private readonly VerificationNodeIdentity $nodeIdentity) {}

    public function nodeId(): string
    {
        return $this->nodeIdentity->nodeId();
    }

    public function nodeInstanceId(): string
    {
        return $this->nodeIdentity->instanceId();
    }

    public function currentProcessIdentity(): ?string
    {
        $pid = getmypid();

        return $pid ? $this->processIdentity($pid) : null;
    }

    public function status(?string $nodeId, ?string $nodeInstanceId, ?int $pid, ?string $processIdentity): string
    {
        if (! $nodeId || ! hash_equals($this->nodeId(), $nodeId)) {
            return self::UNKNOWN;
        }
        if (! $nodeInstanceId || ! hash_equals($this->nodeInstanceId(), $nodeInstanceId)) {
            return self::UNKNOWN;
        }
        if (! $pid || $pid <= 0 || ! $processIdentity) {
            return self::UNKNOWN;
        }
        if (str_starts_with($processIdentity, 'unknown:')) {
            return $this->pidStatus($pid);
        }

        $currentIdentity = $this->processIdentity($pid);
        if ($currentIdentity !== null) {
            if (! hash_equals($processIdentity, $currentIdentity)) {
                return self::DEAD; // PID reuse: the recorded execution is gone.
            }
            if ($this->isZombie($pid)) {
                return self::DEAD;
            }

            return self::ALIVE;
        }

        return $this->pidStatus($pid);
    }

    private function processIdentity(int $pid): ?string
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (is_string($stat) && ($closing = strrpos($stat, ')')) !== false) {
            $fields = preg_split('/\s+/', trim(substr($stat, $closing + 1)));
            $startTicks = $fields[19] ?? null; // /proc stat field 22.
            if ($startTicks !== null) {
                $bootId = trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id'));

                return 'linux:'.hash('sha256', $bootId.':'.$pid.':'.$startTicks);
            }
        }

        $output = $this->runPs($pid, 'lstart=');

        return $output === null ? null : 'ps:'.hash('sha256', $pid.':'.$output);
    }

    private function isZombie(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (is_string($stat) && ($closing = strrpos($stat, ')')) !== false) {
            return str_starts_with(ltrim(substr($stat, $closing + 1)), 'Z');
        }

        $state = $this->runPs($pid, 'stat=');

        return $state !== null && str_starts_with($state, 'Z');
    }

    private function pidStatus(int $pid): string
    {
        if (! function_exists('posix_kill')) {
            return self::UNKNOWN;
        }
        if (@posix_kill($pid, 0)) {
            return self::UNKNOWN; // Exists, but its start identity is unavailable.
        }

        $error = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;

        return $error === 3 ? self::DEAD : self::UNKNOWN; // ESRCH only; EPERM fails closed.
    }

    private function runPs(int $pid, string $column): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }
        $pipes = [];
        $process = @proc_open(['ps', '-p', (string) $pid, '-o', $column], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return $exit === 0 && $stdout !== '' ? preg_replace('/\s+/', ' ', $stdout) : null;
    }
}
