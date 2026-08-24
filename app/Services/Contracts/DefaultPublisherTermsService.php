<?php

namespace App\Services\Contracts;

use App\Enums\ContractStatus;
use App\Models\Publisher;
use App\Models\PublisherContract;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Reporting\RevenueRuleService;
use Illuminate\Support\Facades\DB;

final class DefaultPublisherTermsService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly RevenueRuleService $revenueRules,
    ) {}

    /**
     * Provision the open-ended registration terms once. Existing terms are never
     * overwritten so a retry cannot undo a later administrator edit.
     */
    public function ensure(Publisher $publisher, User $actor): PublisherContract
    {
        return DB::transaction(function () use ($publisher, $actor): PublisherContract {
            $existing = PublisherContract::withoutGlobalScopes()
                ->where('publisher_id', $publisher->id)
                ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")
                ->oldest()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->status === ContractStatus::Active) {
                    $this->revenueRules->ensurePublisherRegistrationTerms($existing);
                }

                return $existing;
            }

            $contract = PublisherContract::withoutGlobalScopes()->create([
                'organization_id' => $publisher->organization_id,
                'publisher_id' => $publisher->id,
                'contract_reference' => 'HM-'.now()->format('Y').'-001',
                'starts_at' => today(),
                'ends_at' => null,
                'auto_renews' => false,
                'revenue_share_percent' => 70,
                'payment_threshold' => 100,
                'currency' => 'USD',
                'payment_terms' => 'NET_30',
                'status' => ContractStatus::Active,
                'internal_notes' => 'Default open-ended commercial terms provisioned at Publisher registration.',
                'created_by' => $actor->id,
            ]);

            $this->audit->record('publisher_contract.default_provisioned', $publisher->organization_id, $actor, $contract, newValues: [
                'contract_reference' => $contract->contract_reference,
                'status' => ContractStatus::Active->value,
                'revenue_share_percent' => '70.00',
                'ends_at' => null,
            ]);
            $this->revenueRules->ensurePublisherRegistrationTerms($contract);

            return $contract->refresh();
        });
    }
}
