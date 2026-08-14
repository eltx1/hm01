<?php

namespace App\Support\Branding;

final readonly class BrandIdentity
{
    public function __construct(
        public string $name,
        public string $descriptor,
        public ?string $logoUrl,
        public string $logoAlt,
        public bool $usesTenantLogo,
    ) {}
}
