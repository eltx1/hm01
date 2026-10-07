<?php

namespace Tests\Feature;

use App\Enums\{AccountStatus, OrganizationType, RoleName, UserStatus};
use App\Models\{AuditLog, Role};
use App\Services\Audit\AuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class ImpersonationAuditTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private function context(): array
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $target = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Example Publishing'), RoleName::PublisherViewer,
            ['name' => 'Alex Publisher', 'email' => 'alex@example.test']);
        $publisher = $this->makePublisherFor($target, ['display_name' => 'Example Publishing']);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);

        return [$admin, $target, $publisher];
    }

    public function test_authorized_admin_impersonation_start_and_stop_are_audited_and_isolated(): void
    {
        [$admin, $target, $publisher] = $this->context();
        $page = $this->get(route('admin.publishers.show', $publisher))->assertOk()
            ->assertSee('Log in as publisher')->assertSee($target->email);
        $this->fixture('admin-single', $page->getContent());
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp, 'account_recovery_codes' => ['synthetic'], 'tenant_filter' => 'old']);
        $oldSession = session()->getId();
        $oldToken = session()->token();
        $this->post(route('admin.impersonate.start', $target))->assertRedirect('/')
            ->assertSessionHas('impersonator_id', $admin->id)->assertSessionMissing('two_factor_passed_at')
            ->assertSessionMissing('auth.password_confirmed_at')->assertSessionMissing('account_recovery_codes')->assertSessionMissing('tenant_filter');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertAuthenticatedAs($target);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.impersonation.started', 'actor_id' => $admin->id, 'auditable_id' => $target->id]);
        $page = $this->get(route('dashboard'))->assertOk()->assertSee('Temporary publisher login')->assertSee('Return to admin')->assertSee($target->email);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->fixture('publisher', $page->getContent());
        $this->get(route('publisher.reporting.index'))->assertOk()->assertDontSee('Video gross revenue')->assertDontSee('Video Horus margin');
        $audit = app(AuditRecorder::class)->record('test.publisher.inspected', $target->organization_id, $target);
        $this->assertSame($admin->id, $audit->metadata['impersonator_id']);
        $this->get(route('admin.reporting.index'))->assertForbidden();
        $this->post(route('admin.impersonate.start', $admin))->assertForbidden();
        $this->assertAuthenticatedAs($target);
        $this->delete(route('admin.impersonate.stop'))->assertRedirect('/')->assertSessionMissing('impersonator_id');
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.impersonation.stopped', 'actor_id' => $admin->id, 'auditable_id' => $target->id]);
        $this->delete(route('admin.impersonate.stop'))->assertStatus(409);
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(1, AuditLog::where('event', 'admin.impersonation.started')->count());
        $this->assertSame(1, AuditLog::where('event', 'admin.impersonation.stopped')->count());
    }

    public function test_start_denies_ineligible_targets_without_changing_admin_session(): void
    {
        [$admin, $target, $publisher] = $this->context();
        foreach ([['status' => UserStatus::Suspended], ['locked_until' => now()->addHour()], ['email_verified_at' => null]] as $attributes) {
            $original = $target->getAttributes();
            $target->forceFill($attributes)->save();
            $this->post(route('admin.impersonate.start', $target))->assertForbidden()->assertSessionMissing('impersonator_id');
            $this->assertAuthenticatedAs($admin);
            $target->setRawAttributes($original)->save();
        }
        $publisher->update(['status' => AccountStatus::Suspended]);
        $this->post(route('admin.impersonate.start', $target))->assertForbidden();
        $publisher->update(['status' => AccountStatus::Active]);
        $staffRole = Role::where('name', RoleName::FinanceAdmin->value)->firstOrFail();
        $target->roles()->attach($staffRole);
        $this->post(route('admin.impersonate.start', $target))->assertForbidden();
        $this->post(route('admin.impersonate.start', $admin))->assertForbidden();
        $advertiser = $this->makeUser($this->makeOrganization(OrganizationType::Advertiser), RoleName::AdvertiserAdmin);
        $this->post(route('admin.impersonate.start', $advertiser))->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin.impersonation.started']);
    }

    public function test_ui_shows_exact_member_choices_and_hides_ineligible_users(): void
    {
        [$admin, $target, $publisher] = $this->context();
        $second = $this->makeUser($target->organization, RoleName::PublisherAdmin, ['name' => 'Sam Publisher', 'email' => 'sam@example.test']);
        $locked = $this->makeUser($target->organization, RoleName::PublisherViewer, ['locked_until' => now()->addHour()]);
        $page = $this->get(route('admin.publishers.show', $publisher))->assertOk()->assertSee('Choose publisher user')
            ->assertSee(route('admin.impersonate.start', $target), false)->assertSee(route('admin.impersonate.start', $second), false)
            ->assertDontSee(route('admin.impersonate.start', $locked), false);
        $this->fixture('admin-multiple', $page->getContent());
        $staff = $this->makeUser($admin->organization, RoleName::FinanceAdmin);
        $this->actingAs($staff)->get(route('admin.publishers.show', $publisher))->assertOk()
            ->assertDontSee('Log in as publisher')->assertDontSee('Log in as this user');
        $this->post(route('admin.impersonate.start', $target))->assertForbidden();
        $this->actingAs($target)->post(route('admin.impersonate.start', $second))->assertForbidden();
    }

    public function test_publisher_losing_eligibility_preserves_safe_return(): void
    {
        [$admin, $target] = $this->context();
        $this->post(route('admin.impersonate.start', $target))->assertRedirect('/');
        $target->update(['status' => UserStatus::Suspended]);
        $this->actingAs($target->fresh());
        $page = $this->get(route('dashboard'))->assertForbidden()->assertSee('Return to admin')->assertSessionHas('impersonator_id', $admin->id);
        $this->fixture('interrupted', $page->getContent());
        $this->delete(route('admin.impersonate.stop'))->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_publisher_losing_verification_can_still_return(): void
    {
        [$admin, $target] = $this->context();
        $this->post(route('admin.impersonate.start', $target))->assertRedirect('/');
        $target->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($target->fresh())->get(route('publisher.reporting.index'))->assertForbidden()->assertSee('Return to admin');
        $this->delete(route('admin.impersonate.stop'))->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_revoked_original_admin_cannot_continue_or_return(): void
    {
        [$admin, $target] = $this->context();
        $this->post(route('admin.impersonate.start', $target))->assertRedirect('/');
        $admin->roles()->detach();
        $this->delete(route('admin.impersonate.stop'))->assertRedirect(route('admin.login'))->assertSessionMissing('impersonator_id');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.impersonation.revoked', 'actor_id' => $admin->id]);
    }

    public function test_deleted_original_admin_fails_closed_on_next_request(): void
    {
        [$admin, $target] = $this->context();
        $this->post(route('admin.impersonate.start', $target))->assertRedirect('/');
        $admin->delete();
        $this->get(route('dashboard'))->assertRedirect(route('admin.login'))->assertSessionMissing('impersonator_id');
        $this->assertGuest();
    }

    public function test_publisher_mfa_changes_do_not_renew_original_admin_mfa(): void
    {
        [$admin, $target] = $this->context();
        $originalTime = now()->subHours(11)->timestamp;
        $this->withSession(['two_factor_passed_at' => $originalTime])->post(route('admin.impersonate.start', $target))->assertRedirect('/');
        $this->travel(2)->hours();
        $this->withSession(['two_factor_passed_at' => now()->timestamp, 'account_recovery_codes' => ['synthetic']]);
        $this->delete(route('admin.impersonate.stop'))->assertRedirect('/')->assertSessionHas('two_factor_passed_at', $originalTime)
            ->assertSessionMissing('account_recovery_codes')->assertSessionMissing('impersonator_two_factor_passed_at');
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_logout_ends_temporary_session_without_automatic_admin_restore(): void
    {
        [, $target] = $this->context();
        $this->post(route('admin.impersonate.start', $target))->assertRedirect('/');
        $this->post(route('logout'))->assertRedirect(route('admin.login'))->assertSessionMissing('impersonator_id');
        $this->assertGuest();
        $this->delete(route('admin.impersonate.stop'))->assertRedirect(route('admin.login'));
    }

    public function test_csrf_is_required_for_both_identity_switches(): void
    {
        [, $target] = $this->context();
        $this->app['env'] = 'production';
        $this->post(route('admin.impersonate.start', $target))->assertStatus(419);
        $this->delete(route('admin.impersonate.stop'))->assertStatus(419);
    }

    private function fixture(string $name, string $html): void
    {
        if (getenv('HORUS_UI_FIXTURES') !== '1') return;
        $directory = storage_path('framework/testing/impersonation');
        if (! is_dir($directory)) mkdir($directory, 0755, true);
        $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $html);
        $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}
