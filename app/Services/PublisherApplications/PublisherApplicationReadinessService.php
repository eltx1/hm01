<?php

namespace App\Services\PublisherApplications;

use Illuminate\Validation\ValidationException;

final class PublisherApplicationReadinessService
{
    public const READY = 'READY';
    public const BLOCKED = 'BLOCKED';

    /** @return array{status: string, reasons: list<string>} */
    public function state(): array
    {
        $reasons = array_values(array_unique(array_merge(
            $this->legalReasons(),
            $this->turnstileReasons(),
        )));

        return [
            'status' => $reasons === [] ? self::READY : self::BLOCKED,
            'reasons' => $reasons,
        ];
    }

    public function isReady(): bool
    {
        return $this->state()['status'] === self::READY;
    }

    public function assertReady(): void
    {
        if ($this->isReady()) {
            return;
        }

        throw ValidationException::withMessages([
            'publisher_application' => 'Publisher applications are temporarily unavailable. Please try again later or contact Horus Media support.',
        ]);
    }

    /** @return list<string> */
    private function legalReasons(): array
    {
        $registry = config('publisher-applications.legal_documents', []);
        if (! is_array($registry)) {
            return ['LEGAL_DOCUMENT_REGISTRY_INVALID'];
        }

        $reasons = [];
        foreach ($registry as $type => $document) {
            $prefix = $this->legalReasonPrefix((string) $type);
            if (! is_array($document)) {
                $reasons[] = $prefix.'_CONFIGURATION_INVALID';
                continue;
            }
            if (! (bool) ($document['required'] ?? false)) {
                continue;
            }

            $version = trim((string) ($document['version'] ?? ''));
            $url = trim((string) ($document['url'] ?? ''));
            if ($version === '') {
                $reasons[] = $prefix.'_VERSION_MISSING';
            }
            if ($url === '') {
                $reasons[] = $prefix.'_URL_MISSING';
            } elseif (! $this->isCanonicalHttpUrl($url)) {
                $reasons[] = $prefix.'_URL_INVALID';
            }
        }

        return $reasons;
    }

    /** @return list<string> */
    private function turnstileReasons(): array
    {
        if (! app()->environment('production') || ! (bool) config('publisher-applications.turnstile.enabled', false)) {
            return [];
        }

        $reasons = [];
        if (strtolower(trim((string) config('publisher-applications.turnstile.provider'))) !== 'cloudflare') {
            $reasons[] = 'TURNSTILE_PROVIDER_INVALID';
        }
        foreach ([
            'site_key' => 'TURNSTILE_SITE_KEY_MISSING',
            'secret_key' => 'TURNSTILE_SECRET_KEY_MISSING',
            'expected_hostname' => 'TURNSTILE_EXPECTED_HOSTNAME_MISSING',
            'action' => 'TURNSTILE_ACTION_MISSING',
        ] as $key => $reason) {
            if (trim((string) config('publisher-applications.turnstile.'.$key)) === '') {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    private function legalReasonPrefix(string $type): string
    {
        return match (strtoupper($type)) {
            'TERMS_OF_SERVICE' => 'LEGAL_TERMS',
            'PRIVACY_POLICY' => 'LEGAL_PRIVACY',
            'PUBLISHER_TERMS' => 'LEGAL_PUBLISHER_TERMS',
            default => 'LEGAL_'.trim((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper($type)), '_'),
        };
    }

    private function isCanonicalHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && trim((string) parse_url($url, PHP_URL_HOST)) !== '';
    }
}
