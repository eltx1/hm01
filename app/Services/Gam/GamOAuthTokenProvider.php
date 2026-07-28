<?php

namespace App\Services\Gam;

use App\Enums\GamCredentialType;
use App\Models\GamConnection;
use App\Services\Gam\Exceptions\GamTransportException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GamOAuthTokenProvider
{
    public function __construct(private readonly GamSecretResolver $secrets)
    {
    }

    public function accessToken(GamConnection $connection): string
    {
        $credential = $connection->credential()->firstOrFail();
        $cacheKey = 'gam:oauth:'.hash('sha256', $connection->id.'|'.($credential->rotated_at?->timestamp ?? '0'));

        if (is_string($cached = Cache::get($cacheKey))) {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                Cache::forget($cacheKey);
            }
        }

        $material = $this->secrets->readJson($credential->reference);
        $token = $credential->credential_type === GamCredentialType::ServiceAccount
            ? $this->serviceAccountToken($material)
            : $this->refreshToken($material);

        Cache::put($cacheKey, Crypt::encryptString($token['access_token']), now()->addSeconds(max(60, ((int) ($token['expires_in'] ?? 3600)) - 120)));

        return $token['access_token'];
    }

    private function serviceAccountToken(array $material): array
    {
        foreach (['client_email', 'private_key'] as $required) {
            if (empty($material[$required])) {
                throw new GamTransportException("Service-account credential is missing {$required}.", 'INVALID_CREDENTIAL_FILE');
            }
        }

        $now = time();
        $tokenUri = $material['token_uri'] ?? config('gam.oauth.token_uri');
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $material['client_email'],
            'scope' => config('gam.oauth.scope'),
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;

        if (! openssl_sign($unsigned, $signature, $material['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new GamTransportException('Unable to sign the service-account assertion.', 'JWT_SIGNING_FAILED');
        }

        return $this->requestToken($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$this->base64Url($signature),
        ]);
    }

    private function refreshToken(array $material): array
    {
        foreach (['client_id', 'client_secret', 'refresh_token'] as $required) {
            if (empty($material[$required])) {
                throw new GamTransportException("OAuth credential is missing {$required}.", 'INVALID_CREDENTIAL_FILE');
            }
        }

        return $this->requestToken($material['token_uri'] ?? config('gam.oauth.token_uri'), [
            'grant_type' => 'refresh_token',
            'client_id' => $material['client_id'],
            'client_secret' => $material['client_secret'],
            'refresh_token' => $material['refresh_token'],
        ]);
    }

    private function requestToken(string $tokenUri, array $form): array
    {
        try {
            $response = Http::asForm()->timeout(20)->retry(2, 250, throw: false)->post($tokenUri, $form);
        } catch (Throwable $exception) {
            throw new GamTransportException('Unable to contact the OAuth token endpoint.', 'OAUTH_NETWORK_ERROR', true, true, previous: $exception);
        }

        if (! $response->successful()) {
            throw new GamTransportException('Google rejected the configured OAuth credential.', (string) $response->json('error', 'OAUTH_REJECTED'));
        }

        $payload = $response->json();
        if (! is_array($payload) || empty($payload['access_token'])) {
            throw new GamTransportException('The OAuth token response did not include an access token.', 'INVALID_OAUTH_RESPONSE');
        }

        return $payload;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
