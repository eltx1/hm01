<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublisherApplicationDomainClaim extends Model
{
    use HasUlids;

    protected $fillable = ['publisher_application_id', 'normalized_domain'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(PublisherApplication::class, 'publisher_application_id');
    }
}
