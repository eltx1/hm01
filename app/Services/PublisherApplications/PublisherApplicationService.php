<?php

namespace App\Services\PublisherApplications;

use App\Enums\AccountStatus;
use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Publisher;
use App\Models\PublisherApplication;
use App\Models\PublisherApplicationDomainClaim;
use App\Models\PublisherApplicationEvent;
use App\Models\PublisherApplicationRevision;
use App\Models\PublisherQualityProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Contracts\DefaultPublisherTermsService;
use App\Services\SupplyChain\DomainNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PublisherApplicationService
{
    private const DECLARATIONS = [
        'original_content', 'user_generated_content', 'ai_assisted_content', 'sensitive_content',
        'has_privacy_policy', 'has_contact_details', 'has_cmp', 'prior_policy_incidents',
    ];

    public function __construct(
        private readonly DomainNormalizer $domains,
        private readonly AuditRecorder $audit,
        private readonly PublisherApplicationNotificationService $notifications,
        private readonly DefaultPublisherTermsService $defaultTerms,
    ) {}

    /** @param array{name: string, email: string, password: string, publisher_name: string, primary_domain?: string|null} $data */
    public function register(array $data): PublisherApplication
    {
        return $this->registerAccount($data, activateImmediately: false);
    }

    /** @param array{name: string, email: string, password: string, publisher_name: string, primary_domain?: string|null} $data */
    public function registerActive(array $data): PublisherApplication
    {
        return $this->registerAccount($data, activateImmediately: true);
    }

    /** @param array{name: string, email: string, password: string, publisher_name: string, primary_domain?: string|null} $data */
    private function registerAccount(array $data, bool $activateImmediately): PublisherApplication
    {
        $email = str($data['email'])->lower()->trim()->value();
        $domain = filled($data['primary_domain'] ?? null)
            ? $this->normalizeDomain((string) $data['primary_domain'])
            : null;
        $this->assertEmailAvailable($email);
        if ($domain !== null) {
            $this->assertDomainAvailable($domain);
        }

        try {
            $application = DB::transaction(function () use ($data, $email, $domain, $activateImmediately): PublisherApplication {
                $organization = Organization::create([
                    'name' => trim($data['publisher_name']),
                    'slug' => $this->uniqueSlug($data['publisher_name']),
                    'type' => OrganizationType::Publisher,
                    'status' => $activateImmediately ? AccountStatus::Active : AccountStatus::Pending,
                    'support_email' => $email,
                ]);
                $publisherValues = [
                    'organization_id' => $organization->id,
                    'legal_name' => trim($data['publisher_name']),
                    'display_name' => trim($data['publisher_name']),
                    'business_domain' => $domain,
                    'billing_email' => $email,
                    'status' => $activateImmediately ? AccountStatus::Active : AccountStatus::Pending,
                ];
                if ($activateImmediately) {
                    $publisherValues += ['onboarding_step' => 7, 'onboarding_submitted_at' => now()];
                }
                $publisher = Publisher::withoutGlobalScopes()->create($publisherValues);
                $user = User::create([
                    'organization_id' => $organization->id,
                    'name' => trim($data['name']),
                    'email' => $email,
                    'password' => $data['password'],
                    'status' => UserStatus::Active,
                    'activated_at' => now(),
                ]);
                $application = PublisherApplication::withoutGlobalScopes()->create([
                    'organization_id' => $organization->id,
                    'publisher_id' => $publisher->id,
                    'applicant_user_id' => $user->id,
                    'primary_domain' => $domain,
                    // Keep one compatibility/audit row for historical tooling. The
                    // public registration path creates it already approved; this
                    // legacy method remains available for historical fixtures/data.
                    'status' => $activateImmediately ? PublisherApplicationStatus::Approved : PublisherApplicationStatus::EmailVerificationRequired,
                    'approved_at' => $activateImmediately ? now() : null,
                ]);
                if ($domain !== null) {
                    PublisherApplicationDomainClaim::create([
                        'publisher_application_id' => $application->id,
                        'normalized_domain' => $domain,
                    ]);
                }
                $applicationStatus = $activateImmediately
                    ? PublisherApplicationStatus::Approved
                    : PublisherApplicationStatus::EmailVerificationRequired;
                if ($activateImmediately) {
                    $role = Role::query()
                        ->whereNull('organization_id')
                        ->where('name', RoleName::PublisherAdmin->value)
                        ->firstOrFail();
                    $user->roles()->syncWithoutDetaching([$role->id => ['assigned_by' => $user->id]]);
                    $this->defaultTerms->ensure($publisher, $user);
                }

                $this->event($application, $activateImmediately ? 'AUTO_ACTIVATED' : 'CREATED', null, $applicationStatus, $user, applicantVisible: true);
                $this->audit->record('publisher_application.created', $organization->id, $user, $application, newValues: [
                    'publisher_id' => $publisher->id,
                    'primary_domain' => $domain,
                    'status' => $applicationStatus->value,
                    'publisher_status' => ($activateImmediately ? AccountStatus::Active : AccountStatus::Pending)->value,
                    'default_contract_status' => $activateImmediately ? 'ACTIVE' : null,
                ]);

                return $application;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => 'An account or active application already uses the supplied details.',
                'primary_domain' => 'This domain cannot be used for a new Publisher application.',
            ]);
        }

        return $application->load(['applicant', 'publisher']);
    }

    public function emailVerified(User $user): ?PublisherApplication
    {
        return DB::transaction(function () use ($user): ?PublisherApplication {
            $application = PublisherApplication::withoutGlobalScopes()
                ->where('applicant_user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $application || $application->status !== PublisherApplicationStatus::EmailVerificationRequired) {
                return $application;
            }

            $this->transition($application, PublisherApplicationStatus::Draft, 'EMAIL_VERIFIED', $user, applicantVisible: true);

            return $application->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function saveDraft(User $user, array $data): PublisherApplication
    {
        return DB::transaction(function () use ($user, $data): PublisherApplication {
            $application = $this->lockForApplicant($user);
            if (! $application->status->applicantMayEdit()) {
                throw ValidationException::withMessages(['application' => 'This application cannot be edited in its current state.']);
            }

            $domain = $application->primary_domain;
            $domainSupplied = array_key_exists('primary_domain', $data) && filled($data['primary_domain']);
            if ($domainSupplied) {
                $domain = $this->normalizeDomain((string) $data['primary_domain']);
                $this->assertDomainAvailable($domain, $application);
                if ($domain !== $application->primary_domain) {
                    try {
                        $application->domainClaim()->firstOrFail()->update(['normalized_domain' => $domain]);
                    } catch (UniqueConstraintViolationException) {
                        throw ValidationException::withMessages(['primary_domain' => 'This domain cannot be used for a new Publisher application.']);
                    }
                }
            }

            $publisher = Publisher::withoutGlobalScopes()->lockForUpdate()->findOrFail($application->publisher_id);
            $organization = Organization::query()->lockForUpdate()->findOrFail($application->organization_id);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->update(['name' => trim($data['contact_name'])]);
            $publisherValues = [
                'legal_name' => trim($data['legal_name']),
                'display_name' => trim($data['publisher_name']),
            ];
            if ($domainSupplied) {
                $publisherValues['business_domain'] = $domain;
            }
            $publisher->update($publisherValues);
            $organization->update(['name' => trim($data['publisher_name'])]);
            if ($domainSupplied) {
                $application->update(['primary_domain' => $domain]);
            }

            $profile = $this->createQualityProfile($publisher, $lockedUser, $data);
            $this->audit->record('publisher_application.draft.saved', $application->organization_id, $lockedUser, $application, newValues: [
                'quality_profile_version' => $profile->version,
                'primary_domain' => $domain,
            ]);

            return $application->refresh()->load(['publisher.qualityProfiles', 'applicant']);
        });
    }

    public function submit(User $user): PublisherApplication
    {
        $application = DB::transaction(function () use ($user): PublisherApplication {
            $application = $this->lockForApplicant($user);
            if (! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['email' => 'Verify your email before submitting the application.']);
            }
            if (! in_array($application->status, [PublisherApplicationStatus::Draft, PublisherApplicationStatus::MoreInfoRequired], true)) {
                throw ValidationException::withMessages(['application' => 'This application cannot be submitted in its current state.']);
            }

            $publisher = Publisher::withoutGlobalScopes()->findOrFail($application->publisher_id);
            $profile = PublisherQualityProfile::query()->where('publisher_id', $publisher->id)->latest('version')->first();
            $this->assertComplete($application, $publisher, $profile);

            $snapshot = $this->snapshot($application, $publisher, $user, $profile);
            $version = $application->current_revision + 1;
            $revision = PublisherApplicationRevision::create([
                'publisher_application_id' => $application->id,
                'publisher_quality_profile_id' => $profile->id,
                'submitted_by' => $user->id,
                'version' => $version,
                'snapshot' => $snapshot,
                'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'submitted_at' => now(),
            ]);
            $isResubmission = $application->status === PublisherApplicationStatus::MoreInfoRequired;
            $application->update([
                'status' => PublisherApplicationStatus::Submitted,
                'current_revision' => $version,
                'submitted_at' => $application->submitted_at ?? now(),
                'last_submitted_at' => now(),
                'review_started_at' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ]);
            $this->event(
                $application,
                $isResubmission ? 'RESUBMITTED' : 'SUBMITTED',
                $isResubmission ? PublisherApplicationStatus::MoreInfoRequired : PublisherApplicationStatus::Draft,
                PublisherApplicationStatus::Submitted,
                $user,
                $revision,
                applicantVisible: true,
            );
            $this->audit->record(
                $isResubmission ? 'publisher_application.resubmitted' : 'publisher_application.submitted',
                $application->organization_id,
                $user,
                $application,
                newValues: ['status' => PublisherApplicationStatus::Submitted->value, 'revision' => $version, 'snapshot_hash' => $revision->snapshot_hash],
            );

            return $application->refresh()->load(['publisher', 'applicant']);
        });

        $this->notifications->submitted($application);

        return $application;
    }

    public function withdraw(User $user): PublisherApplication
    {
        return DB::transaction(function () use ($user): PublisherApplication {
            $application = $this->lockForApplicant($user);
            if (! $application->status->canTransitionTo(PublisherApplicationStatus::Withdrawn)) {
                throw ValidationException::withMessages(['application' => 'This application can no longer be withdrawn.']);
            }
            $this->transition($application, PublisherApplicationStatus::Withdrawn, 'WITHDRAWN', $user, applicantVisible: true, updates: ['withdrawn_at' => now()]);
            $application->domainClaim()->first()?->update([
                'claim_status' => 'RELEASED',
                'released_at' => now(),
            ]);
            $this->audit->record('publisher_application.withdrawn', $application->organization_id, $user, $application, newValues: ['status' => PublisherApplicationStatus::Withdrawn->value]);

            return $application->refresh();
        });
    }

    public function startReview(PublisherApplication $application, User $actor): PublisherApplication
    {
        return DB::transaction(function () use ($application, $actor): PublisherApplication {
            $locked = $this->lock($application);
            if ($locked->status !== PublisherApplicationStatus::Submitted) {
                throw ValidationException::withMessages(['application' => 'Only a submitted application can enter review.']);
            }
            $this->transition($locked, PublisherApplicationStatus::UnderReview, 'REVIEW_STARTED', $actor, applicantVisible: true, updates: [
                'review_started_at' => now(), 'reviewed_at' => now(), 'reviewed_by' => $actor->id,
            ]);
            $this->audit->record('publisher_application.review_started', $locked->organization_id, $actor, $locked, newValues: ['status' => PublisherApplicationStatus::UnderReview->value]);

            return $locked->refresh();
        });
    }

    public function requestMoreInformation(PublisherApplication $application, User $actor, string $reason): PublisherApplication
    {
        $application = DB::transaction(function () use ($application, $actor, $reason): PublisherApplication {
            $locked = $this->lock($application);
            if ($locked->status !== PublisherApplicationStatus::UnderReview) {
                throw ValidationException::withMessages(['application' => 'Start review before requesting more information.']);
            }
            $this->transition($locked, PublisherApplicationStatus::MoreInfoRequired, 'MORE_INFO_REQUESTED', $actor, $reason, true, updates: ['reviewed_at' => now(), 'reviewed_by' => $actor->id]);
            $this->audit->record('publisher_application.information_requested', $locked->organization_id, $actor, $locked, newValues: [
                'status' => PublisherApplicationStatus::MoreInfoRequired->value, 'reason' => $reason,
            ]);

            return $locked->refresh()->load('applicant');
        });
        $this->notifications->informationRequested($application);

        return $application;
    }

    public function approve(PublisherApplication $application, User $actor): PublisherApplication
    {
        $application = DB::transaction(function () use ($application, $actor): PublisherApplication {
            $locked = $this->lock($application);
            if ($locked->status === PublisherApplicationStatus::Approved) {
                return $locked->load(['publisher', 'applicant']);
            }
            if ($locked->status !== PublisherApplicationStatus::UnderReview) {
                throw ValidationException::withMessages(['application' => 'Start review before approving this application.']);
            }
            if (! $locked->applicant()->firstOrFail()->hasVerifiedEmail() || $locked->current_revision < 1) {
                throw ValidationException::withMessages(['application' => 'A verified submitted revision is required before approval.']);
            }

            $publisher = Publisher::withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->publisher_id);
            $organization = Organization::query()->lockForUpdate()->findOrFail($locked->organization_id);
            $applicant = User::query()->lockForUpdate()->findOrFail($locked->applicant_user_id);
            $role = Role::query()->whereNull('organization_id')->where('name', RoleName::PublisherAdmin->value)->firstOrFail();

            $publisher->update(['status' => AccountStatus::Active]);
            $organization->update(['status' => AccountStatus::Active]);
            $applicant->update(['status' => UserStatus::Active, 'activated_at' => $applicant->activated_at ?? now()]);
            $applicant->roles()->syncWithoutDetaching([$role->id => ['assigned_by' => $actor->id]]);
            $this->transition($locked, PublisherApplicationStatus::Approved, 'APPROVED', $actor, applicantVisible: true, updates: [
                'approved_at' => now(), 'reviewed_at' => now(), 'reviewed_by' => $actor->id,
            ]);
            $this->audit->record('publisher_application.approved', $locked->organization_id, $actor, $locked, newValues: [
                'status' => PublisherApplicationStatus::Approved->value,
                'publisher_id' => $publisher->id,
                'role' => RoleName::PublisherAdmin->value,
            ]);

            return $locked->refresh()->load(['publisher', 'applicant']);
        });
        $this->notifications->approved($application);

        return $application;
    }

    public function reject(PublisherApplication $application, User $actor, string $reason): PublisherApplication
    {
        $application = DB::transaction(function () use ($application, $actor, $reason): PublisherApplication {
            $locked = $this->lock($application);
            if ($locked->status !== PublisherApplicationStatus::UnderReview) {
                throw ValidationException::withMessages(['application' => 'Start review before rejecting this application.']);
            }
            $this->transition($locked, PublisherApplicationStatus::Rejected, 'REJECTED', $actor, $reason, true, updates: [
                'rejected_at' => now(), 'reviewed_at' => now(), 'reviewed_by' => $actor->id,
            ]);
            $locked->domainClaim()->first()?->update([
                'claim_status' => 'RELEASED',
                'released_at' => now(),
            ]);
            $this->audit->record('publisher_application.rejected', $locked->organization_id, $actor, $locked, newValues: [
                'status' => PublisherApplicationStatus::Rejected->value, 'reason' => $reason,
            ]);

            return $locked->refresh()->load('applicant');
        });
        $this->notifications->rejected($application);

        return $application;
    }

    public function hasUnapprovedApplication(Publisher $publisher): bool
    {
        $status = $publisher->application()->value('status');

        return $status !== null && $status !== PublisherApplicationStatus::Approved->value;
    }

    private function lock(PublisherApplication $application): PublisherApplication
    {
        return PublisherApplication::withoutGlobalScopes()->lockForUpdate()->findOrFail($application->id);
    }

    private function lockForApplicant(User $user): PublisherApplication
    {
        return PublisherApplication::withoutGlobalScopes()
            ->where('applicant_user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function transition(
        PublisherApplication $application,
        PublisherApplicationStatus $to,
        string $action,
        User $actor,
        ?string $reason = null,
        bool $applicantVisible = false,
        ?PublisherApplicationRevision $revision = null,
        array $updates = [],
    ): void {
        $from = $application->status;
        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages(['application' => 'Invalid Publisher application lifecycle transition.']);
        }
        $application->update(array_merge($updates, ['status' => $to]));
        $this->event($application, $action, $from, $to, $actor, $revision, $reason, $applicantVisible);
    }

    private function event(
        PublisherApplication $application,
        string $action,
        ?PublisherApplicationStatus $from,
        PublisherApplicationStatus $to,
        User $actor,
        ?PublisherApplicationRevision $revision = null,
        ?string $reason = null,
        bool $applicantVisible = false,
    ): PublisherApplicationEvent {
        return PublisherApplicationEvent::create([
            'publisher_application_id' => $application->id,
            'publisher_application_revision_id' => $revision?->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'previous_status' => $from?->value,
            'new_status' => $to->value,
            'reason' => $reason,
            'applicant_visible' => $applicantVisible,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function createQualityProfile(Publisher $publisher, User $user, array $data): PublisherQualityProfile
    {
        $latest = PublisherQualityProfile::query()->where('publisher_id', $publisher->id)->latest('version')->first();
        $traffic = $latest?->traffic_profile ?? [];
        foreach (['monthly_pageviews', 'organic', 'social', 'direct', 'paid', 'other', 'monetization_history'] as $key) {
            $input = $key === 'monthly_pageviews' || $key === 'monetization_history' ? $key : $key.'_percent';
            if (array_key_exists($input, $data)) {
                $traffic[$key] = $data[$input];
            }
        }
        $devices = $latest?->device_mix ?? [];
        foreach (['desktop', 'mobile', 'tablet'] as $key) {
            if (array_key_exists($key.'_percent', $data)) {
                $devices[$key] = $data[$key.'_percent'];
            }
        }
        $declarations = $latest?->declarations ?? [];
        foreach (self::DECLARATIONS as $key) {
            if (array_key_exists($key, $data)) {
                $declarations[$key] = (bool) $data[$key];
            }
        }

        return PublisherQualityProfile::create([
            'publisher_id' => $publisher->id,
            'version' => ((int) ($latest?->version ?? 0)) + 1,
            'content_categories' => $data['content_categories'] ?? $latest?->content_categories ?? [],
            'content_description' => $data['content_description'] ?? $latest?->content_description ?? '',
            'traffic_profile' => $traffic,
            'audience_countries' => $data['audience_countries'] ?? $latest?->audience_countries ?? [],
            'device_mix' => $devices,
            'declarations' => $declarations,
            'review_comments' => $data['application_notes'] ?? $latest?->review_comments,
            'created_by' => $user->id,
        ]);
    }

    private function assertComplete(PublisherApplication $application, Publisher $publisher, ?PublisherQualityProfile $profile): void
    {
        $errors = [];
        $websiteApplication = $application->domainClaims()->exists();
        if (blank($publisher->legal_name) || blank($publisher->display_name) || ($websiteApplication && blank($application->primary_domain))) {
            $errors['application'] = $websiteApplication
                ? 'Complete the company and domain information before submitting.'
                : 'Complete the company information before submitting.';
        }
        if (! $profile || $profile->content_categories === [] || blank($profile->content_description)) {
            $errors['content_description'] = 'Content categories and a content description are required before submitting.';
        }
        if ($websiteApplication && (! $profile || array_sum(array_map('intval', collect($profile->traffic_profile ?? [])->only(['organic', 'social', 'direct', 'paid', 'other'])->all())) !== 100)) {
            $errors['traffic'] = 'Traffic source percentages must total 100.';
        }
        if ($websiteApplication && (! $profile || $profile->audience_countries === [])) {
            $errors['audience_countries'] = 'At least one audience country is required.';
        }
        if ($websiteApplication && (! $profile || array_sum(array_map('intval', collect($profile->device_mix ?? [])->only(['desktop', 'mobile', 'tablet'])->all())) !== 100)) {
            $errors['device_mix'] = 'Device percentages must total 100.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(PublisherApplication $application, Publisher $publisher, User $user, PublisherQualityProfile $profile): array
    {
        return [
            'application_id' => $application->id,
            'contact' => ['name' => $user->name, 'email' => $user->email],
            'publisher' => ['legal_name' => $publisher->legal_name, 'display_name' => $publisher->display_name, 'primary_domain' => $application->primary_domain],
            'quality_profile' => [
                'id' => $profile->id,
                'version' => $profile->version,
                'content_categories' => $profile->content_categories,
                'content_description' => $profile->content_description,
                'traffic_profile' => $profile->traffic_profile,
                'audience_countries' => $profile->audience_countries,
                'device_mix' => $profile->device_mix,
                'declarations' => $profile->declarations,
                'application_notes' => $profile->review_comments,
            ],
        ];
    }

    private function assertEmailAvailable(string $email): void
    {
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An account already exists for this email. Sign in or use password recovery.']);
        }
    }

    private function assertDomainAvailable(string $domain, ?PublisherApplication $current = null): void
    {
        $publisherConflict = Publisher::withoutGlobalScopes()
            ->where('business_domain', $domain)
            ->when($current, fn ($query) => $query->whereKeyNot($current->publisher_id))
            ->where(fn ($query) => $query
                ->whereDoesntHave('application')
                ->orWhereHas('application', fn ($application) => $application->where('status', PublisherApplicationStatus::Approved->value)))
            ->exists();
        $siteConflict = Site::withoutGlobalScopes()->where('primary_domain', $domain)->exists()
            || SiteDomain::withoutGlobalScopes()->where('domain', $domain)->exists();
        $claimConflict = PublisherApplicationDomainClaim::query()
            ->where('normalized_domain', $domain)
            ->when($current, fn ($query) => $query->where('publisher_application_id', '!=', $current->id))
            ->exists();

        if ($publisherConflict || $siteConflict || $claimConflict) {
            throw ValidationException::withMessages(['primary_domain' => 'This domain cannot be used for a new Publisher application. Contact Horus Media if you believe this is an error.']);
        }
    }

    private function normalizeDomain(string $value): string
    {
        try {
            return (string) $this->domains->normalize($value);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['primary_domain' => $exception->getMessage()]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'publisher';
        do {
            $slug = Str::limit($base, 80, '').'-'.strtolower(Str::random(8));
        } while (Organization::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
