<?php

namespace Tests\Fixtures\Gam;

class ThirdPartyCreative
{
    public mixed $name = null;
    public mixed $costPerUnit = null;

    /** @param string $name */
    public function setName($name): self
    {
        $this->name = $name;

        return $this;
    }

    /** @param Money $costPerUnit */
    public function setCostPerUnit($costPerUnit): self
    {
        $this->costPerUnit = $costPerUnit;

        return $this;
    }
}
