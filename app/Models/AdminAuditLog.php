<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AdminAuditLog extends Model
{
    public const UPDATED_AT = null; // single timestamp column

    protected $fillable = [
        'admin_id',
        'action',
        'subject_type',
        'subject_id',
        'meta',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'actor_admin_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            // No FK: retain the original local actor ID after user deletion.
            $log->actor_admin_id = $log->admin_id;
        });
        static::updating(function () {
            throw new LogicException('Administrator audit history is append-only.');
        });
        static::deleting(function () {
            throw new LogicException('Administrator audit history is append-only.');
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
