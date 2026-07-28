<?php

namespace Tests\Concerns;

use App\Models\Publisher;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteLifecycleService;

trait InteractsWithPublisherSites
{
    protected function makePublisherFor(User $user, array $attributes = []): Publisher
    {
        return Publisher::withoutGlobalScopes()->create(array_merge([
            'organization_id' => $user->organization_id,
            'legal_name' => 'Publisher LLC',
            'display_name' => 'Publisher',
            'status' => 'ACTIVE',
            'billing_email' => 'billing@publisher.test',
        ], $attributes));
    }

    protected function makeSiteFor(Publisher $publisher, User $actor, array $attributes = []): Site
    {
        return app(SiteLifecycleService::class)->create(array_merge([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
            'display_name' => 'Example Site',
            'primary_domain' => fake()->unique()->domainName(),
            'language' => 'en',
            'content_category' => 'News',
            'country' => 'US',
            'main_traffic_countries' => ['US', 'GB'],
            'estimated_monthly_pageviews' => 100000,
            'estimated_monthly_users' => 50000,
            'current_monetization_providers' => ['AdSense'],
            'prebid_enabled' => false,
            'native_demand_enabled' => false,
            'default_revenue_share_percent' => 70,
        ], $attributes), $actor);
    }
}
