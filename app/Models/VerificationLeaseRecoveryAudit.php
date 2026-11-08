<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class VerificationLeaseRecoveryAudit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['recovered_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verification lease recovery audits are append-only.'));
        static::deleting(fn () => throw new LogicException('Verification lease recovery audits are append-only.'));
    }
}
