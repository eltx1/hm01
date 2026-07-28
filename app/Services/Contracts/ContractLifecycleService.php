<?php

namespace App\Services\Contracts;

use App\Enums\ContractStatus;
use App\Models\PublisherContract;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Validation\ValidationException;

class ContractLifecycleService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function transition(PublisherContract $contract, ContractStatus $status, User $actor, ?string $reason = null): PublisherContract
    {
        $previous = $contract->status;
        if ($previous === $status) {
            return $contract;
        }

        $allowed = [
            ContractStatus::Draft->value => [ContractStatus::Sent, ContractStatus::Terminated],
            ContractStatus::Sent->value => [ContractStatus::Signed, ContractStatus::Draft, ContractStatus::Terminated],
            ContractStatus::Signed->value => [ContractStatus::Active, ContractStatus::Terminated],
            ContractStatus::Active->value => [ContractStatus::Expired, ContractStatus::Terminated],
            ContractStatus::Expired->value => [],
            ContractStatus::Terminated->value => [],
        ];
        if (! in_array($status, $allowed[$previous->value], true)) {
            throw ValidationException::withMessages(['status' => "Invalid contract transition from {$previous->value} to {$status->value}."]);
        }

        $contract->update(['status' => $status]);
        $this->audit->record('publisher_contract.status.changed', $contract->organization_id, $actor, $contract, ['status' => $previous->value], ['status' => $status->value], ['reason' => $reason]);

        return $contract->refresh();
    }
}
