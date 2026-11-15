<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteOperationReferenceTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    public function test_successful_site_save_correlates_the_existing_request_and_response_without_replaying_it(): void
    {
        $calls = 0;
        Route::middleware('web')->post('/test-site-save', function () use (&$calls) {
            $calls++;
            return redirect('/')->with('status', 'Saved; delivery pending.');
        })->name('admin.sites.reference-test');
        $this->withHeader('X-Request-ID', self::ID)->post('/test-site-save')
            ->assertRedirect()->assertHeader('X-Request-ID', self::ID)
            ->assertSessionHas('operation_reference', self::ID)->assertSessionHas('status', 'Saved; delivery pending.');
        $this->assertSame(1, $calls);
    }

    public function test_validation_redirects_and_unrelated_routes_do_not_claim_a_saved_operation(): void
    {
        Route::middleware('web')->post('/test-site-invalid', fn () => redirect('/')->withErrors(['setting' => 'Invalid']))->name('admin.sites.invalid-test');
        $this->post('/test-site-invalid')->assertSessionHasErrors('setting')->assertSessionMissing('operation_reference');
        Route::middleware('web')->post('/test-other-save', fn () => redirect('/')->with('status', 'Saved'))->name('publisher.other-test');
        $this->withHeader('X-Request-ID', self::ID)->post('/test-other-save')->assertSessionMissing('operation_reference');
    }

    public function test_get_and_existing_endpoint_reference_are_preserved(): void
    {
        Route::middleware('web')->get('/test-site-read', fn () => redirect('/')->with('status', 'Read'))->name('admin.sites.read-test');
        $this->withHeader('X-Request-ID', self::ID)->get('/test-site-read')->assertSessionMissing('operation_reference');
        Route::middleware('web')->post('/test-site-owned-reference', fn () => redirect('/')->with('status', 'Saved')->with('operation_reference', 'endpoint-reference'))->name('admin.sites.owned-reference-test');
        $this->post('/test-site-owned-reference')->assertSessionHas('operation_reference', 'endpoint-reference');
    }
}
