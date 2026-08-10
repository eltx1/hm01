<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Enums\OrganizationType;
use App\Enums\UserStatus;
use App\Models\HorusNotification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class HorusNotificationService
{
    /** @return Collection<int, User> */
    public function organizationRecipients(string $organizationId, ?string $permission = null): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('status', UserStatus::Active->value)
            ->when($permission, fn ($query, $ability) => $query->whereHas(
                'roles.permissions', fn ($permissions) => $permissions->where('permissions.name', $ability),
            ))
            ->with('notificationPreferences')
            ->get();
    }

    /** @return Collection<int, User> */
    public function horusRecipients(string $permission): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('organization', fn ($query) => $query->where('type', OrganizationType::HorusMedia->value))
            ->whereHas('roles.permissions', fn ($query) => $query->where('permissions.name', $permission))
            ->with('notificationPreferences')
            ->get();
    }

    /**
     * @param  iterable<User>  $recipients
     * @param  array{category: NotificationCategory, type: string, severity: NotificationSeverity, title: string, message: string, event_key: string, related_type?: string|null, related_id?: string|null, action_route?: string|null, action_parameters?: array<string, mixed>}  $data
     */
    public function notify(iterable $recipients, array $data): int
    {
        $this->validatePayload($data);
        $created = 0;

        foreach ($recipients as $recipient) {
            $preference = $this->preference($recipient, $data['category']);
            if (! $data['category']->mandatory() && ! $preference['in_app_enabled'] && ! $preference['email_enabled']) {
                continue;
            }

            $dedupeKey = hash('sha256', $recipient->id.'|'.$data['event_key']);
            try {
                $notification = HorusNotification::query()->firstOrCreate(
                    ['dedupe_key' => $dedupeKey],
                    [
                        'recipient_id' => $recipient->id,
                        'organization_id' => $recipient->organization_id,
                        'category' => $data['category'],
                        'type' => $data['type'],
                        'severity' => $data['severity'],
                        'title' => mb_substr(trim($data['title']), 0, 180),
                        'message' => mb_substr(trim($data['message']), 0, 500),
                        'related_type' => $data['related_type'] ?? null,
                        'related_id' => $data['related_id'] ?? null,
                        'action_route' => $data['action_route'] ?? null,
                        'action_parameters' => $data['action_parameters'] ?? null,
                        'in_app_visible' => $data['category']->mandatory() || $preference['in_app_enabled'],
                        'email_requested' => $data['category']->mandatory() || $preference['email_enabled'],
                    ],
                );
                if ($notification->wasRecentlyCreated) {
                    $created++;
                }
            } catch (UniqueConstraintViolationException) {
                // A concurrent producer already persisted this exact recipient event.
            }
        }

        return $created;
    }

    public function savePreference(User $user, NotificationCategory $category, bool $inApp, bool $email): NotificationPreference
    {
        if ($category->mandatory()) {
            $inApp = true;
            $email = true;
        }

        return NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'category' => $category->value],
            ['in_app_enabled' => $inApp, 'email_enabled' => $email],
        );
    }

    /** @return array{in_app_enabled: bool, email_enabled: bool} */
    public function preference(User $user, NotificationCategory $category): array
    {
        $preference = $user->relationLoaded('notificationPreferences')
            ? $user->notificationPreferences->first(fn (NotificationPreference $item): bool => $item->category === $category)
            : $user->notificationPreferences()->where('category', $category->value)->first();

        return [
            'in_app_enabled' => $category->mandatory() || ($preference?->in_app_enabled ?? true),
            'email_enabled' => $category->mandatory() || ($preference?->email_enabled ?? false),
        ];
    }

    private function validatePayload(array $data): void
    {
        if (! isset($data['category'], $data['severity'], $data['type'], $data['event_key'], $data['title'], $data['message'])) {
            throw new \InvalidArgumentException('A complete notification payload is required.');
        }
        if (($data['action_route'] ?? null) && ! Route::has($data['action_route'])) {
            throw new \InvalidArgumentException('Notification action routes must be registered route names.');
        }
        if (str_contains(strtolower(json_encode($data, JSON_THROW_ON_ERROR)), 'payment_details')) {
            throw new \InvalidArgumentException('Sensitive payment details are not permitted in notifications.');
        }
    }
}
