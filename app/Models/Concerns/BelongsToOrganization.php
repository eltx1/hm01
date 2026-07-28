<?php

namespace App\Models\Concerns;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $user = Auth::user();

            if ($user?->organization_id && $user->organization?->type !== OrganizationType::HorusMedia) {
                $builder->where($builder->qualifyColumn('organization_id'), $user->organization_id);
            }
        });

        static::creating(function ($model): void {
            $model->organization_id ??= Auth::user()?->organization_id;
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
