<?php

namespace Tests\Feature;

use App\Enums\NotificationCategory;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\HorusNotification;
use App\Services\Notifications\HorusNotificationService;
use App\Services\Sites\PublisherSetupReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PublisherSetupReminderTest extends TestCase
{
    use RefreshDatabase, InteractsWithIdentity, InteractsWithPublisherSites;

    public function test_reminders_respect_stage_preferences_cooldown_and_dry_run(): void
    {
        $this->seedIdentity();
        $org = $this->makeOrganization(OrganizationType::Publisher);
        $admin = $this->makeUser($org, RoleName::PublisherAdmin, ['email_verified_at' => now()]);
        $viewer = $this->makeUser($org, RoleName::PublisherViewer, ['email_verified_at' => now()]);
        $optOut = $this->makeUser($org, RoleName::PublisherAdmin, ['email_verified_at' => now()]);
        $publisher = $this->makePublisherFor($admin);
        app(HorusNotificationService::class)->savePreference($admin, NotificationCategory::Sites, true, false);
        app(HorusNotificationService::class)->savePreference($optOut, NotificationCategory::Sites, false, false);
        foreach (['DRAFT', 'PENDING_REVIEW', 'ACTIVE'] as $status) {
            $site = $this->makeSiteFor($publisher, $admin);
            $site->forceFill(['status' => $status, 'updated_at' => now()->subDays(8)])->save(['timestamps' => false]);
            if ($status === 'DRAFT') $draft = $site;
        }
        $service = app(PublisherSetupReminder::class);
        $before = \App\Models\Site::withoutGlobalScopes()->get()->toJson();
        $countBefore = HorusNotification::count();
        $this->assertSame(1, $service->run()['eligible_recipients']);
        $this->assertSame($countBefore, HorusNotification::count());
        $this->assertSame(1, $service->run(true)['created']);
        $notification = HorusNotification::where('type', 'PUBLISHER_SETUP_REMINDER')->sole();
        $this->assertSame($admin->id, $notification->recipient_id);
        $this->assertFalse($notification->email_requested);
        $this->assertSame($draft->id, $notification->related_id);
        $this->assertSame(0, $service->run(true)['created']);
        $this->travel(6)->days();
        $this->assertSame(0, $service->run(true)['created']);
        $this->travel(2)->days();
        $this->assertSame(1, $service->run(true)['created']);
        $this->assertSame($before, \App\Models\Site::withoutGlobalScopes()->get()->toJson());
        $this->assertDatabaseHas('audit_logs', ['event' => 'site.setup.reminder.queued', 'auditable_id' => $draft->id]);
    }

    public function test_recent_deleted_inactive_organization_and_email_unverified_are_excluded(): void
    {
        $this->seedIdentity();
        $org = $this->makeOrganization(OrganizationType::Publisher);
        $admin = $this->makeUser($org, RoleName::PublisherAdmin, ['email_verified_at' => now()]);
        $publisher = $this->makePublisherFor($admin);
        $site = $this->makeSiteFor($publisher, $admin);
        $service = app(PublisherSetupReminder::class);
        $this->assertSame(0, $service->run(true)['created']);
        $site->forceFill(['updated_at' => now()->subDays(8)])->save(['timestamps' => false]);
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->assertSame(0, $service->run(true)['created']);
        $admin->forceFill(['email_verified_at' => now()])->save();
        $org->update(['status' => 'SUSPENDED']);
        $this->assertSame(0, $service->run(true)['created']);
        $org->update(['status' => 'ACTIVE']);
        $site->delete();
        $this->assertSame(0, $service->run(true)['created']);
    }
}
