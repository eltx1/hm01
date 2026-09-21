<?php

namespace App\Services\Gam;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Platform-owned OAuth setup; website administrators only authorize accounts. */
final class GamReportingGoogleApp
{
    public function __construct(private readonly GamManagedCredentials $secrets, private readonly AuditRecorder $audit) {}

    public function redirectUri(): string
    {
        return rtrim(config('app.url'), '/').route('admin.gam.reporting.oauth.callback', absolute: false);
    }

    public function credentials(): ?array
    {
        try {
            // Explicit deployment configuration takes precedence; never fall back
            // to an unrelated client if that configuration is incomplete.
            $reference = config('gam.onboarding.oauth_app_reference');
            if (is_string($reference) && $reference !== '') {
                $json = app(GamSecretResolver::class)->readJson($reference);

                return $this->validateWebApplication($json);
            }
            if (! $this->secrets->hasApp()) {
                return null;
            }
            $app = $this->secrets->read('managed:oauth-app');

            return $this->validPair($app) ? array_intersect_key($app, array_flip(['client_id', 'client_secret'])) : null;
        } catch (Throwable) {
            // Never expose private paths, parsing errors or credential material.
            return null;
        }
    }

    public function ready(): bool
    {
        return $this->credentials() !== null;
    }

    public function save(array $json, ?User $actor = null): void
    {
        $this->secrets->write($this->validateWebApplication($json), 'oauth-app');
        $this->audit->record('gam.reporting.oauth_app.configured', $actor?->organization_id, $actor);
    }

    private function validateWebApplication(array $json): array
    {
        $web = $json['web'] ?? null;
        if (! is_array($web) || ! $this->validPair($web)
            || ! is_array($web['redirect_uris'] ?? null)
            || ! in_array($this->redirectUri(), $web['redirect_uris'], true)) {
            throw ValidationException::withMessages(['gam_account' => 'The platform Google application is invalid or does not authorize its reporting callback.']);
        }

        return array_intersect_key($web, array_flip(['client_id', 'client_secret']));
    }

    private function validPair(array $app): bool
    {
        return is_string($app['client_id'] ?? null)
            && preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/D', $app['client_id'])
            && is_string($app['client_secret'] ?? null) && strlen($app['client_secret']) >= 8;
    }
}
