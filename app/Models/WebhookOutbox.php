<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WebhookOutbox extends Model
{
    protected $table = 'webhook_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sequence' => 'integer',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'queued_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event) {
            if ($event->isDirty(['event_id', 'order_id', 'sequence', 'transition_key', 'event', 'environment', 'payload', 'created_at'])) {
                throw new LogicException('Webhook event identity and snapshot are immutable.');
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
