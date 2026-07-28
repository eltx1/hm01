<?php

namespace App\Services\Identity;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

final class InvitationService
{
    public function __construct(private AuditRecorder $audit) {}

    public function issue(Organization $organization, string $email, ?Role $role, User $inviter): array
    {
        $email = str($email)->lower()->trim()->value();
        if ($role && ! in_array($role->name, $this->allowedRoles($organization->type), true)) {
            throw ValidationException::withMessages(['role_id' => 'This role cannot be assigned within the selected organization type.']);
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'A user already exists for this email.']);
        }

        $token = bin2hex(random_bytes(32));
        $invitation = UserInvitation::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'role_id' => $role?->id,
            'invited_by' => $inviter->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(48),
        ]);

        Notification::route('mail', $email)->notify(new UserInvitationNotification($invitation, $token));
        $this->audit->record('user.invited', $organization->id, $inviter, $invitation, newValues: ['email' => $email, 'role_id' => $role?->id]);

        return [$invitation, $token];
    }

    public function allowedRoles(OrganizationType $type): array
    {
        return match ($type) {
            OrganizationType::HorusMedia => [RoleName::SuperAdmin->value, RoleName::OperationsAdmin->value, RoleName::AdOpsAdmin->value, RoleName::FinanceAdmin->value, RoleName::SupportAgent->value],
            OrganizationType::Publisher => [RoleName::PublisherAdmin->value, RoleName::PublisherViewer->value],
            OrganizationType::Advertiser => [RoleName::AdvertiserAdmin->value, RoleName::AdvertiserViewer->value],
            OrganizationType::Partner => [RoleName::PartnerAdmin->value, RoleName::PartnerViewer->value],
        };
    }

    public function accept(string $token, string $name, string $password): User
    {
        return DB::transaction(function () use ($token, $name, $password): User {
            $invitation = UserInvitation::withoutGlobalScopes()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($invitation->isUsable(), 410, 'This invitation is no longer valid.');

            $user = User::create([
                'organization_id' => $invitation->organization_id,
                'name' => $name,
                'email' => $invitation->email,
                'password' => Hash::make($password),
                'status' => 'ACTIVE',
                'activated_at' => now(),
                'email_verified_at' => now(),
            ]);
            if ($invitation->role_id) {
                $user->roles()->attach($invitation->role_id, ['assigned_by' => $invitation->invited_by]);
            }
            $invitation->update(['accepted_at' => now()]);
            $this->audit->record('user.invitation.accepted', $user->organization_id, $user, $invitation);

            return $user;
        });
    }
}
