<?php

namespace App\Services\SupplyChain\Data;

final readonly class CanonicalAdsTxtSource
{
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $advertisingSystemDomain,
        public string $publisherAccountId,
        public string $relationship,
        public ?string $certificationAuthorityId,
        public string $line,
        public string $sortKey,
        public mixed $record = null,
        public mixed $declaration = null,
        public array $metadata = [],
    ) {}

    public function identityKey(): string
    {
        return strtolower($this->advertisingSystemDomain)."\0".$this->publisherAccountId;
    }

    public function provenance(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'metadata' => $this->metadata,
        ];
    }
}
