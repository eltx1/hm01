<?php

namespace Tests\Fixtures\Gam;

class FakeCreativeService
{
    /** @param ThirdPartyCreative[] $creatives */
    public function createCreatives(array $creatives): array
    {
        return $creatives;
    }
}
