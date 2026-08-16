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
                $publisher = Publisher::withoutGlobalScopes()->findOrFail($application->publisher_id);
                $identities->ensureForPublisher($publisher);
                $identities->markApplicationApproved($application);
            } elseif (in_array($application->status, [PublisherApplicationStatus::Rejected, PublisherApplicationStatus::Withdrawn], true)) {
                $identities->retireApplicationReservations($application, $application->status->value);
                $application->domainClaims()->where('claim_status', 'CLAIMED')->update([
                    'claim_status' => 'RELEASED',
                    'released_at' => now(),
                ]);
            }
        });
    }

    public function publisher(): BelongsTo { return $this->belongsTo(Publisher::class); }
    public function applicant(): BelongsTo { return $this->belongsTo(User::class, 'applicant_user_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function domainClaim(): HasOne
    {
        return $this->hasOne(PublisherApplicationDomainClaim::class)->where('claim_status', 'CLAIMED')->latestOfMany('claimed_at');
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
