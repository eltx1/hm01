<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class DashboardLayoutTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_all_paginator_states_use_the_shared_accessible_markup_and_preserve_filters(): void
    {
        foreach ([1, 2, 40] as $page) {
            $paginator = new LengthAwarePaginator(range(1, 25), 1000, 25, $page, ['path' => '/sites']);
            $html = $paginator->appends(['status' => 'ACTIVE'])->links()->toHtml();
            $this->assertStringContainsString('hm-pagination', $html);
            $this->assertStringContainsString('aria-current="page"', $html);
            $this->assertStringContainsString('status=ACTIVE', $html);
            $this->assertStringNotContainsString('<svg', $html);
            $this->assertStringNotContainsString('&amp;laquo;', $html);
            $this->assertStringNotContainsString('&amp;raquo;', $html);
            $this->assertStringNotContainsString('sm:', $html);
        }
        $single = new LengthAwarePaginator(range(1, 5), 5, 25);
        $this->assertStringNotContainsString('<nav', $single->links()->toHtml());
        $simple = new Paginator(range(1, 26), 25, 2, ['path' => '/notifications']);
        $this->assertStringContainsString('hm-pagination', $simple->links()->toHtml());
    }

    public function test_real_admin_and_publisher_lists_render_both_pages_with_local_table_scrolling(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        for ($i = 0; $i < 27; $i++) {
            $this->makeSiteFor($publisher, $user, ['display_name' => 'Publisher website '.($i + 1), 'primary_domain' => 'website-'.$i.'.example.test']);
        }
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        foreach (['admin' => $admin, 'publisher' => $user] as $role => $actor) {
            $this->actingAs($actor)->withSession(['two_factor_passed_at' => now()->timestamp]);
            foreach (app(\App\Services\ControlPlane\ControlPlaneNavigation::class)->for($actor) as $group) {
                foreach ($group['items'] as $item) {
                    $response = $this->get(route($item['route'], $item['parameters']))->assertSuccessful();
                    if (getenv('HORUS_UI_FIXTURES') === '1') {
                        $directory = storage_path('framework/testing/dashboard-layout');
                        if (! is_dir($directory)) mkdir($directory, 0755, true);
                        $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $response->getContent());
                        $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
                        file_put_contents($directory.'/nav-'.$role.'-'.str_replace('.', '-', $item['route']).'.html', $html);
                    }
                }
            }
            foreach ([1, 2] as $page) {
                $response = $this->get(route($role.'.sites.index', ['page' => $page]))
                    ->assertOk()->assertSee('hm-pagination')->assertSee('27');
                if (getenv('HORUS_UI_FIXTURES') === '1') {
                    $directory = storage_path('framework/testing/dashboard-layout');
                    if (! is_dir($directory)) mkdir($directory, 0755, true);
                    $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $response->getContent());
                    $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
                    file_put_contents($directory.'/'.$role.'-'.$page.'.html', $html);
                }
            }
        }
    }
}
