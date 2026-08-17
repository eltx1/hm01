<?php

namespace App\Services\PublisherApplications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class TurnstileVerifier
{
    public function verify(?string $token): void
    {
        if (! config('publisher-applications.turnstile.enabled')) {
            return;
        }

        $this->assertProductionConfiguration();

        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 2048) {
            $this->fail();
        }

        $result = $this->usesDeterministicProvider()
            ? $this->fakeResult($token)
            : $this->siteverify($token);

        if (! (bool) ($result['success'] ?? false)) {
            $this->fail();
        }

        $expectedHostname = trim((string) config('publisher-applications.turnstile.expected_hostname'));
        if ($expectedHostname !== '' && ! hash_equals(strtolower($expectedHostname), strtolower((string) ($result['hostname'] ?? '')))) {
            $this->fail();
        }

        $expectedAction = trim((string) config('publisher-applications.turnstile.action'));
        if ($expectedAction !== '' && ! hash_equals($expectedAction, (string) ($result['action'] ?? ''))) {
            $this->fail();
        }

        $challengeAt = strtotime((string) ($result['challenge_ts'] ?? ''));
        if ($challengeAt === false || time() - $challengeAt > 300 || $challengeAt > time() + 30) {
            $this->fail();
        }

        $replayKey = 'publisher-turnstile:'.hash('sha256', $token);
        if (! Cache::add($replayKey, true, now()->addMinutes(5))) {
            $this->fail();
        }
    }

    /** @return array<string, mixed> */
    private function siteverify(string $token): array
    {
        $secret = trim((string) config('publisher-applications.turnstile.secret_key'));
        if ($secret === '') {
            $this->fail();
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('publisher-applications.turnstile.timeout_seconds', 5))
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                ]);
        } catch (\Throwable $exception) {
            report($exception);
            $this->fail();
        }

        if (! $response->successful()) {
            $this->fail();
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function assertProductionConfiguration(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $provider = strtolower(trim((string) config('publisher-applications.turnstile.provider')));
        $secret = trim((string) config('publisher-applications.turnstile.secret_key'));
        $hostname = trim((string) config('publisher-applications.turnstile.expected_hostname'));
        $action = trim((string) config('publisher-applications.turnstile.action'));
        if ($provider !== 'cloudflare' || $secret === '' || $hostname === '' || $action === '') {
            $this->fail();
        }
    }

    private function usesDeterministicProvider(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return strtolower(trim((string) config('publisher-applications.turnstile.provider'))) === 'fake'
            || app()->environment(['local', 'testing']);
    }

    /** @return array<string, mixed> */
    private function fakeResult(string $token): array
    {
        if ($token === 'turnstile-test-expired') {
            return [
                'success' => true,
                'challenge_ts' => now()->subMinutes(6)->toIso8601String(),
                'hostname' => (string) config('publisher-applications.turnstile.expected_hostname'),
                'action' => (string) config('publisher-applications.turnstile.action'),
            ];
        }
        if ($token !== (string) config('publisher-applications.turnstile.test_token', 'turnstile-test-valid')) {
            return ['success' => false, 'error-codes' => ['invalid-input-response']];
        }

        return [
            'success' => true,
            'challenge_ts' => now()->toIso8601String(),
            'hostname' => (string) config('publisher-applications.turnstile.expected_hostname'),
            'action' => (string) config('publisher-applications.turnstile.action'),
        ];
    }

    private function fail(): never
    {
        throw ValidationException::withMessages([
            'cf-turnstile-response' => 'Security verification failed or expired. Please try again.',
        ]);
    }
}
