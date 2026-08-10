<?php

namespace App\Services\Reporting;

use App\Enums\PublisherPaymentProfileStatus;
use App\Models\Publisher;
use App\Models\PublisherPaymentProfile;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PublisherPaymentProfileService
{
    private const PUBLIC_FIELDS = [
        'beneficiary_name', 'payment_method', 'currency', 'country', 'billing_address',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function save(Publisher $publisher, array $attributes, User $actor): PublisherPaymentProfile
    {
        return DB::transaction(function () use ($publisher, $attributes, $actor): PublisherPaymentProfile {
            $profile = PublisherPaymentProfile::withoutGlobalScopes()
                ->where('publisher_id', $publisher->id)
                ->lockForUpdate()
                ->first();
            $before = $profile ? $this->safeSnapshot($profile) : [];
            $existingDetails = (array) ($profile?->payment_details ?? []);
            $existingTaxIdentifier = $profile?->tax_identifier;

            $values = [
                'organization_id' => $publisher->organization_id,
                'beneficiary_name' => trim((string) $attributes['beneficiary_name']),
                'payment_method' => (string) $attributes['payment_method'],
                'currency' => strtoupper((string) $attributes['currency']),
                'country' => strtoupper((string) $attributes['country']),
                'billing_address' => $this->nullableTrim($attributes['billing_address'] ?? null),
            ];

            $details = $existingDetails;
            if ($this->hasReplacement($attributes, 'account_reference')) {
                $details = [
                    'account_reference' => trim((string) $attributes['account_reference']),
                    'routing_reference' => $this->nullableTrim($attributes['routing_reference'] ?? null),
                ];
                $values['payment_details'] = $details;
                $values['account_last_four'] = $this->lastFour((string) $attributes['account_reference']);
            }
            if ($this->hasReplacement($attributes, 'tax_identifier')) {
                $values['tax_identifier'] = trim((string) $attributes['tax_identifier']);
            }

            $taxIdentifier = array_key_exists('tax_identifier', $values)
                ? $values['tax_identifier']
                : $existingTaxIdentifier;
            $materialChanges = $this->materialChanges($profile, $values, $details, $taxIdentifier);
            $complete = $this->isComplete($values, $details);
            $previousStatus = $profile?->verification_status ?? PublisherPaymentProfileStatus::Incomplete;
            $status = $previousStatus;
            if (! $profile || $materialChanges !== []) {
                $status = $profile && $previousStatus === PublisherPaymentProfileStatus::Verified
                    ? PublisherPaymentProfileStatus::NeedsUpdate
                    : ($complete ? PublisherPaymentProfileStatus::PendingVerification : PublisherPaymentProfileStatus::Incomplete);
                $values += [
                    'verification_status' => $status,
                    'is_verified' => false,
                    'verification_requested_at' => $complete ? now() : null,
                    'verified_at' => null,
                    'verified_by' => null,
                    'verification_reason' => null,
                ];
            }

            if ($profile) {
                $profile->update($values);
            } else {
                $profile = PublisherPaymentProfile::withoutGlobalScopes()->create(
                    ['publisher_id' => $publisher->id] + $values
                );
            }
            $profile->refresh();

            $event = $before === []
                ? 'publisher.payment_profile.created'
                : ($materialChanges !== [] ? 'publisher.payment_profile.destination_changed' : 'publisher.payment_profile.updated');
            $this->audit->record($event, $publisher->organization_id, $actor, $profile, $before, $this->safeSnapshot($profile), [
                'changed_fields' => $materialChanges,
                'verification_reset' => $previousStatus === PublisherPaymentProfileStatus::Verified && $materialChanges !== [],
            ]);

            return $profile;
        });
    }

    public function review(
        PublisherPaymentProfile $profile,
        PublisherPaymentProfileStatus $status,
        User $actor,
        ?string $reason = null,
    ): PublisherPaymentProfile {
        if (! $actor->isHorusAdministrator() || ! $actor->hasPermission('finance.payment_profiles.verify')) {
            abort(403);
        }
        if (! in_array($status, [
            PublisherPaymentProfileStatus::Verified,
            PublisherPaymentProfileStatus::Rejected,
            PublisherPaymentProfileStatus::PendingVerification,
        ], true)) {
            throw ValidationException::withMessages(['verification_status' => 'This verification transition is not permitted.']);
        }
        if ($status === PublisherPaymentProfileStatus::Rejected && blank($reason)) {
            throw ValidationException::withMessages(['verification_reason' => 'A safe Publisher-visible reason is required when rejecting a payment profile.']);
        }

        return DB::transaction(function () use ($profile, $status, $actor, $reason): PublisherPaymentProfile {
            $profile = PublisherPaymentProfile::withoutGlobalScopes()->lockForUpdate()->findOrFail($profile->id);
            if ($status === PublisherPaymentProfileStatus::Verified && ! $this->isComplete(
                $profile->only(self::PUBLIC_FIELDS),
                (array) $profile->payment_details,
            )) {
                throw ValidationException::withMessages(['verification_status' => 'An incomplete payment destination cannot be verified.']);
            }

            $before = $this->safeSnapshot($profile);
            $profile->update([
                'verification_status' => $status,
                'is_verified' => $status === PublisherPaymentProfileStatus::Verified,
                'verification_requested_at' => $status === PublisherPaymentProfileStatus::PendingVerification ? now() : $profile->verification_requested_at,
                'verified_at' => $status === PublisherPaymentProfileStatus::Verified ? now() : null,
                'verified_by' => $status === PublisherPaymentProfileStatus::Verified ? $actor->id : null,
                'verification_reason' => $this->nullableTrim($reason),
            ]);

            $this->audit->record('publisher.payment_profile.verification_changed', $profile->organization_id, $actor, $profile, $before, $this->safeSnapshot($profile));

            return $profile->refresh();
        });
    }

    private function materialChanges(?PublisherPaymentProfile $profile, array $values, array $details, ?string $taxIdentifier): array
    {
        if (! $profile) {
            return array_values(array_merge(self::PUBLIC_FIELDS, ['account_reference', 'routing_reference', 'tax_identifier']));
        }

        $changed = [];
        foreach (self::PUBLIC_FIELDS as $field) {
            if ((string) ($profile->{$field} ?? '') !== (string) ($values[$field] ?? '')) {
                $changed[] = $field;
            }
        }
        $existingDetails = (array) $profile->payment_details;
        foreach (['account_reference', 'routing_reference'] as $field) {
            if ((string) ($existingDetails[$field] ?? '') !== (string) ($details[$field] ?? '')) {
                $changed[] = $field;
            }
        }
        if ((string) ($profile->tax_identifier ?? '') !== (string) ($taxIdentifier ?? '')) {
            $changed[] = 'tax_identifier';
        }

        return $changed;
    }

    private function isComplete(array $values, array $details): bool
    {
        foreach (['beneficiary_name', 'payment_method', 'currency', 'country'] as $field) {
            if (blank($values[$field] ?? null)) {
                return false;
            }
        }

        return filled($details['account_reference'] ?? null);
    }

    private function safeSnapshot(PublisherPaymentProfile $profile): array
    {
        return [
            'payment_method' => $profile->payment_method,
            'currency' => $profile->currency,
            'country' => $profile->country,
            'account' => $profile->maskedAccountReference(),
            'has_routing_reference' => filled(data_get($profile->payment_details, 'routing_reference')),
            'has_tax_identifier' => filled($profile->tax_identifier),
            'verification_status' => $profile->verification_status->value,
        ];
    }

    private function hasReplacement(array $attributes, string $key): bool
    {
        return array_key_exists($key, $attributes) && filled($attributes[$key]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function lastFour(string $reference): ?string
    {
        $normalized = preg_replace('/\s+/', '', $reference);

        return $normalized === '' ? null : substr($normalized, -4);
    }
}
