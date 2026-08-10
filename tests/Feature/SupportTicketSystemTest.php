<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SupportTicketEventType;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Services\Support\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SupportTicketSystemTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_publisher_creates_numbered_ticket_and_can_view_public_thread(): void
    {
        [, $publisherAdmin, , , , $site] = $this->context();

        $response = $this->actingAs($publisherAdmin)->post(route('support.tickets.store'), [
            'subject' => 'Serving stopped on homepage',
            'description' => 'The primary placement is not serving.',
            'category' => 'ADS_SERVING',
            'priority' => 'HIGH',
            'linked_resource' => 'SITE|'.$site->id,
        ]);

        $ticket = SupportTicket::withoutGlobalScopes()->firstOrFail();
        $response->assertRedirect(route('support.tickets.show', $ticket));
        $this->assertMatchesRegularExpression('/^HM-TKT-[0-9A-Z]{12}$/', $ticket->ticket_number);
        $this->assertSame($publisherAdmin->organization_id, $ticket->organization_id);
        $this->assertSame($site->id, $ticket->linked_resource_id);
        $this->assertSame(SupportTicketStatus::Open, $ticket->status);
        $this->assertDatabaseHas('support_ticket_events', ['support_ticket_id' => $ticket->id, 'event' => SupportTicketEventType::Created->value]);

        $this->get(route('support.tickets.show', $ticket))->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee('The primary placement is not serving.')
            ->assertSee('Website: '.$site->display_name);
    }

    public function test_organization_ticket_and_linked_resource_idor_are_rejected(): void
    {
        [, $firstAdmin] = $this->context();
        $secondOrg = $this->makeOrganization(OrganizationType::Publisher, 'Second Publisher');
        $secondAdmin = $this->makeUser($secondOrg, RoleName::PublisherAdmin);
        $secondPublisher = $this->makePublisherFor($secondAdmin, ['display_name' => 'Second Publisher']);
        $secondSite = $this->makeSiteFor($secondPublisher, $secondAdmin, ['display_name' => 'Private Second Site']);
        $secondTicket = $this->ticket($secondAdmin, 'Second organization private ticket');

        $this->actingAs($firstAdmin)->get(route('support.tickets.show', $secondTicket))->assertNotFound();
        $this->post(route('support.tickets.reply', $secondTicket), ['body' => 'Tampered reply'])->assertNotFound();
        $this->post(route('support.tickets.store'), [
            'subject' => 'Attempted cross-organization link',
            'description' => 'This must be rejected safely.',
            'category' => 'TECHNICAL',
            'priority' => 'NORMAL',
            'linked_resource' => 'SITE|'.$secondSite->id,
        ])->assertSessionHasErrors('linked_resource_id');
        $this->assertDatabaseMissing('support_tickets', ['subject' => 'Attempted cross-organization link']);
    }

    public function test_public_replies_drive_controlled_waiting_states_and_first_response(): void
    {
        [$support, $publisherAdmin] = $this->context();
        $ticket = $this->ticket($publisherAdmin);

        $this->actingAs($support)->withSession($this->adminSession())
            ->post(route('admin.support.tickets.reply', $ticket), ['body' => 'Please confirm the affected URL.'])
            ->assertRedirect();
        $ticket->refresh();
        $this->assertSame(SupportTicketStatus::PendingCustomer, $ticket->status);
        $this->assertNotNull($ticket->first_response_at);
        $this->assertNotNull($ticket->sla_paused_at);

        $this->actingAs($publisherAdmin)->post(route('support.tickets.reply', $ticket), ['body' => 'The affected URL is /news.'])
            ->assertRedirect();
        $ticket->refresh();
        $this->assertSame(SupportTicketStatus::PendingHorus, $ticket->status);
        $this->assertNull($ticket->sla_paused_at);
        $this->assertSame(3, $ticket->publicMessages()->count());
    }

    public function test_internal_notes_never_leak_to_customer_html_or_thread_queries(): void
    {
        [$support, $publisherAdmin] = $this->context();
        $ticket = $this->ticket($publisherAdmin);
        $secret = 'PRIVATE HORUS ROOT CAUSE AND COMMERCIAL NOTE';

        $this->actingAs($support)->withSession($this->adminSession())
            ->post(route('admin.support.tickets.note', $ticket), ['body' => $secret])->assertRedirect();
        $this->get(route('admin.support.tickets.show', $ticket))->assertOk()->assertSee($secret);

        $this->actingAs($publisherAdmin)->get(route('support.tickets.show', $ticket))
            ->assertOk()->assertDontSee($secret)->assertDontSee('Internal Horus note');
        $this->assertSame(1, $ticket->publicMessages()->count());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'support.ticket.message']);
    }

    public function test_untrusted_message_content_is_escaped_and_never_executes_as_html(): void
    {
        [, $publisherAdmin] = $this->context();
        $payload = '<script>window.stolen=true</script><img src=x onerror=alert(1)>';
        $ticket = $this->ticket($publisherAdmin, $payload);

        $this->actingAs($publisherAdmin)->get(route('support.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('&lt;script&gt;window.stolen=true&lt;/script&gt;', false)
            ->assertDontSee('<script>window.stolen=true</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_allowed_attachment_is_private_randomized_and_authorized_per_ticket(): void
    {
        Storage::fake('local');
        [, $firstAdmin] = $this->context();
        $ticket = $this->ticket($firstAdmin, 'Attachment ticket', UploadedFile::fake()->create('evidence.pdf', 20, 'application/pdf'));
        $attachment = SupportTicketAttachment::query()->firstOrFail();

        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertStringNotContainsString('evidence.pdf', $attachment->storage_path);
        $download = $this->actingAs($firstAdmin)->get(route('support.attachments.download', [$ticket, $attachment]));
        $download->assertOk();
        $this->assertStringContainsString('no-store', (string) $download->headers->get('cache-control'));

        $otherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Other Org');
        $other = $this->makeUser($otherOrg, RoleName::PublisherAdmin);
        $this->actingAs($other)->get(route('support.attachments.download', [$ticket, $attachment]))->assertNotFound();

        $otherTicket = $this->ticket($other);
        $this->get(route('support.attachments.download', [$otherTicket, $attachment]))->assertNotFound();
    }

    public function test_executable_and_mime_extension_mismatch_uploads_are_rejected(): void
    {
        Storage::fake('local');
        [, $publisherAdmin] = $this->context();

        $this->actingAs($publisherAdmin)->post(route('support.tickets.store'), [
            'subject' => 'Executable attempt', 'description' => 'Must fail safely.',
            'category' => 'TECHNICAL', 'priority' => 'NORMAL',
            'attachment' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo "bad";'),
        ])->assertSessionHasErrors('attachment');
        $this->assertDatabaseMissing('support_tickets', ['subject' => 'Executable attempt']);

        $this->post(route('support.tickets.store'), [
            'subject' => 'MIME mismatch attempt', 'description' => 'Must also fail safely.',
            'category' => 'TECHNICAL', 'priority' => 'NORMAL',
            'attachment' => UploadedFile::fake()->create('fake.pdf', 1, 'text/plain'),
        ])->assertSessionHasErrors('attachment');
    }

    public function test_status_state_machine_close_and_reopen_are_enforced_and_audited(): void
    {
        [$support, $publisherAdmin] = $this->context();
        $ticket = $this->ticket($publisherAdmin);

        $this->actingAs($support)->withSession($this->adminSession())
            ->patch(route('admin.support.tickets.status', $ticket), ['status' => 'CLOSED'])
            ->assertSessionHasErrors('status');
        $this->patch(route('admin.support.tickets.status', $ticket), ['status' => 'RESOLVED'])->assertRedirect();
        $this->assertSame(SupportTicketStatus::Resolved, $ticket->fresh()->status);
        $this->patch(route('admin.support.tickets.status', $ticket), ['status' => 'CLOSED'])->assertRedirect();
        $this->assertSame(SupportTicketStatus::Closed, $ticket->fresh()->status);

        $this->actingAs($publisherAdmin)->patch(route('support.tickets.reopen', $ticket))->assertRedirect();
        $this->assertSame(SupportTicketStatus::PendingHorus, $ticket->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'support.ticket.reopened', 'auditable_id' => $ticket->id]);
        $this->assertDatabaseHas('support_ticket_events', ['support_ticket_id' => $ticket->id, 'event' => SupportTicketEventType::Reopened->value]);
    }

    public function test_support_agent_can_assign_and_manage_but_finance_admin_cannot(): void
    {
        [$support, $publisherAdmin, $finance] = $this->context();
        $secondSupport = $this->makeUser($support->organization, RoleName::SupportAgent);
        $ticket = $this->ticket($publisherAdmin);

        $this->actingAs($finance)->withSession($this->adminSession())
            ->get(route('admin.support.tickets.index'))->assertForbidden();

        $this->actingAs($support)->withSession($this->adminSession())
            ->patch(route('admin.support.tickets.assign', $ticket), ['assigned_to' => $secondSupport->id])->assertRedirect();
        $this->patch(route('admin.support.tickets.priority', $ticket), ['priority' => 'URGENT'])->assertRedirect();
        $ticket->refresh();
        $this->assertSame($secondSupport->id, $ticket->assigned_to);
        $this->assertSame(SupportTicketPriority::Urgent, $ticket->priority);
        $this->assertDatabaseHas('audit_logs', ['event' => 'support.ticket.assigned', 'auditable_id' => $ticket->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'support.ticket.priority_changed', 'auditable_id' => $ticket->id]);
    }

    public function test_publisher_permissions_separate_admin_mutation_from_viewer_access(): void
    {
        [, $publisherAdmin, , , $publisherViewer] = $this->context();
        $ticket = $this->ticket($publisherAdmin);

        $this->actingAs($publisherViewer)->get(route('support.tickets.index'))->assertOk();
        $this->get(route('support.tickets.show', $ticket))->assertOk();
        $this->get(route('support.tickets.create'))->assertForbidden();
        $this->post(route('support.tickets.reply', $ticket), ['body' => 'Viewer tampering'])->assertForbidden();
    }

    public function test_sla_targets_warning_breach_and_customer_pause_are_calculated(): void
    {
        Carbon::setTestNow('2026-08-10 10:00:00');
        [$support, $publisherAdmin] = $this->context();
        $ticket = $this->ticket($publisherAdmin);
        $this->assertSame('ON_TRACK', $ticket->firstResponseSlaStatus()->value);

        Carbon::setTestNow('2026-08-10 17:30:00');
        $this->assertSame('APPROACHING', $ticket->fresh('slaPolicy')->firstResponseSlaStatus()->value);
        Carbon::setTestNow('2026-08-10 18:01:00');
        $this->assertSame('BREACHED', $ticket->fresh('slaPolicy')->firstResponseSlaStatus()->value);

        $this->actingAs($support)->withSession($this->adminSession())
            ->post(route('admin.support.tickets.reply', $ticket), ['body' => 'We need a customer answer.'])->assertRedirect();
        $pausedDue = $ticket->fresh()->resolution_due_at;
        Carbon::setTestNow('2026-08-10 20:01:00');
        $this->actingAs($publisherAdmin)->post(route('support.tickets.reply', $ticket), ['body' => 'Customer response.'])->assertRedirect();
        $this->assertTrue($ticket->fresh()->resolution_due_at->gt($pausedDue));
        Carbon::setTestNow();
    }

    public function test_ticket_lists_are_paginated_without_loading_entire_histories(): void
    {
        [$support, $publisherAdmin] = $this->context();
        for ($index = 1; $index <= 26; $index++) {
            $this->ticket($publisherAdmin, 'Pagination item '.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }

        $this->actingAs($publisherAdmin)->get(route('support.tickets.index'))->assertOk()
            ->assertSee('Pagination item 026')->assertDontSee('Pagination item 001');
        $this->actingAs($support)->withSession($this->adminSession())
            ->get(route('admin.support.tickets.index'))->assertOk()->assertSee('Pagination item 026')->assertDontSee('Pagination item 001');
    }

    public function test_ticket_creation_rate_limit_blocks_support_spam(): void
    {
        [, $publisherAdmin] = $this->context();
        $this->actingAs($publisherAdmin);
        for ($index = 1; $index <= 10; $index++) {
            $this->post(route('support.tickets.store'), [
                'subject' => 'Rate ticket '.$index, 'description' => 'Legitimate request '.$index,
                'category' => 'OTHER', 'priority' => 'LOW',
            ])->assertRedirect();
        }
        $this->post(route('support.tickets.store'), [
            'subject' => 'Rate ticket blocked', 'description' => 'Must be throttled.',
            'category' => 'OTHER', 'priority' => 'LOW',
        ])->assertTooManyRequests();
    }

    private function context(): array
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $support = $this->makeUser($horus, RoleName::SupportAgent);
        $finance = $this->makeUser($horus, RoleName::FinanceAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $publisherAdmin = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisherViewer = $this->makeUser($publisherOrg, RoleName::PublisherViewer);
        $publisher = $this->makePublisherFor($publisherAdmin);
        $site = $this->makeSiteFor($publisher, $publisherAdmin);

        return [$support, $publisherAdmin, $finance, $publisher, $publisherViewer, $site];
    }

    private function ticket($actor, string $description = 'Initial customer description.', ?UploadedFile $attachment = null): SupportTicket
    {
        return app(SupportTicketService::class)->create($actor, [
            'subject' => $description,
            'description' => $description,
            'category' => 'TECHNICAL',
            'priority' => 'NORMAL',
        ], $attachment);
    }

    private function adminSession(): array
    {
        return ['two_factor_passed_at' => now()->timestamp];
    }
}
