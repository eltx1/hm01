<?php

namespace App\Services\SupplyChain;

final class SupplyChainProductionReadinessService
{
    public function __construct(
        private readonly SupplyChainCrossConsistencyValidator $consistency,
        private readonly SupplyChainPublicOriginVerifier $origins,
        private readonly SupplyChainStandardsContract $contract,
    ) {}

    /** @return array{status: string, findings: array<int, array<string, string>>, sellers_json_origin: array<string, mixed>} */
    public function forSite(\App\Models\Site $site): array
    {
        $cross = $this->consistency->validateSite($site);
        $origin = $this->origins->readiness();
        $findings = collect($cross['findings'] ?? []);
        if (! $origin['verified']) {
            $findings->push([
                'code' => 'HORUS_SELLERS_JSON_PUBLIC_ORIGIN_UNVERIFIED',
                'severity' => 'ERROR',
                'message' => 'The canonical advertising-system origin '.config('supply-chain.canonical_sellers_json_url').' has not been verified with the current generated sellers.json payload.',
            ]);
        }

        return [
            'status' => $findings->contains(fn (array $finding): bool => ($finding['severity'] ?? null) === 'ERROR') ? 'BLOCKED' : 'READY',
            'findings' => $findings->values()->all(),
            'sellers_json_origin' => $origin,
        ];
    }
}
