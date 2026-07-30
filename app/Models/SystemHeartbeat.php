<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHeartbeat extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $fillable = ['key', 'status', 'last_seen_at', 'metadata'];
    protected function casts(): array { return ['last_seen_at' => 'datetime', 'metadata' => 'array']; }
}
