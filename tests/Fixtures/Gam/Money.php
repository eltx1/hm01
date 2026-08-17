<?php

namespace Tests\Fixtures\Gam;

class Money
{
    public mixed $currencyCode = null;
    public mixed $microAmount = null;

    /** @param string $currencyCode */
    public function setCurrencyCode($currencyCode): self
    {
        $this->currencyCode = $currencyCode;

        return $this;
    }

    /** @param int $microAmount */
    public function setMicroAmount($microAmount): self
    {
        $this->microAmount = $microAmount;

        return $this;
    }
}
