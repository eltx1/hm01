<?php

namespace App\Services\Contracts;

use App\Enums\ContractStatus;
use App\Models\PublisherContract;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Reporting\RevenueRuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractLifecycleService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly RevenueRuleService $revenueRules,
    ) {}

    public function transition(PublisherContract $contract, ContractStatus $status, User $actor, ?string $reason = null): PublisherContract
    {
        $previous = $contract->status;
        if ($previous === $status) {
            return $contract;
        }

        if (! in_array($status, $this->allowedTransitions($previous), true)) {
            throw ValidationException::withMessages(['status' => "Invalid contract transition from {$previous->value} to {$status->value}."]);
        }

        DB::transaction(function () use ($contract, $status, $actor, $previous, $reason): void {
            if ($status === ContractStatus::Active) {
                $previouslyActive = PublisherContract::withoutGlobalScopes()
                    ->where('publisher_id', $contract->publisher_id)
                    ->where('id', '!=', $contract->id)
                    ->where('status', ContractStatus::Active->value)
                    ->get();
                foreach ($previouslyActive as $activeContract) {
                    $activeContract->update(['status' => ContractStatus::Expired]);
                    $this->audit->record('publisher_contract.status.changed', $activeContract->organization_id, $actor, $activeContract,
                        ['status' => ContractStatus::Active->value], ['status' => ContractStatus::Expired->value],
                        ['reason' => "Replaced by commercial terms {$contract->contract_reference}"]);
                }
            }

            $contract->update(['status' => $status]);
            $this->audit->record('publisher_contract.status.changed', $contract->organization_id, $actor, $contract, ['status' => $previous->value], ['status' => $status->value], ['reason' => $reason]);

            if ($status === ContractStatus::Active) {
                $this->revenueRules->syncPublisherCommercialTerms($contract, $actor);
            } elseif ($previous === ContractStatus::Active) {
                $this->revenueRules->disablePublisherCommercialTerms($contract, $actor);
            }
        });

        return $contract->refresh();
    }

    /** @return list<ContractStatus> */
    public function allowedTransitions(ContractStatus $status): array
    {
        return match ($status) {
            ContractStatus::Draft => [ContractStatus::Active, ContractStatus::Terminated],
            ContractStatus::Sent => [ContractStatus::Active, ContractStatus::Draft, ContractStatus::Terminated],
            ContractStatus::Signed => [ContractStatus::Active, ContractStatus::Terminated],
            ContractStatus::Active => [ContractStatus::Expired, ContractStatus::Terminated],
            ContractStatus::Expired, ContractStatus::Terminated => [],
        };
    }

    public function syncActiveTerms(PublisherContract $contract, User $actor): void
    {
        if ($contract->status === ContractStatus::Active) {
            $this->revenueRules->syncPublisherCommercialTerms($contract, $actor);
        }
    }
}
