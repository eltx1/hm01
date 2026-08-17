<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionEnvironmentTemplateContractTest extends TestCase
{
    public function test_critical_production_configuration_placeholders_are_present_and_secret_safe(): void
    {
        $path = dirname(__DIR__, 2).'/.env.production.example';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $values = $this->boundedTemplateValues($contents);

        $criticalKeys = [
            'APP_ENV', 'APP_URL', 'APP_KEY', 'APP_DEBUG',
            'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION',
            'PUBLIC_PUBLISHER_REGISTRATION_ENABLED',
            'PUBLISHER_TERMS_OF_SERVICE_VERSION', 'PUBLISHER_TERMS_OF_SERVICE_URL',
            'PUBLISHER_PRIVACY_POLICY_VERSION', 'PUBLISHER_PRIVACY_POLICY_URL',
            'PUBLISHER_TERMS_VERSION', 'PUBLISHER_TERMS_URL',
            'TURNSTILE_ENABLED', 'TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY',
            'TURNSTILE_EXPECTED_HOSTNAME', 'TURNSTILE_ACTION', 'TURNSTILE_PROVIDER', 'TURNSTILE_TIMEOUT_SECONDS',
            'HORUS_STATIC_DELIVERY_DRIVER', 'HORUS_EDGE_GITHUB_REPOSITORY', 'HORUS_EDGE_GITHUB_BRANCH',
            'HORUS_EDGE_GITHUB_TOKEN_REFERENCE', 'HORUS_EDGE_GITHUB_TOKEN',
            'AUTH_ADMIN_2FA_REQUIRED', 'AUTH_MAX_FAILED_ATTEMPTS', 'AUTH_LOCK_MINUTES',
            'GAM_APPLICATION_NAME', 'GAM_HORUS_NETWORK_CODE', 'GAM_HORUS_SERVICE_ACCOUNT_PATH',
            'GAM_REST_BASE_URL', 'GAM_REST_TIMEOUT',
            'THOTH_PROVIDER', 'THOTH_OPENAI_API_KEY', 'THOTH_GEMINI_API_KEY',
            'THOTH_TIMEOUT_SECONDS', 'THOTH_MAX_OUTPUT_TOKENS', 'THOTH_CONNECTION_MAX_AGE_MINUTES',
            'PRIVACY_DIAGNOSTIC_ENDPOINT', 'PRIVACY_DIAGNOSTIC_TTL_MINUTES', 'PRIVACY_DIAGNOSTIC_MAX_BYTES',
            'DATA_RETENTION_CHUNK_SIZE', 'DATA_RETENTION_SYNTHETIC_PROBES_DAYS',
            'DATA_RETENTION_PRIVACY_DIAGNOSTIC_EVIDENCE_DAYS',
            'DATA_RETENTION_PRIVACY_DIAGNOSTIC_TOKEN_GRACE_DAYS',
            'DATA_RETENTION_EXPIRED_INVITATIONS_DAYS',
            'DATA_RETENTION_COMPLETED_JOB_BATCHES_DAYS', 'DATA_RETENTION_AUDIT_LOGS_DAYS',
        ];

        foreach ($criticalKeys as $key) {
            $this->assertArrayHasKey($key, $values, $key.' is missing from .env.production.example');
        }

        foreach ([
            'APP_KEY',
            'DB_PASSWORD',
            'MAIL_USERNAME',
            'MAIL_PASSWORD',
            'TURNSTILE_SITE_KEY',
            'TURNSTILE_SECRET_KEY',
            'THOTH_OPENAI_API_KEY',
            'THOTH_GEMINI_API_KEY',
            'GAM_HORUS_NETWORK_CODE',
            'HORUS_EDGE_GITHUB_TOKEN',
            'LOG_SLACK_WEBHOOK_URL',
            'SLACK_BOT_USER_OAUTH_TOKEN',
            'AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY',
        ] as $secretPlaceholder) {
            $this->assertArrayHasKey($secretPlaceholder, $values);
            $this->assertSame('', $values[$secretPlaceholder], $secretPlaceholder.' must remain an empty placeholder');
        }

        $this->assertArrayNotHasKey('TURNSTILE_TEST_TOKEN', $values, 'Test-only deterministic Turnstile tokens must not be in the production template.');
    }

    /**
     * This intentionally recognizes only simple KEY=value declarations in our
     * controlled example file. It is not a general-purpose dotenv/shell parser.
     *
     * @return array<string, string>
     */
    private function boundedTemplateValues(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (! preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
                continue;
            }

            $values[$matches[1]] = $matches[2];
        }

        return $values;
    }
}
