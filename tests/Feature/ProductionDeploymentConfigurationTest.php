<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionDeploymentConfigurationTest extends TestCase
{
    public function test_production_environment_template_has_non_empty_runtime_critical_defaults(): void
    {
        $environment = file_get_contents(base_path('.env.production.example'));

        $this->assertIsString($environment);
        $this->assertStringContainsString("SESSION_COOKIE=horus-media-session\n", $environment);
        $this->assertStringContainsString("DB_CACHE_TABLE=cache\n", $environment);
        $this->assertStringContainsString("DB_CACHE_LOCK_TABLE=cache_locks\n", $environment);
        $this->assertStringContainsString("AUTH_EMAIL_VERIFICATION_REQUIRED=false\n", $environment);
        $this->assertStringContainsString("AUTH_ADMIN_2FA_REQUIRED=false\n", $environment);
        $this->assertDoesNotMatchRegularExpression('/^MYSQL_ATTR_SSL_CA=\s*$/m', $environment);
        $this->assertDoesNotMatchRegularExpression('/^(SESSION_COOKIE|DB_CACHE_TABLE|DB_CACHE_LOCK_TABLE)=\s*$/m', $environment);
    }

    public function test_runtime_config_defends_against_blank_cache_table_and_session_cookie_values(): void
    {
        $cache = file_get_contents(config_path('cache.php'));
        $session = file_get_contents(config_path('session.php'));
        $database = file_get_contents(config_path('database.php'));

        $this->assertIsString($cache);
        $this->assertIsString($session);
        $this->assertIsString($database);

        $this->assertStringContainsString("env('DB_CACHE_TABLE') ?: 'cache'", $cache);
        $this->assertStringContainsString("env('DB_CACHE_LOCK_TABLE') ?: 'cache_locks'", $cache);
        $this->assertStringContainsString("env('SESSION_COOKIE') ?: Str::slug", $session);
        $this->assertStringContainsString("$value !== null && $value !== ''", $database);
    }

    public function test_atomic_deployment_assets_are_versioned_with_the_repository(): void
    {
        $this->assertFileExists(base_path('ops/deploy/horus-atomic-deploy.sh'));
        $this->assertFileExists(base_path('ops/deploy/horus-bootstrap-atomic-layout.sh'));
        $this->assertFileExists(base_path('ops/deploy/write-mysql-client-config.php'));
        $this->assertFileExists(base_path('.github/workflows/deploy-production.yml'));
    }
}
