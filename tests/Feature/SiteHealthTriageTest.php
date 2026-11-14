<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\MonetizationHealthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SiteHealthTriageTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_filters_distinguish_stored_findings_stale_and_missing_records_without_monitoring(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($owner);
        foreach (['Finding' => ['BROKEN', now()], 'Stale' => ['HEALTHY', now()->subHours(2)], 'Missing' => null] as $name => $evidence) {
            $site = $this->makeSiteFor($publisher, $owner, ['display_name' => 'Triage'.$name, 'primary_domain' => strtolower($name).'.example.test', 'status' => 'ACTIVE']);
            $site->update(['status' => 'ACTIVE']);
            if ($evidence) MonetizationHealthState::create(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'state_key' => 'renderer_conflict', 'status' => $evidence[0], 'observed_at' => $evidence[1]]);
        }
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        foreach (['findings' => 'Finding', 'stale' => 'Stale', 'missing' => 'Missing'] as $filter => $expected) {
            $response = $this->get(route('admin.sites.index', ['health' => $filter]))->assertOk()->assertSee('Triage'.$expected);
            foreach (array_diff(['Finding', 'Stale', 'Missing'], [$expected]) as $other) $response->assertDontSee('Triage'.$other);
        }
        $this->assertSame(2, MonetizationHealthState::count());
        DB::enableQueryLog();
        $this->get(route('admin.sites.index'))->assertOk()->assertSee('TriageFinding')->assertSee('TriageStale')->assertSee('TriageMissing');
        $queries = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/^select .* from ["`]monetization_health_states["`] where/i', $query['query']));
        DB::disableQueryLog();
        $this->assertCount(1, $queries, 'Health evidence must be eager-loaded once for the page, not per website.');
        $this->actingAs($owner)->get(route('admin.sites.index', ['health' => 'findings']))->assertForbidden();
    }
}
