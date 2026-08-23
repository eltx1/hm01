<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RevenueRuleScope;
use App\Enums\RoleName;
use App\Services\Reporting\RevenueRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class RevenueRuleConflictSafetyTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_same_rank_overlapping_rule_for_same_target_is_rejected(): void
    {
        [$admin, $publisher] = $this->context();
        $service = app(RevenueRuleService::class);
        $effective = now()->toDateString();

        $service->createRule([
            'name' => 'Publisher USD default',
            'scope_type' => RevenueRuleScope::Publisher,
            'scope_id' => $publisher->id,
            'effective_from' => $effective,
            'currency' => 'USD',
            'publisher_share_bp' => 7500,
            'horus_share_bp' => 2500,
            'mcm_partner_share_bp' => 0,
            'priority' => 0,
        ], $admin);

        try {
            $service->createRule([
                'name' => 'Ambiguous duplicate',
                'scope_type' => RevenueRuleScope::Publisher,
                'scope_id' => $publisher->id,
                'effective_from' => $effective,
                'currency' => 'USD',
                'publisher_share_bp' => 8000,
                'horus_share_bp' => 2000,
                'mcm_partner_share_bp' => 0,
                'priority' => 0,
            ], $admin);
            $this->fail('Expected ambiguous revenue rule creation to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_from', $exception->errors());
            $this->assertStringContainsString('already covers the same scope', $exception->errors()['effective_from'][0]);
        }
    }

    public function test_different_currency_or_priority_remains_explicit_and_resolvable(): void
    {
        [$admin, $publisher] = $this->context();
        $service = app(RevenueRuleService::class);
        $effective = now()->toDateString();

        $usd = $service->createRule([
            'name' => 'USD share',
            'scope_type' => RevenueRuleScope::Publisher,
            'scope_id' => $publisher->id,
            'effective_from' => $effective,
            'currency' => 'USD',
            'publisher_share_bp' => 7500,
            'horus_share_bp' => 2500,
            'mcm_partner_share_bp' => 0,
            'priority' => 0,
        ], $admin);
        $eur = $service->createRule([
            'name' => 'EUR share',
            'scope_type' => RevenueRuleScope::Publisher,
            'scope_id' => $publisher->id,
            'effective_from' => $effective,
            'currency' => 'EUR',
            'publisher_share_bp' => 8000,
            'horus_share_bp' => 2000,
            'mcm_partner_share_bp' => 0,
            'priority' => 0,
        ], $admin);
        $preferredUsd = $service->createRule([
            'name' => 'Preferred USD share',
            'scope_type' => RevenueRuleScope::Publisher,
            'scope_id' => $publisher->id,
            'effective_from' => $effective,
            'currency' => 'USD',
            'publisher_share_bp' => 8250,
            'horus_share_bp' => 1750,
            'mcm_partner_share_bp' => 0,
            'priority' => 10,
        ], $admin);

        $this->assertSame(
            $preferredUsd->current_version_id,
            $service->resolve($effective, ['publisher_id' => $publisher->id], 'USD')->id,
        );
        $this->assertSame(
            $eur->current_version_id,
            $service->resolve($effective, ['publisher_id' => $publisher->id], 'EUR')->id,
        );
        $this->assertNotSame($usd->current_version_id, $preferredUsd->current_version_id);
    }

    public function test_currency_agnostic_rule_conflicts_with_currency_specific_rule_at_same_rank(): void
    {
        [$admin, $publisher] = $this->context();
        $service = app(RevenueRuleService::class);
        $effective = now()->toDateString();

        $service->createRule([
            'name' => 'Any currency share',
            'scope_type' => RevenueRuleScope::Publisher,
            'scope_id' => $publisher->id,
            'effective_from' => $effective,
            'publisher_share_bp' => 7000,
            'horus_share_bp' => 3000,
            'mcm_partner_share_bp' => 0,
            'priority' => 0,
        ], $admin);

        $this->expectException(ValidationException::class);
        $service->createRule([
            'name' => 'USD conflicting share',
            'scope_type' => RevenueRuleScope::Publisher,
            'scope_id' => $publisher->id,
            'effective_from' => $effective,
            'currency' => 'USD',
            'publisher_share_bp' => 7200,
            'horus_share_bp' => 2800,
            'mcm_partner_share_bp' => 0,
            'priority' => 0,
        ], $admin);
    }

    public function test_admin_preview_uses_the_real_resolver_and_shows_the_winning_share(): void
    {
        [$admin, $publisher] = $this->context();
        $effective = now()->toDateString();
        app(RevenueRuleService::class)->createRule([
            'name' => 'Preview winner',
            'scope_type' => RevenueRuleScope::Publisher,
            'scope_id' => $publisher->id,
            'effective_from' => $effective,
            'currency' => 'USD',
            'publisher_share_bp' => 8250,
            'horus_share_bp' => 1750,
            'mcm_partner_share_bp' => 0,
            'priority' => 10,
        ], $admin);

        $this->actingAs($admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.finance.revenue-rules.index', [
                'preview' => 1,
                'preview_publisher_id' => $publisher->id,
                'preview_date' => $effective,
                'preview_currency' => 'USD',
            ]))
            ->assertOk()
            ->assertSee('Preview winner')
            ->assertSee('82.50%')
            ->assertSee('17.50%')
            ->assertSee('This preview is read-only and changes nothing.');
    }

    private function context(): array
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['display_name' => 'Publisher']);

        return [$admin, $publisher];
    }
}
