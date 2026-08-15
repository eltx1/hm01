<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

final class ProductUxLaunchReadinessTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_private_control_plane_has_explicit_noindex_policy_and_crawl_guidance(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet')
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">', false)
            ->assertSee('Skip to main content');

        $robots = File::get(public_path('robots.txt'));
        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Disallow: /', $robots);
        $this->assertStringContainsString('authentication and authorization remain the security boundary', $robots);
    }

    public function test_customer_and_staff_authentication_support_password_managers_and_accessible_field_semantics(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('autocomplete="email"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('for="login-email"', false)
            ->assertDontSee('onpaste=', false);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('autocomplete="email"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('for="staff-password"', false)
            ->assertDontSee('onpaste=', false);

        $this->get('/reset-password/test-token?email=owner%40example.test')
            ->assertOk()
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('Password managers and paste are supported.')
            ->assertDontSee('onpaste=', false);

        $this->withSession(['two_factor_user_id' => 'accessibility-probe', 'two_factor_context' => 'admin'])
            ->get('/two-factor/challenge')
            ->assertOk()
            ->assertSee('autocomplete="one-time-code"', false)
            ->assertSee('Copy and paste are supported.');
    }

    public function test_publisher_registration_has_field_errors_and_an_assisted_turnstile_path(): void
    {
        Config::set('publisher-applications.public_registration_enabled', true);
        Config::set('publisher-applications.turnstile.enabled', true);
        Config::set('publisher-applications.turnstile.site_key', 'test-site-key');
        Config::set('publisher-applications.support_url', 'https://horusmedia.net/contact');

        $this->get('/register/publisher')
            ->assertOk()
            ->assertSee('aria-current="step"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('aria-describedby="publisher-password-help', false)
            ->assertSee('contact Horus Media support for an assisted application path')
            ->assertSee('data-size="flexible"', false)
            ->assertDontSee('onpaste=', false);
    }

    public function test_branded_safe_error_family_exists_without_internal_diagnostics(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $html = view("errors.{$code}")->render();
            $this->assertStringContainsString('Horus Media', $html);
            $this->assertStringContainsString((string) $code, $html);
            $this->assertStringContainsString('noindex, nofollow, noarchive, nosnippet', $html);
            $this->assertStringNotContainsString('SQLSTATE', $html);
            $this->assertStringNotContainsString('APP_ENV', $html);
            $this->assertStringNotContainsString('/var/www', $html);
        }
    }

    public function test_real_forbidden_and_not_found_responses_use_safe_product_surfaces(): void
    {
        $this->seedIdentity();
        $publisher = $this->makeUser(
            $this->makeOrganization(OrganizationType::Publisher, 'Publisher'),
            RoleName::PublisherAdmin,
        );

        $this->actingAs($publisher)
            ->get('/admin/organizations')
            ->assertForbidden()
            ->assertSee('Access not available')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

        $this->get('/this-route-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertDontSee('Stack trace');
    }

    public function test_authentication_emails_use_horus_branded_mail_layer_without_replacing_canonical_routes(): void
    {
        $provider = File::get(app_path('Providers/AppServiceProvider.php'));
        $invitation = File::get(app_path('Notifications/UserInvitationNotification.php'));
        $mailView = File::get(resource_path('views/emails/auth-action.blade.php'));

        $this->assertStringContainsString('ResetPassword::toMailUsing', $provider);
        $this->assertStringContainsString('VerifyEmail::toMailUsing', $provider);
        $this->assertStringContainsString("route('password.reset'", $provider);
        $this->assertStringContainsString("->view('emails.auth-action'", $provider);
        $this->assertStringContainsString("route('invitations.accept.show'", $invitation);
        $this->assertStringContainsString("->view('emails.auth-action'", $invitation);
        $this->assertStringContainsString("@extends('emails.layouts.horus')", $mailView);
        $this->assertTrue(app('router')->has('password.reset'));
        $this->assertTrue(app('router')->has('verification.verify'));
        $this->assertTrue(app('router')->has('invitations.accept.show'));
    }

    public function test_reusable_status_language_normalizes_common_product_states(): void
    {
        $this->assertStringContainsString('Pending review', view('components.status-badge', ['status' => 'UNDER_REVIEW'])->render());
        $this->assertStringContainsString('Action required', view('components.status-badge', ['status' => 'MORE_INFO_REQUIRED'])->render());
        $this->assertStringContainsString('Not configured', view('components.status-badge', ['status' => 'NOT_CONFIGURED'])->render());
        $this->assertStringContainsString('Not applicable', view('components.status-badge', ['status' => 'NOT_APPLICABLE'])->render());
    }

    public function test_auth_views_do_not_block_paste_and_launch_css_keeps_keyboard_and_mobile_contracts(): void
    {
        $authMarkup = collect(File::files(resource_path('views/auth')))
            ->map(fn ($file) => strtolower(File::get($file->getPathname())))
            ->implode("\n");
        $css = File::get(resource_path('css/ux-launch.css'));
        $javascript = File::get(resource_path('js/app.js'));

        $this->assertStringNotContainsString('onpaste=', $authMarkup);
        $this->assertStringNotContainsString('oncopy=', $authMarkup);
        $this->assertStringNotContainsString('oncut=', $authMarkup);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('scroll-margin-block', $css);
        $this->assertStringContainsString('@media (max-width: 430px)', $css);
        $this->assertStringContainsString('@media (max-width: 768px)', $css);
        $this->assertStringContainsString('@media (max-width: 1024px)', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('form.dataset.submitting', $javascript);
        $this->assertStringContainsString('navigationToggle.focus()', $javascript);
    }
}
