<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CronHeartbeat extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'name';
    protected $keyType = 'string';
    protected $fillable = ['name', 'status', 'last_started_at', 'last_completed_at', 'last_failed_at', 'duration_ms', 'message'];
    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_completed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }
}
