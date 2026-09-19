<?php

namespace App\Services\Demand;

use App\Services\Security\PublicProviderOriginValidator;
use RuntimeException;

final class VastTagUrlParser
{
    public function __construct(
        private readonly PublicProviderOriginValidator $originValidator,
    ) {}

    /**
     * Return a normalized public VAST/VMAP ad-tag URL when the entire input is
     * a URL. Markup and provider JavaScript deliberately remain on the existing
     * isolated-tag path.
     *
     * @return array{url:string,origin:string}|null
     */
    public function parse(string $input): ?array
    {
        $value = html_entity_decode(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($value === '' || str_contains($value, '<') || preg_match('/\s/u', $value)) {
            return null;
        }

        if (! preg_match('#^https?://#i', $value)) {
            return null;
        }
        if (strlen($value) > 10_000) {
            throw new RuntimeException('The VAST ad tag URL exceeds the supported length.');
        }
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('The VAST ad tag must be a valid absolute URL.');
        }
        if (strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('The VAST ad tag must use HTTPS.');
        }
        if (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
            throw new RuntimeException('The VAST ad tag cannot contain URL credentials.');
        }

        $origin = $this->originValidator->canonicalOrigin($value);
        if ($origin === null) {
            $host = $this->originValidator->normalizeHost((string) parse_url($value, PHP_URL_HOST));
            throw new RuntimeException("VAST host [{$host}] is private, reserved, unresolved, control-plane, or otherwise unsafe for publisher delivery.");
        }

        return ['url' => $value, 'origin' => $origin];
    }
}
