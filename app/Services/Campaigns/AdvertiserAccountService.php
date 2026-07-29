<?php

namespace App\Services\Campaigns;

use App\Enums\AccountStatus;
use App\Models\Advertiser;
use App\Models\AdvertiserBillingProfile;
use App\Models\AdvertiserUser;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Validation\ValidationException;

final class AdvertiserAccountService
{
    public function __construct(private readonly AuditRecorder $audit)
    {
    }

    public function linkUser(Advertiser $advertiser, User $user, string $role, bool $primary, User $actor): AdvertiserUser
    {
        if ($user->organization_id !== $advertiser->organization_id) throw ValidationException::withMessages(['user_id' => 'The user must belong to the advertiser organization.']);
        if ($primary) AdvertiserUser::withoutGlobalScopes()->where('advertiser_id', $advertiser->id)->update(['is_primary' => false]);
        $link = AdvertiserUser::withoutGlobalScopes()->updateOrCreate(
            ['advertiser_id' => $advertiser->id, 'user_id' => $user->id],
            ['organization_id' => $advertiser->organization_id, 'role' => strtoupper($role), 'is_primary' => $primary],
        );
        $this->audit->record('advertiser.user.linked', $advertiser->organization_id, $actor, $link, [], $link->toArray());
        return $link;
    }

    public function saveBillingProfile(Advertiser $advertiser, array $data, User $actor): AdvertiserBillingProfile
    {
        if ((bool) ($data['is_default'] ?? true)) AdvertiserBillingProfile::withoutGlobalScopes()->where('advertiser_id', $advertiser->id)->update(['is_default' => false]);
        $profile = AdvertiserBillingProfile::withoutGlobalScopes()->updateOrCreate(
            ['id' => $data['id'] ?? null, 'advertiser_id' => $advertiser->id],
            array_merge($data, ['organization_id' => $advertiser->organization_id, 'advertiser_id' => $advertiser->id]),
        );
        $this->audit->record('advertiser.billing_profile.saved', $advertiser->organization_id, $actor, $profile, [], $profile->only(['legal_name', 'billing_email', 'currency', 'country_code', 'is_default']));
        return $profile;
    }

    public function review(Advertiser $advertiser, bool $approved, User $actor, ?string $notes = null): Advertiser
    {
        $before = $advertiser->status->value;
        $status = $approved ? AccountStatus::Active : AccountStatus::Suspended;
        $advertiser->update(['status' => $status, 'reviewed_at' => now(), 'reviewed_by' => $actor->id, 'review_notes' => $notes]);
        $advertiser->organization->update(['status' => $status]);
        $this->audit->record('advertiser.reviewed', $advertiser->organization_id, $actor, $advertiser, ['status' => $before], ['status' => $status->value], ['approved' => $approved, 'notes' => $notes]);
        return $advertiser->fresh();
    }
}
