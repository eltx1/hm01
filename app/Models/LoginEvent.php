<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LoginEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'user_id', 'email', 'successful', 'failure_reason', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['successful' => 'boolean'];
    }
}
