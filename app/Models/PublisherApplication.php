<?php

namespace App\Models;

use App\Enums\PublisherApplicationStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\SupplyChain\HorusSellerIdentityService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use LogicException;

class PublisherApplication extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'publisher_id', 'applicant_user_id', 'primary_domain', 'status',
        'current_revision', 'submitted_at', 'last_submitted_at', 'review_started_at',
        'reviewed_at', 'reviewed_by', 'approved_at', 'rejected_at', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublisherApplicationStatus::class,
            'submitted_at' => 'datetime',
            'last_submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $application): void {
            if ($application->isDirty('status')) {
                $from = PublisherApplicationStatus::tryFrom((string) $application->getRawOriginal('status'));
                $rawNext = $application->getAttribute('status');
                $to = $rawNext instanceof PublisherApplicationStatus ? $rawNext : PublisherApplicationStatus::tryFrom((string) $rawNext);
                if (! $from || ! $to || ($from !== $to && ! $from->canTransitionTo($to))) {
                    throw new LogicException('Invalid Publisher application lifecycle transition.');
                }
                if ($from !== $to && $to === PublisherApplicationStatus::Submitted) {
                    // Legacy applications that already reserved a website keep the
                    // original ads.txt gate. Express Publisher applications have no
                    // website claim; websites are added and reviewed independently
                    // after Publisher approval.
                    if (blank($application->primary_domain) && ! $application->domainClaims()->exists()) {
                        return;
                    }
                    $verified = PublisherApplicationDomainClaim::query()
                        ->where('publisher_application_id', $application->id)
                        ->where('normalized_domain', strtolower(rtrim((string) $application->primary_domain, '.')))
                        ->where('claim_status', 'CLAIMED')
                        ->where('verification_status', 'VERIFIED')
                        ->exists();
                    if (! $verified) {
                        throw ValidationException::withMessages([
                            'website_verification' => 'Verify the current website through both required Horus ads.txt seller authorizations before submitting.',
                        ]);
                    }
                }
            }
            if ($application->getRawOriginal('submitted_at') !== null && $application->isDirty('submitted_at')) {
                throw new LogicException('The first Publisher application submission timestamp is immutable.');
            }
        });

        static::updated(function (self $application): void {
            if (! $application->wasChanged('status')) {
                return;
            }

            $identities = app(HorusSellerIdentityService::class);
            if ($application->status === PublisherApplicationStatus::Approved) {
                // Express applications deliberately have no website/domain yet.
                // Their HMP/HMS identities are issued together when the first
                // website is added, so Publisher approval stays independent.
                if (filled($application->primary_domain) || $application->domainClaims()->exists()) {
                    $publisher = Publisher::withoutGlobalScopes()->findOrFail($application->publisher_id);
                    $identities->ensureForPublisher($publisher);
                    $identities->markApplicationApproved($application);
                }
            } elseif (in_array($application->status, [PublisherApplicationStatus::Rejected, PublisherApplicationStatus::Withdrawn], true)) {
                $application->domainClaims()->where('claim_status', 'CLAIMED')->update([
                    'claim_status' => 'RELEASED',
                    'released_at' => now(),
                ]);
                $identities->retireApplicationReservations($application, $application->status->value);
            }
        });
    }

    public function publisher(): BelongsTo { return $this->belongsTo(Publisher::class); }
    public function applicant(): BelongsTo { return $this->belongsTo(User::class, 'applicant_user_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function domainClaim(): HasOne
    {
        return $this->hasOne(PublisherApplicationDomainClaim::class);
    }

    public function domainClaims(): HasMany
    {
        return $this->hasMany(PublisherApplicationDomainClaim::class);
    }

    public function revisions(): HasMany { return $this->hasMany(PublisherApplicationRevision::class); }
    public function events(): HasMany { return $this->hasMany(PublisherApplicationEvent::class); }
    public function legalAcceptances(): HasMany { return $this->hasMany(PublisherApplicationLegalAcceptance::class, 'publisher_application_id'); }
    public function marketingConsents(): HasMany { return $this->hasMany(PublisherApplicationMarketingConsent::class, 'publisher_application_id'); }
}
