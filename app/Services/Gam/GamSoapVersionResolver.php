<?php

namespace App\Services\Gam;

use App\Services\Gam\Exceptions\GamTransportException;

final class GamSoapVersionResolver
{
    public function resolve(): string
    {
        $override = trim((string) config('gam.soap.version_override', ''));
        if ($override !== '') {
            $this->assertAvailable($override);

            return $override;
        }

        $versions = $this->installedVersions();
        if ($versions === []) {
            throw new GamTransportException(
                'No generated Google Ad Manager SOAP API version is installed.',
                'SOAP_LIBRARY_VERSION_MISSING',
            );
        }

        return $versions[0];
    }

    /** @return list<string> */
    public function installedVersions(): array
    {
        $root = base_path('vendor/googleads/googleads-php-lib/src/Google/AdsApi/AdManager');
        $versions = [];
        foreach (glob($root.'/v*', GLOB_ONLYDIR) ?: [] as $directory) {
            $version = basename($directory);
            if (preg_match('/^v\d{6}$/', $version) === 1
                && class_exists("Google\\AdsApi\\AdManager\\{$version}\\ServiceFactory")) {
                $versions[] = $version;
            }
        }
        rsort($versions, SORT_STRING);

        return $versions;
    }

    public function namespaceFor(string $version): string
    {
        return "Google\\AdsApi\\AdManager\\{$version}";
    }

    private function assertAvailable(string $version): void
    {
        if (preg_match('/^v\d{6}$/', $version) !== 1
            || ! class_exists($this->namespaceFor($version).'\\ServiceFactory')) {
            throw new GamTransportException(
                "The configured GAM SOAP fallback version {$version} is not installed.",
                'SOAP_VERSION_UNAVAILABLE',
            );
        }
    }
}
