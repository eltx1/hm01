<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PublisherContact extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'publisher_id', 'name', 'email', 'phone', 'title', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
