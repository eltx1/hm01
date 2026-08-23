<?php

namespace App\Services\Sites;

use App\Enums\VerificationMethod;
use App\Models\SiteDomain;
use App\Models\SiteVerification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

class DomainVerificationService
{
    public function __construct(private readonly DomainSafetyValidator $safety, private readonly DnsResolver $dns) {}

    public function expectedValue(SiteDomain $domain, VerificationMethod $method): string
    {
        return match ($method) {
            VerificationMethod::AdsTxt => 'The two assigned Horus HMP/HMS DIRECT records',
            VerificationMethod::DnsTxt => 'horus-site-verification='.$domain->verification_token,
            default => $domain->verification_token,
        };
    }

    public function instructions(SiteDomain $domain, VerificationMethod $method): string
    {
        return match ($method) {
            VerificationMethod::AdsTxt => 'Publish the complete Horus ads.txt installation block at https://'.$domain->domain.'/ads.txt',
            VerificationMethod::MetaTag => '<meta name="horus-site-verification" content="'.$domain->verification_token.'">',
            VerificationMethod::TextFile => 'Publish '.$domain->verification_token.' at https://'.$domain->domain.'/.well-known/horus-verification.txt',
            VerificationMethod::DnsTxt => 'Create TXT _horus-verify.'.$domain->domain.' with value '.$this->expectedValue($domain, $method),
            VerificationMethod::Manual => 'A Horus Media administrator must confirm ownership manually.',
        };
    }

    public function verify(SiteDomain $domain, VerificationMethod $method, ?User $actor = null): SiteVerification
    {
        $verification = SiteVerification::create([
            'organization_id' => $domain->organization_id,
            'site_id' => $domain->site_id,
            'site_domain_id' => $domain->id,
            'method' => $method,
            'expected_value' => $this->expectedValue($domain, $method),
            'attempted_at' => now(),
        ]);

        try {
            $verified = match ($method) {
                VerificationMethod::AdsTxt => false,
                VerificationMethod::MetaTag => $this->verifyMetaTag($domain),
                VerificationMethod::TextFile => $this->verifyTextFile($domain),
                VerificationMethod::DnsTxt => $this->verifyDns($domain),
                VerificationMethod::Manual => (bool) $actor?->isHorusAdministrator(),
            };
            $evidence = ['method' => $method->value, 'checked_domain' => $domain->domain];
            $failure = $verified ? null : 'The expected verification value was not found.';
        } catch (Throwable $exception) {
            report($exception);
            $verified = false;
            $evidence = ['method' => $method->value, 'checked_domain' => $domain->domain];
            $failure = 'The verification check could not be completed safely.';
        }

        $verification->update([
            'status' => $verified ? 'VERIFIED' : 'FAILED',
            'verified_by' => $verified && $method === VerificationMethod::Manual ? $actor?->id : null,
            'verified_at' => $verified ? now() : null,
            'evidence' => $evidence,
            'failure_reason' => $failure,
        ]);

        if ($verified) {
            $domain->update(['verification_status' => 'VERIFIED', 'verification_method' => $method, 'verified_at' => now()]);
        }

        return $verification;
    }

    private function verifyMetaTag(SiteDomain $domain): bool
    {
        $body = $this->fetch($domain->domain, '/');
        $token = preg_quote($domain->verification_token, '#');

        return (bool) preg_match('#<meta\s+[^>]*name=["\']horus-site-verification["\'][^>]*content=["\']'.$token.'["\'][^>]*>#i', $body)
            || (bool) preg_match('#<meta\s+[^>]*content=["\']'.$token.'["\'][^>]*name=["\']horus-site-verification["\'][^>]*>#i', $body);
    }

    private function verifyTextFile(SiteDomain $domain): bool
    {
        return hash_equals($domain->verification_token, trim($this->fetch($domain->domain, '/.well-known/horus-verification.txt')));
    }

    private function verifyDns(SiteDomain $domain): bool
    {
        return in_array($this->expectedValue($domain, VerificationMethod::DnsTxt), $this->dns->textRecords('_horus-verify.'.$domain->domain), true);
    }

    private function fetch(string $domain, string $path): string
    {
        $addresses = $this->safety->assertSafe($domain);

        foreach (['https', 'http'] as $scheme) {
            $port = $scheme === 'https' ? 443 : 80;
            $options = defined('CURLOPT_RESOLVE') ? ['curl' => [CURLOPT_RESOLVE => array_map(function (string $ip) use ($domain, $port): string {
                $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;

                return "{$domain}:{$port}:{$address}";
            }, $addresses)]] : [];
            $response = Http::timeout(8)->connectTimeout(4)->withoutRedirecting()->withOptions(array_merge($options, ['stream' => true]))->get($scheme.'://'.$domain.$path);
            if ($response->successful()) {
                $body = $response->toPsrResponse()->getBody()->read(1_000_001);
                if (strlen($body) <= 1_000_000) {
                    return $body;
                }
            }
        }

        return '';
    }
}
