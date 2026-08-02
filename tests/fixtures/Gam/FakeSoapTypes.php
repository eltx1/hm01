<?php

namespace Tests\Fixtures\Gam;

class Money
{
    public mixed $currencyCode = null;
    public mixed $microAmount = null;

    /** @param string $currencyCode */
    public function setCurrencyCode($currencyCode): self { $this->currencyCode = $currencyCode; return $this; }
    /** @param int $microAmount */
    public function setMicroAmount($microAmount): self { $this->microAmount = $microAmount; return $this; }
}

class ThirdPartyCreative
{
    public mixed $name = null;
    public mixed $costPerUnit = null;

    /** @param string $name */
    public function setName($name): self { $this->name = $name; return $this; }
    /** @param \Tests\Fixtures\Gam\Money $costPerUnit */
    public function setCostPerUnit($costPerUnit): self { $this->costPerUnit = $costPerUnit; return $this; }
}

class FakeCreativeService
{
    /** @param \Tests\Fixtures\Gam\ThirdPartyCreative[] $creatives */
    public function createCreatives(array $creatives): array { return $creatives; }
}
