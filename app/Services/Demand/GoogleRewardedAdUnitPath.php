<?php

namespace App\Services\Demand;

use RuntimeException;

final class GoogleRewardedAdUnitPath
{
    public function parse(string $input): ?string
    {
        $value = trim($input);
        if (! str_starts_with($value, '/') || str_starts_with($value, '//')) return null;
        if (! preg_match('#^/[0-9]{1,20}(?:,[0-9]{1,20})?/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#D', $value) || strlen($value) > 280) {
            throw new RuntimeException('Enter a GAM rewarded ad unit path such as /1234567/rewarded. Do not paste a VAST URL into an ad unit path.');
        }
        return $value;
    }
}
