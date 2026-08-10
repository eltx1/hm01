<?php

namespace App\Services\Settings;

final readonly class SettingDefinition
{
    /** @param array<int, string> $rules @param array<int, string> $allowedValues */
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public string $type,
        public string $configPath,
        public array $rules,
        public array $allowedValues,
        public string $description,
        public string $sensitivity = 'SAFE',
        public bool $runtimeEditable = true,
        public bool $highImpact = false,
        public ?string $impact = null,
    ) {}
}
