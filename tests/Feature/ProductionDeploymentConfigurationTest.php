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

    public function test_runtime_config_defends_against_blank_cache_table_session_cookie_and_ssl_ca_values(): void
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
        $this->assertStringContainsString("\$value !== null && \$value !== ''", $database);
    }

    public function test_atomic_deployment_assets_are_versioned_with_the_repository(): void
    {
        $this->assertFileExists(base_path('ops/deploy/horus-atomic-deploy.sh'));
        $this->assertFileExists(base_path('ops/deploy/horus-bootstrap-atomic-layout.sh'));
        $this->assertFileExists(base_path('ops/deploy/write-mysql-client-config.php'));
        $this->assertFileExists(base_path('.github/workflows/deploy-production.yml'));
    }

    public function test_global_click_guard_deployment_audit_uses_typed_array_values(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_24_020000_enable_global_click_guard.php'));

        $this->assertIsString($migration);
        $pattern = <<<'REGEX'
/AuditRecorder::class\)->record\(\s*'click_guard\.global_default\.deployed',\s*null,\s*\$actor,\s*null,\s*\[\],\s*\$policy,/s
REGEX;
        $this->assertMatchesRegularExpression(
            $pattern,
            $migration,
        );
    }

    public function test_direct_origin_tls_override_is_narrow_and_public_health_remains_strict(): void
    {
        $deploy = file_get_contents(base_path('ops/deploy/horus-atomic-deploy.sh'));
        $bootstrap = file_get_contents(base_path('ops/deploy/horus-bootstrap-atomic-layout.sh'));
        $workflow = file_get_contents(base_path('.github/workflows/deploy-production.yml'));

        $this->assertIsString($deploy);
        $this->assertIsString($bootstrap);
        $this->assertIsString($workflow);

        foreach ([$deploy, $bootstrap] as $script) {
            $this->assertStringContainsString('HORUS_DEPLOY_HEALTH_INSECURE_TLS', $script);
            $this->assertStringContainsString("HEALTH_INSECURE_TLS\" == '0' || \"\$HEALTH_INSECURE_TLS\" == '1'", $script);
            $this->assertStringContainsString('requires an explicit HORUS_DEPLOY_HEALTH_RESOLVE_IP', $script);
            $this->assertStringContainsString("--noproxy '*' --resolve", $script);
            $this->assertMatchesRegularExpression('/\b(?:curl_)?args\+=\(--insecure\)/', $script);
        }

        $this->assertStringContainsString('HORUS_PRODUCTION_ORIGIN_HEALTH_INSECURE_TLS', $workflow);
        $this->assertStringContainsString("HORUS_DEPLOY_HEALTH_INSECURE_TLS='\$ORIGIN_HEALTH_INSECURE_TLS'", $workflow);

        preg_match('/- name: Probe public production edge(?<step>.*?)(?:\n\s{6}- name:|\z)/s', $workflow, $matches);
        $this->assertArrayHasKey('step', $matches);
        $this->assertStringContainsString('curl -sS', $matches['step']);
        $this->assertStringContainsString("--write-out '%{http_code}'", $matches['step']);
        $this->assertStringContainsString('403)', $matches['step']);
        $this->assertStringContainsString('authoritative origin health passed', $matches['step']);
        $this->assertStringNotContainsString('--insecure', $matches['step']);
        $this->assertStringNotContainsString('-k ', $matches['step']);
    }
}
