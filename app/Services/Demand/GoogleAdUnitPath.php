<?php

namespace App\Services\Demand;

use RuntimeException;

final class GoogleAdUnitPath
{
    /** Return null for provider markup and URLs; reject malformed path input. */
    public function parse(string $input): ?string
    {
        $value = trim($input);
        if (! str_starts_with($value, '/') || str_starts_with($value, '//')) return null;
        if (strlen($value) > 280 || ! preg_match('#^/[0-9]{1,20}(?:,[0-9]{1,20})?/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#D', $value)) {
            throw new RuntimeException('Enter a GAM ad unit path such as /1234567/ad_unit. Do not include a URL, query parameters, or provider code.');
        }

        return $value;
    }
}
