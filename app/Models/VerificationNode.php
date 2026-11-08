<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationNode extends Model
{
    protected $primaryKey = 'node_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
