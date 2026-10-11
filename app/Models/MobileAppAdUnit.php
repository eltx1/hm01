<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MobileAppAdUnit extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $guarded = [];
    protected function casts(): array { return ['is_enabled' => 'boolean']; }
    public function mobileApp() { return $this->belongsTo(MobileApp::class); }
}
