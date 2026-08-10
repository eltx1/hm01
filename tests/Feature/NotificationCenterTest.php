<?php

namespace Tests\Feature;

use App\Enums\AdsTxtComplianceStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SupportTicketEventType;
use App\Enums\SupportTicketStatus;
use App\Mail\HorusNotificationMail;
use App\Models\HorusNotification;
use App\Models\StaticDeliveryBatch;
use App\Models\SupplyChainCheck;
use App\Models\SupportTicket;
use App\Services\ControlPlane\ActionCenter;
use App\Services\Notifications\DomainNotificationService;
use App\Services\Notifications\HorusNotificationService;
use App\Services\Reporting\PublisherPaymentProfileService;
use App\Services\Support\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $support;

    private $finance;

    private $adOps;

    private $publisherAdmin;

    private $publisherViewer;

    private $otherPublisherAdmin;

    private $publisher;

    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->support = $this->makeUser($horus, RoleName::SupportAgent);
        $this->finance = $this->makeUser($horus, RoleName::FinanceAdmin);
        $this->adOps = $this->makeUser($horus, RoleName::AdOpsAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher One');
        $this->publisherAdmin = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisherViewer = $this->makeUser($publisherOrganization, RoleName::PublisherViewer);
        $this->publisher = $this->makePublisherFor($this->publisherAdmin);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherAdmin);
        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher Two');
        $this->otherPublisherAdmin = $this->makeUser($otherOrganization, RoleName::PublisherAdmin);
        $this->makePublisherFor($this->otherPublisherAdmin, ['display_name' => 'Publisher Two']);
    }

    public function test_support_creation_resolves_only_authorized_admin_recipients(): void
    {
        $ticket = $this->ticket();

        $this->assertDatabaseHas('horus_notifications', [
            'recipient_id' => $this->support->id,
            'type' => 'SUPPORT_TICKET_CREATED',
            'related_id' => $ticket->id,
        ]);
        $this->assertDatabaseMissing('horus_notifications', [
            'recipient_id' => $this->finance->id,
            'type' => 'SUPPORT_TICKET_CREATED',
        ]);
        $this->assertDatabaseMissing('horus_notifications', [
            'recipient_id' => $this->publisherAdmin->id,
            'type' => 'SUPPORT_TICKET_CREATED',
        ]);
    }

    public function test_public_support_reply_notifies_customer_without_message_or_internal_note_leakage(): void
    {
        $ticket = $this->ticket();
        $private = 'INTERNAL BANK TRACE AND PRIVATE ROOT CAUSE';
        app(SupportTicketService::class)->internalNote($this->support, $ticket, $private);
        app(SupportTicketService::class)->reply($this->support, $ticket, 'Public answer with safe customer guidance.');

        $notification = HorusNotification::query()->where('recipient_id', $this->publisherAdmin->id)
            ->where('type', 'SUPPORT_HORUS_REPLY')->firstOrFail();
        $serialized = $notification->title.' '.$notification->message.' '.json_encode($notification->action_parameters);
        $this->assertStringNotContainsString($private, $serialized);
        $this->assertStringNotContainsString('Public answer with safe customer guidance.', $serialized);
        $this->assertDatabaseMissing('horus_notifications', ['recipient_id' => $this->otherPublisherAdmin->id, 'related_id' => $ticket->id]);
    }

    public function test_notification_center_enforces_recipient_isolation_and_read_state(): void
    {
        $notification = $this->notify($this->publisherAdmin, 'isolation-event');

        $this->actingAs($this->otherPublisherAdmin)->get(route('notifications.index'))
            ->assertOk()->assertDontSee($notification->title);
        $this->patch(route('notifications.read', $notification))->assertNotFound();

        $this->actingAs($this->publisherAdmin)->patch(route('notifications.read', $notification))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->patch(route('notifications.unread', $notification))->assertRedirect();
        $this->assertNull($notification->fresh()->read_at);
        $this->get(route('notifications.read', $notification))->assertMethodNotAllowed();
    }

    public function test_mark_all_read_changes_only_current_recipient_rows(): void
    {
        $own = $this->notify($this->publisherAdmin, 'own-event');
        $other = $this->notify($this->otherPublisherAdmin, 'other-event');

        $this->actingAs($this->publisherAdmin)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertNotNull($own->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_stable_event_key_deduplicates_repeated_generation(): void
    {
        $service = app(HorusNotificationService::class);
        $payload = $this->payload('stable-state');
        $this->assertSame(1, $service->notify([$this->publisherAdmin], $payload));
        $this->assertSame(0, $service->notify([$this->publisherAdmin], $payload));
        $this->assertDatabaseCount('horus_notifications', 1);
    }

    public function test_compliance_transition_creates_alert_once_and_recovery_once(): void
    {
        $domain = app(DomainNotificationService::class);
        $bad = $this->check(AdsTxtComplianceStatus::Missing, 'bad-state');
        $domain->adsTxtChanged($this->site, $bad, AdsTxtComplianceStatus::Compliant->value);
        $domain->adsTxtChanged($this->site, $bad, AdsTxtComplianceStatus::Missing->value);
        $good = $this->check(AdsTxtComplianceStatus::Compliant, 'good-state');
        $domain->adsTxtChanged($this->site, $good, AdsTxtComplianceStatus::Missing->value);

        $this->assertSame(1, HorusNotification::query()->where('recipient_id', $this->publisherAdmin->id)->where('type', 'ADS_TXT_NON_COMPLIANT')->count());
        $this->assertSame(1, HorusNotification::query()->where('recipient_id', $this->publisherAdmin->id)->where('type', 'ADS_TXT_RECOVERED')->count());
        $this->assertDatabaseHas('horus_notifications', ['recipient_id' => $this->support->id, 'type' => 'ADS_TXT_NON_COMPLIANT']);
        $this->assertDatabaseMissing('horus_notifications', ['recipient_id' => $this->otherPublisherAdmin->id, 'type' => 'ADS_TXT_NON_COMPLIANT']);
    }

    public function test_finance_profile_change_targets_finance_and_publisher_without_sensitive_values(): void
    {
        $secretAccount = 'GB82 WEST 1234 5698 7654 32';
        app(PublisherPaymentProfileService::class)->save($this->publisher, [
            'beneficiary_name' => 'Publisher Beneficiary', 'payment_method' => 'BANK_TRANSFER',
            'currency' => 'USD', 'country' => 'US', 'billing_address' => 'Safe address',
            'account_reference' => $secretAccount, 'routing_reference' => '021000021',
            'tax_identifier' => 'PRIVATE-TAX-123',
        ], $this->publisherAdmin);

        $this->assertDatabaseHas('horus_notifications', ['recipient_id' => $this->finance->id, 'type' => 'PAYMENT_PROFILE_REVIEW']);
        $this->assertDatabaseHas('horus_notifications', ['recipient_id' => $this->publisherAdmin->id, 'type' => 'PAYMENT_PROFILE_STATUS']);
        $payloads = HorusNotification::query()->get()->map(fn ($item) => $item->title.' '.$item->message.' '.json_encode($item->action_parameters))->join(' ');
        $this->assertStringNotContainsString($secretAccount, $payloads);
        $this->assertStringNotContainsString('PRIVATE-TAX-123', $payloads);
        $this->assertDatabaseMissing('horus_notifications', ['recipient_id' => $this->adOps->id, 'type' => 'PAYMENT_PROFILE_REVIEW']);
    }

    public function test_operational_failure_targets_operations_permission_without_raw_error_payload(): void
    {
        $secret = 'provider credential secret should remain private';
        StaticDeliveryBatch::query()->create([
            'status' => 'FAILED', 'priority' => 'URGENT', 'driver' => 'local',
            'attempts' => 3, 'error_code' => 'UPLOAD_FAILED', 'error_message' => $secret,
        ]);

        $notification = HorusNotification::query()->where('recipient_id', $this->adOps->id)
            ->where('type', 'OPERATION_FAILURE')->firstOrFail();
        $this->assertStringNotContainsString($secret, $notification->title.' '.$notification->message);
        $this->assertDatabaseMissing('horus_notifications', ['recipient_id' => $this->support->id, 'type' => 'OPERATION_FAILURE']);
    }

    public function test_sla_warning_and_breach_scheduler_are_idempotent(): void
    {
        Carbon::setTestNow('2026-08-10 10:00:00');
        $ticket = $this->ticket();
        $ticket->update(['first_response_due_at' => now()->addMinutes(20), 'resolution_due_at' => now()->addHours(4)]);

        $this->artisan('support:sla-monitor')->assertSuccessful();
        $this->artisan('support:sla-monitor')->assertSuccessful();
        $this->assertSame(1, HorusNotification::query()->where('recipient_id', $this->support->id)->where('type', 'SUPPORT_SLA_APPROACHING')->count());

        Carbon::setTestNow('2026-08-10 10:21:00');
        $this->artisan('support:sla-monitor')->assertSuccessful();
        $this->artisan('support:sla-monitor')->assertSuccessful();
        $this->assertSame(1, HorusNotification::query()->where('recipient_id', $this->support->id)->where('type', 'SUPPORT_SLA_BREACHED')->count());
        $this->assertSame(1, $ticket->events()->where('event', SupportTicketEventType::SlaWarning->value)->count());
        $this->assertSame(1, $ticket->events()->where('event', SupportTicketEventType::SlaBreached->value)->count());
        Carbon::setTestNow();
    }

    public function test_preferences_control_email_selection_and_scheduled_delivery(): void
    {
        Mail::fake();
        $service = app(HorusNotificationService::class);
        $service->savePreference($this->publisherAdmin, NotificationCategory::Support, true, true);
        $notification = $this->notify($this->publisherAdmin, 'email-selected', NotificationCategory::Support);
        $this->assertTrue($notification->fresh()->email_requested);

        $this->artisan('notifications:deliver-email')->assertSuccessful();
        Mail::assertSent(HorusNotificationMail::class, fn ($mail): bool => $mail->hasTo($this->publisherAdmin->email));
        $this->assertNotNull($notification->fresh()->emailed_at);

        $service->savePreference($this->publisherAdmin, NotificationCategory::Finance, true, false);
        $noEmail = $this->notify($this->publisherAdmin, 'email-disabled', NotificationCategory::Finance);
        $this->assertFalse($noEmail->fresh()->email_requested);

        $service->savePreference($this->publisherAdmin, NotificationCategory::Compliance, false, true);
        $emailOnly = $this->notify($this->publisherAdmin, 'email-only', NotificationCategory::Compliance);
        $this->assertFalse($emailOnly->fresh()->in_app_visible);
        $this->actingAs($this->publisherAdmin)->get(route('notifications.index'))->assertDontSee('email-only');
        $this->patch(route('notifications.read', $emailOnly))->assertNotFound();
    }

    public function test_preferences_are_owned_and_mandatory_account_channel_cannot_be_disabled(): void
    {
        $this->actingAs($this->publisherAdmin)->put(route('notifications.preferences.update'), [
            'preferences' => ['ACCOUNT' => ['in_app' => 0, 'email' => 0]],
        ])->assertRedirect();
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->publisherAdmin->id, 'category' => 'ACCOUNT',
            'in_app_enabled' => true, 'email_enabled' => true,
        ]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $this->otherPublisherAdmin->id]);
    }

    public function test_action_center_tracks_source_state_and_removes_resolved_support_work(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['priority' => 'HIGH', 'status' => SupportTicketStatus::PendingHorus]);
        $items = collect(app(ActionCenter::class)->items($this->support));
        $this->assertSame(1, $items->firstWhere('key', 'support-priority')['count']);

        app(SupportTicketService::class)->transition($this->support, $ticket, SupportTicketStatus::Resolved);
        $this->assertNull(collect(app(ActionCenter::class)->items($this->support))->firstWhere('key', 'support-priority'));
    }

    public function test_notification_action_does_not_bypass_target_route_authorization(): void
    {
        app(HorusNotificationService::class)->notify([$this->publisherAdmin], array_merge($this->payload('forbidden-target'), [
            'action_route' => 'admin.finance.payouts.index', 'action_parameters' => [],
        ]));
        $notification = HorusNotification::query()->where('recipient_id', $this->publisherAdmin->id)->firstOrFail();

        $this->actingAs($this->publisherAdmin)->get($notification->actionUrl())->assertForbidden();
    }

    public function test_notification_list_is_paginated_and_action_center_query_count_is_bounded(): void
    {
        foreach (range(1, 25) as $index) {
            $this->notify($this->publisherAdmin, 'page-'.$index);
        }
        $this->actingAs($this->publisherAdmin)->get(route('notifications.index'))->assertOk()
            ->assertSee('page-25')->assertDontSee('<strong>page-1</strong>', false);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ActionCenter::class)->items($this->publisherAdmin);
        $this->assertLessThanOrEqual(20, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    private function ticket(): SupportTicket
    {
        return app(SupportTicketService::class)->create($this->publisherAdmin, [
            'subject' => 'Notification lifecycle ticket', 'description' => 'Safe initial customer context.',
            'category' => 'TECHNICAL', 'priority' => 'NORMAL',
        ]);
    }

    private function notify($user, string $event, NotificationCategory $category = NotificationCategory::Support): HorusNotification
    {
        app(HorusNotificationService::class)->notify([$user], $this->payload($event, $category));

        return HorusNotification::query()->where('recipient_id', $user->id)->where('title', $event)->firstOrFail();
    }

    private function payload(string $event, NotificationCategory $category = NotificationCategory::Support): array
    {
        return [
            'category' => $category, 'type' => 'TEST_EVENT', 'severity' => NotificationSeverity::Info,
            'title' => $event, 'message' => 'Safe notification message.', 'event_key' => 'test:'.$event,
            'action_route' => 'notifications.index', 'action_parameters' => [],
        ];
    }

    private function check(AdsTxtComplianceStatus $status, string $hash): SupplyChainCheck
    {
        return SupplyChainCheck::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id,
            'check_type' => 'ADS_TXT', 'status' => $status->value,
            'url' => 'https://'.$this->site->primary_domain.'/ads.txt', 'snapshot_hash' => hash('sha256', $hash),
            'trigger' => 'SCHEDULED', 'checked_at' => now(), 'first_checked_at' => now(), 'occurrence_count' => 1,
        ]);
    }
}
