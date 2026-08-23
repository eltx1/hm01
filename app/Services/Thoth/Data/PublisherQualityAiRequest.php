<?php

namespace App\Services\Thoth\Data;

final readonly class PublisherQualityAiRequest
{
    public function __construct(
        public array $evidence,
        public string $policyVersion,
        public string $schemaVersion,
        public ?string $runId = null,
        public ?string $publisherId = null,
        public ?string $siteId = null,
    ) {}

    public function toArray(): array
    {
        $siteId = $this->siteId ?? data_get($this->evidence, 'site.id');

        return [
            'task' => $siteId ? 'site_quality_advisory' : 'publisher_quality_advisory',
            'review_run_id' => $this->runId,
            'publisher_id' => $this->publisherId,
            'site_id' => $siteId,
            'policy_version' => $this->policyVersion,
            'schema_version' => $this->schemaVersion,
            'evidence' => $this->evidence,
        ];
    }
}
