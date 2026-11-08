<?php

namespace App\Services;

class VerificationLease
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $token,
        public readonly int $generation,
        public readonly string $nodeId,
        public readonly string $nodeInstanceId,
        public readonly int $pid,
        public readonly string $processIdentity,
    ) {}
}
