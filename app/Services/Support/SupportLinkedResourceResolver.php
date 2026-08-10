<?php

namespace App\Services\Support;

use App\Enums\SupportLinkedResourceType;
use App\Models\Campaign;
use App\Models\PublisherContract;
use App\Models\PublisherPayment;
use App\Models\PublisherStatement;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class SupportLinkedResourceResolver
{
    /** @return array<class-string<Model>> */
    private function modelMap(): array
    {
        return [
            SupportLinkedResourceType::Site->value => Site::class,
            SupportLinkedResourceType::Contract->value => PublisherContract::class,
            SupportLinkedResourceType::Statement->value => PublisherStatement::class,
            SupportLinkedResourceType::Payment->value => PublisherPayment::class,
            SupportLinkedResourceType::Campaign->value => Campaign::class,
        ];
    }

    public function resolveForOrganization(string $organizationId, ?string $type, ?string $id): ?Model
    {
        if ($type === null && $id === null) {
            return null;
        }
        if (! $type || ! $id || ! array_key_exists($type, $this->modelMap())) {
            throw ValidationException::withMessages(['linked_resource_id' => 'The selected linked resource is invalid.']);
        }

        $model = $this->modelMap()[$type];
        $resource = $model::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->first();
        if (! $resource) {
            throw ValidationException::withMessages(['linked_resource_id' => 'The selected linked resource is not available to this organization.']);
        }

        return $resource;
    }

    /** @return Collection<int, array{type: string, id: string, label: string}> */
    public function optionsForOrganization(string $organizationId): Collection
    {
        return collect()
            ->concat(Site::withoutGlobalScopes()->where('organization_id', $organizationId)->latest()->limit(50)->get()
                ->map(fn (Site $site): array => $this->option(SupportLinkedResourceType::Site, $site, $site->display_name.' · '.$site->primary_domain)))
            ->concat(PublisherContract::withoutGlobalScopes()->where('organization_id', $organizationId)->latest()->limit(50)->get()
                ->map(fn (PublisherContract $contract): array => $this->option(SupportLinkedResourceType::Contract, $contract, $contract->contract_reference)))
            ->concat(PublisherStatement::withoutGlobalScopes()->where('organization_id', $organizationId)->latest()->limit(50)->get()
                ->map(fn (PublisherStatement $statement): array => $this->option(SupportLinkedResourceType::Statement, $statement, $statement->statement_number)))
            ->concat(PublisherPayment::withoutGlobalScopes()->where('organization_id', $organizationId)->latest()->limit(50)->get()
                ->map(fn (PublisherPayment $payment): array => $this->option(SupportLinkedResourceType::Payment, $payment, $payment->payment_number)))
            ->concat(Campaign::withoutGlobalScopes()->where('organization_id', $organizationId)->latest()->limit(50)->get()
                ->map(fn (Campaign $campaign): array => $this->option(SupportLinkedResourceType::Campaign, $campaign, $campaign->name)));
    }

    public function label(?SupportLinkedResourceType $type, ?string $id, string $organizationId): ?string
    {
        if (! $type || ! $id) {
            return null;
        }

        try {
            $resource = $this->resolveForOrganization($organizationId, $type->value, $id);
        } catch (ValidationException) {
            return $type->label().': unavailable historical resource';
        }

        return match ($type) {
            SupportLinkedResourceType::Site => 'Website: '.$resource->display_name,
            SupportLinkedResourceType::Contract => 'Contract: '.$resource->contract_reference,
            SupportLinkedResourceType::Statement => 'Statement: '.$resource->statement_number,
            SupportLinkedResourceType::Payment => 'Payout: '.$resource->payment_number,
            SupportLinkedResourceType::Campaign => 'Campaign: '.$resource->name,
        };
    }

    private function option(SupportLinkedResourceType $type, Model $resource, string $label): array
    {
        return ['type' => $type->value, 'id' => (string) $resource->getKey(), 'label' => $type->label().': '.$label];
    }
}
