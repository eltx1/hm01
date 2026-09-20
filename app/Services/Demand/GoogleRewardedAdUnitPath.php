<?php

namespace App\Services\Demand;

final class GoogleRewardedAdUnitPath
{
    public function parse(string $input): ?string
    {
        return (new GoogleAdUnitPath())->parse($input);
    }
}
