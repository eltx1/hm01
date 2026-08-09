<?php

namespace App\Services\Network\Contracts;

interface DnsResolver
{
    /** @return list<string> */
    public function addresses(string $host): array;
}
