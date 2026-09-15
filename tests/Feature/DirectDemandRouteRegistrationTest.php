<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DirectDemandRouteRegistrationTest extends TestCase
{
    public function test_named_routes_are_unique_and_quick_monetize_is_registered(): void
    {
        $seen = [];
        $duplicates = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || $name === '') {
                continue;
            }

            if (isset($seen[$name])) {
                $duplicates[$name] = true;
            }

            $seen[$name] = true;
        }

        $this->assertSame([], array_keys($duplicates), 'Duplicate named routes prevent Laravel route serialization.');
        $this->assertTrue(Route::has('admin.demand.quick.create'));
        $this->assertTrue(Route::has('admin.demand.quick.store'));
        $this->assertTrue(Route::has('admin.demand.networks.toggle'));
    }

    public function test_direct_demand_has_one_route_source_and_release_validation_caches_routes(): void
    {
        $application = file_get_contents(base_path('bootstrap/app.php'));
        $providers = file_get_contents(base_path('bootstrap/providers.php'));
        $workflow = file_get_contents(base_path('.github/workflows/production-release.yml'));

        $this->assertIsString($application);
        $this->assertIsString($providers);
        $this->assertIsString($workflow);

        $this->assertStringContainsString("routes/direct-demand.php", $application);
        $this->assertStringNotContainsString('DemandServiceProvider::class', $providers);
        $this->assertStringContainsString('php artisan route:cache', $workflow);
        $this->assertStringContainsString('php artisan route:clear', $workflow);
    }

    public function test_privacy_diagnostic_options_route_is_controller_backed_for_route_caching(): void
    {
        $provider = file_get_contents(app_path('Providers/PrivacyServiceProvider.php'));
        $controller = file_get_contents(app_path('Http/Controllers/PrivacyDiagnosticReportController.php'));

        $this->assertIsString($provider);
        $this->assertIsString($controller);
        $this->assertStringContainsString(
            "Route::options('/privacy-diagnostics/report', [PrivacyDiagnosticReportController::class, 'options']);",
            $provider,
        );
        $this->assertStringNotContainsString("Route::options('/privacy-diagnostics/report', fn", $provider);
        $this->assertStringContainsString('public function options(): Response', $controller);
    }
}
