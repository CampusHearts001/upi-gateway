<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    protected $fillable = [
        'order_id',
        'event',
        'event_id',
        'url',
        'payload',
        'response_code',
        'response_body',  // always null (kept in schema for safe migration; never written)
        'success',
        'attempt',
        'error_label',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'success' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
