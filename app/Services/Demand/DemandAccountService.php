<?php

namespace App\Services\Demand;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandSyncStatus;
use App\Enums\OrganizationType;
use App\Models\DemandAccount;
use App\Models\DemandAccountCredential;
use App\Models\DemandError;
use App\Models\DemandPlacement;
use App\Models\DemandRemoteObject;
use App\Models\DemandSite;
use App\Models\DemandSyncLog;
use App\Models\DemandWidget;
use App\Models\Organization;
use App\Models\Placement;
use App\Models\Publisher;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Demand\Data\DemandResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DemandAccountService
{
    public function __construct(
        private readonly DemandConnectorManager $connectors,
        private readonly DemandCredentialReferenceValidator $credentialReferences,
        private readonly DemandPayloadSanitizer $sanitizer,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function create(array $attributes, User $actor): DemandAccount
    {
        return DB::transaction(function () use ($attributes, $actor): DemandAccount {
            $scope = $attributes['scope'] instanceof DemandAccountScope
                ? $attributes['scope']
                : DemandAccountScope::from((string) $attributes['scope']);
            $publisher = null;
            $partner = null;
            if ($scope === DemandAccountScope::Publisher) {
                if (empty($attributes['publisher_id'])) {
                    throw ValidationException::withMessages(['publisher_id' => 'Publisher scope requires a publisher assignment.']);
                }
                $publisher = Publisher::withoutGlobalScopes()->findOrFail($attributes['publisher_id']);
            }
            if ($scope === DemandAccountScope::McmPartner) {
                if (empty($attributes['partner_organization_id'])) {
                    throw ValidationException::withMessages(['partner_organization_id' => 'MCM partner scope requires a partner organization.']);
                }
                $partner = Organization::withoutGlobalScopes()->findOrFail($attributes['partner_organization_id']);
                if ($partner->type !== OrganizationType::Partner) {
                    throw ValidationException::withMessages(['partner_organization_id' => 'The selected organization is not an MCM partner.']);
                }
            }

            $organizationId = $attributes['organization_id'] ?? null;
            $organizationId ??= $publisher?->organization_id;
            $organizationId ??= $partner?->id;
            $organizationId ??= $actor->organization_id;
            if ($publisher && $organizationId !== $publisher->organization_id) {
                throw ValidationException::withMessages(['organization_id' => 'Publisher-scoped accounts must belong to the publisher organization.']);
            }
            if ($partner && $organizationId !== $partner->id) {
                throw ValidationException::withMessages(['organization_id' => 'MCM-partner accounts must belong to the selected partner organization.']);
            }

            $attributes['publisher_id'] = $scope === DemandAccountScope::Publisher ? $publisher?->id : null;
            $attributes['partner_organization_id'] = $scope === DemandAccountScope::McmPartner ? $partner?->id : null;

            $account = DemandAccount::withoutGlobalScopes()->create(array_merge($attributes, [
                'organization_id' => $organizationId,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]));

            $errors = $this->connectors->for($account->fresh('network'))->validateConfiguration();
            if ($errors) {
                throw ValidationException::withMessages(['configuration' => $errors]);
            }

            if ($account->is_default) {
                DemandAccount::withoutGlobalScopes()
                    ->where('organization_id', $account->organization_id)
                    ->where('demand_network_id', $account->demand_network_id)
                    ->whereKeyNot($account->id)
                    ->update(['is_default' => false]);
            }

            $this->audit->record('demand.account.created', $account->organization_id, $actor, $account, newValues: [
                'network' => $account->network->code->value,
                'scope' => $account->scope->value,
                'integration_mode' => $account->integration_mode->value,
            ]);

            return $account;
        });
    }

    public function update(DemandAccount $account, array $attributes, User $actor): DemandAccount
    {
        return DB::transaction(function () use ($account, $attributes, $actor): DemandAccount {
            $scope = ($attributes['scope'] ?? $account->scope) instanceof DemandAccountScope
                ? ($attributes['scope'] ?? $account->scope)
                : DemandAccountScope::from((string) ($attributes['scope'] ?? $account->scope->value));
            if ($scope !== $account->scope) {
                throw ValidationException::withMessages(['scope' => 'Account scope is immutable. Create a separate scoped account to preserve reporting and remote mappings.']);
            }
            if ($scope === DemandAccountScope::Publisher && ! ($attributes['publisher_id'] ?? $account->publisher_id)) {
                throw ValidationException::withMessages(['publisher_id' => 'Publisher scope requires a publisher assignment.']);
            }
            if ($scope === DemandAccountScope::McmPartner && ! ($attributes['partner_organization_id'] ?? $account->partner_organization_id)) {
                throw ValidationException::withMessages(['partner_organization_id' => 'MCM partner scope requires a partner organization.']);
            }
            if ($scope === DemandAccountScope::McmPartner) {
                $partnerId = (string) ($attributes['partner_organization_id'] ?? $account->partner_organization_id);
                $partner = Organization::withoutGlobalScopes()->findOrFail($partnerId);
                if ($partner->type !== OrganizationType::Partner) {
                    throw ValidationException::withMessages(['partner_organization_id' => 'The selected organization is not an MCM partner.']);
                }
            }

            $before = $account->only([
                'name', 'scope', 'integration_mode', 'approval_status', 'is_enabled',
                'is_default', 'revenue_share_percent', 'fallback_priority',
                'account_identifier', 'configuration',
            ]);
            $account->update($attributes + ['updated_by' => $actor->id]);
            $errors = $this->connectors->for($account->fresh('network'))->validateConfiguration();
            if ($errors) {
                throw ValidationException::withMessages(['configuration' => $errors]);
            }

            if ($account->is_default) {
                DemandAccount::withoutGlobalScopes()
                    ->where('organization_id', $account->organization_id)
                    ->where('demand_network_id', $account->demand_network_id)
                    ->whereKeyNot($account->id)
                    ->update(['is_default' => false]);
            }

            $this->audit->record('demand.account.updated', $account->organization_id, $actor, $account, $before, $account->only(array_keys($before)));

            return $account->refresh();
        });
    }

    public function upsertCredential(DemandAccount $account, array $attributes, User $actor): DemandAccountCredential
    {
        $this->credentialReferences->validate((string) $attributes['reference']);

        $credential = DemandAccountCredential::withoutGlobalScopes()->updateOrCreate(
            [
                'demand_account_id' => $account->id,
                'credential_key' => $attributes['credential_key'],
            ],
            [
                'organization_id' => $account->organization_id,
                'reference' => $attributes['reference'],
                'hint' => $attributes['hint'] ?? null,
                'capability' => $attributes['capability'] ?? 'API',
                'expires_at' => $attributes['expires_at'] ?? null,
                'rotated_at' => now(),
                'metadata' => $attributes['metadata'] ?? null,
            ],
        );

        $this->audit->record('demand.credential.updated', $account->organization_id, $actor, $credential, newValues: [
            'credential_key' => $credential->credential_key,
            'reference' => '[REDACTED]',
            'capability' => $credential->capability,
        ]);

        return $credential;
    }

    public function test(DemandAccount $account, User $actor, bool $dryRun = false): DemandResult
    {
        $result = $this->connectors->for($account)->testConnection(['dry_run' => $dryRun]);
        $account->update([
            'last_tested_at' => now(),
            'last_successful_sync_at' => $result->success && ! $result->dryRun ? now() : $account->last_successful_sync_at,
            'updated_by' => $actor->id,
        ]);
        $this->log($account, null, null, 'ACCOUNT_TEST', $result, $dryRun);
        $this->audit->record('demand.account.tested', $account->organization_id, $actor, $account, newValues: [
            'success' => $result->success,
            'dry_run' => $result->dryRun,
            'error_code' => $result->errorCode,
        ]);

        return $result;
    }

    public function reviewAccount(
        DemandAccount $account,
        DemandApprovalStatus $status,
        User $actor,
        ?string $reason = null,
    ): DemandAccount {
        $before = $account->approval_status;
        $account->update([
            'approval_status' => $status,
            'approved_at' => $status === DemandApprovalStatus::Approved ? now() : null,
            'rejection_reason' => in_array($status, [DemandApprovalStatus::Rejected, DemandApprovalStatus::Suspended], true) ? $reason : null,
            'updated_by' => $actor->id,
        ]);

        $this->audit->record('demand.account.reviewed', $account->organization_id, $actor, $account, [
            'approval_status' => $before->value,
        ], [
            'approval_status' => $status->value,
            'reason' => $reason,
        ]);

        return $account->refresh();
    }

    public function assignSite(DemandAccount $account, Site $site, array $attributes, User $actor): DemandSite
    {
        if ($account->scope->value === 'PUBLISHER' && $account->publisher_id && $account->publisher_id !== $site->publisher_id) {
            throw ValidationException::withMessages(['site_id' => 'A publisher-scoped demand account may only be assigned to that publisher’s websites.']);
        }

        $demandSite = DemandSite::withoutGlobalScopes()->updateOrCreate(
            ['demand_account_id' => $account->id, 'site_id' => $site->id],
            [
                'organization_id' => $site->organization_id,
                'approval_status' => $attributes['approval_status'] ?? DemandApprovalStatus::NotSubmitted,
                'is_enabled' => $attributes['is_enabled'] ?? true,
                'is_default' => $attributes['is_default'] ?? false,
                'integration_mode' => $attributes['integration_mode'] ?? null,
                'revenue_share_percent' => $attributes['revenue_share_percent'] ?? null,
                'fallback_priority' => $attributes['fallback_priority'] ?? null,
                'remote_site_id' => $attributes['remote_site_id'] ?? null,
                'configuration' => $attributes['configuration'] ?? null,
                'sync_status' => DemandSyncStatus::Pending,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ],
        );

        if ($demandSite->is_default) {
            DemandSite::withoutGlobalScopes()
                ->where('site_id', $site->id)
                ->whereKeyNot($demandSite->id)
                ->update(['is_default' => false]);
        }

        $this->audit->record('demand.site.assigned', $site->organization_id, $actor, $demandSite, newValues: [
            'account_id' => $account->id,
            'site_id' => $site->id,
            'approval_status' => $demandSite->approval_status->value,
            'is_enabled' => $demandSite->is_enabled,
        ]);

        return $demandSite->refresh();
    }

    public function assignPlacement(DemandSite $demandSite, Placement $placement, array $attributes, User $actor): DemandPlacement
    {
        if ($placement->site_id !== $demandSite->site_id) {
            throw ValidationException::withMessages(['placement_id' => 'The placement does not belong to the selected website.']);
        }

        $mapping = DemandPlacement::withoutGlobalScopes()->updateOrCreate(
            ['demand_site_id' => $demandSite->id, 'placement_id' => $placement->id],
            [
                'organization_id' => $demandSite->organization_id,
                'approval_status' => $attributes['approval_status'] ?? DemandApprovalStatus::NotSubmitted,
                'is_enabled' => $attributes['is_enabled'] ?? true,
                'integration_mode' => $attributes['integration_mode'] ?? null,
                'fallback_priority' => $attributes['fallback_priority'] ?? null,
                'remote_placement_id' => $attributes['remote_placement_id'] ?? null,
                'placement_code' => $attributes['placement_code'] ?? null,
                'configuration' => $attributes['configuration'] ?? null,
                'sync_status' => DemandSyncStatus::Pending,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ],
        );

        $this->audit->record('demand.placement.assigned', $mapping->organization_id, $actor, $mapping, newValues: [
            'demand_site_id' => $demandSite->id,
            'placement_id' => $placement->id,
            'approval_status' => $mapping->approval_status->value,
        ]);

        return $mapping->refresh();
    }

    public function upsertWidget(DemandPlacement $placement, array $attributes, User $actor): DemandWidget
    {
        $identity = ['demand_placement_id' => $placement->id];
        if (! empty($attributes['remote_widget_id'])) {
            $identity['remote_widget_id'] = $attributes['remote_widget_id'];
        } else {
            $identity['name'] = $attributes['name'];
        }

        $widget = DemandWidget::withoutGlobalScopes()->updateOrCreate(
            $identity,
            [
                'organization_id' => $placement->organization_id,
                'name' => $attributes['name'],
                'widget_code' => $attributes['widget_code'] ?? null,
                'integration_mode' => $attributes['integration_mode'] ?? null,
                'direct_tag_template' => $attributes['direct_tag_template'] ?? null,
                'gam_creative_template' => $attributes['gam_creative_template'] ?? null,
                'approval_status' => $attributes['approval_status'] ?? DemandApprovalStatus::NotSubmitted,
                'is_enabled' => $attributes['is_enabled'] ?? true,
                'configuration' => $attributes['configuration'] ?? null,
            ],
        );

        $this->audit->record('demand.widget.updated', $widget->organization_id, $actor, $widget, newValues: [
            'name' => $widget->name,
            'approval_status' => $widget->approval_status->value,
            'is_enabled' => $widget->is_enabled,
        ]);

        return $widget->refresh();
    }

    public function reviewSite(DemandSite $site, DemandApprovalStatus $status, User $actor): DemandSite
    {
        $site->update(['approval_status' => $status, 'updated_by' => $actor->id]);
        $this->audit->record('demand.site.reviewed', $site->organization_id, $actor, $site, newValues: ['approval_status' => $status->value]);

        return $site->refresh();
    }

    public function reviewPlacement(DemandPlacement $placement, DemandApprovalStatus $status, User $actor): DemandPlacement
    {
        $placement->update(['approval_status' => $status, 'updated_by' => $actor->id]);
        $this->audit->record('demand.placement.reviewed', $placement->organization_id, $actor, $placement, newValues: ['approval_status' => $status->value]);

        return $placement->refresh();
    }

    public function refreshSiteStatus(DemandSite $site, User $actor): DemandResult
    {
        $site->loadMissing(['account.network', 'site']);
        $result = $this->connectors->for($site->account)->getSiteStatus($site);
        if ($result->success) {
            $status = $this->approvalStatus(data_get($result->data, 'approval_status') ?? data_get($result->data, 'status'));
            $remoteId = data_get($result->data, 'id') ?? data_get($result->data, 'site_id') ?? data_get($result->data, 'remote_site_id');
            $site->update([
                'approval_status' => $status ?? $site->approval_status,
                'remote_site_id' => is_scalar($remoteId) && (string) $remoteId !== '' ? (string) $remoteId : $site->remote_site_id,
                'last_synced_at' => now(),
                'updated_by' => $actor->id,
            ]);
        }

        $this->log($site->account, $site, null, 'SITE_STATUS', $result, false);
        $this->audit->record('demand.site.status_synchronized', $site->organization_id, $actor, $site, newValues: [
            'success' => $result->success,
            'approval_status' => $site->fresh()->approval_status->value,
            'remote_site_id' => $site->fresh()->remote_site_id,
        ]);

        return $result;
    }

    public function syncSite(DemandSite $site, User $actor, bool $dryRun = true): DemandResult
    {
        $site->loadMissing(['account.network', 'site']);
        $idempotencyKey = hash('sha256', $site->demand_account_id.'|provider_site|'.$site->id);
        $result = $this->connectors->for($site->account)->createSite($site, [
            'dry_run' => $dryRun,
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($result->success && ! $result->dryRun) {
            $remoteId = data_get($result->data, 'id') ?? data_get($result->data, 'site_id');
            $site->update([
                'remote_site_id' => $remoteId ?: $site->remote_site_id,
                'sync_status' => DemandSyncStatus::InSync,
                'last_synced_at' => now(),
                'updated_by' => $actor->id,
            ]);
            if ($remoteId) {
                $this->providerMapping($site->account, 'demand_site', $site->id, 'site', (string) $remoteId, $idempotencyKey, [
                    'demand_site_id' => $site->id,
                ]);
            }
        } elseif (! $result->success) {
            $site->update(['sync_status' => DemandSyncStatus::Failed, 'updated_by' => $actor->id]);
        }

        $this->log($site->account, $site, null, 'SITE_SYNC', $result, $dryRun);
        $this->audit->record('demand.site.synchronized', $site->organization_id, $actor, $site, newValues: [
            'success' => $result->success,
            'dry_run' => $result->dryRun,
            'remote_site_id' => $site->remote_site_id,
        ]);

        return $result;
    }

    public function syncPlacement(DemandPlacement $placement, User $actor, bool $dryRun = true): DemandResult
    {
        $placement->loadMissing(['demandSite.account.network', 'demandSite.site', 'placement.sizes']);
        $idempotencyKey = hash('sha256', $placement->demandSite->demand_account_id.'|provider_placement|'.$placement->id);
        $result = $this->connectors->for($placement->demandSite->account)->createPlacement($placement, [
            'dry_run' => $dryRun,
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($result->success && ! $result->dryRun) {
            $remoteId = data_get($result->data, 'id') ?? data_get($result->data, 'placement_id');
            $placement->update([
                'remote_placement_id' => $remoteId ?: $placement->remote_placement_id,
                'sync_status' => DemandSyncStatus::InSync,
                'last_synced_at' => now(),
                'updated_by' => $actor->id,
            ]);
            $codeResult = $this->connectors->for($placement->demandSite->account)->getPlacementCode($placement->fresh());
            $placementCode = data_get($codeResult->data, 'code') ?? data_get($codeResult->data, 'placement_code');
            if ($codeResult->success && is_scalar($placementCode) && (string) $placementCode !== '') {
                $placement->update(['placement_code' => (string) $placementCode]);
            }
            if ($remoteId) {
                $this->providerMapping($placement->demandSite->account, 'demand_placement', $placement->id, 'placement', (string) $remoteId, $idempotencyKey, [
                    'demand_site_id' => $placement->demand_site_id,
                    'placement_id' => $placement->placement_id,
                ]);
            }
        } elseif (! $result->success) {
            $placement->update(['sync_status' => DemandSyncStatus::Failed, 'updated_by' => $actor->id]);
        }

        $this->log($placement->demandSite->account, $placement->demandSite, $placement, 'PLACEMENT_SYNC', $result, $dryRun);
        $this->audit->record('demand.placement.synchronized', $placement->organization_id, $actor, $placement, newValues: [
            'success' => $result->success,
            'dry_run' => $result->dryRun,
            'remote_placement_id' => $placement->remote_placement_id,
        ]);

        return $result;
    }

    public function setPlacementEnabled(DemandPlacement $placement, bool $enabled, User $actor): DemandResult
    {
        $placement->loadMissing('demandSite.account.network');
        $connector = $this->connectors->for($placement->demandSite->account);
        $result = $enabled
            ? $connector->activatePlacement($placement)
            : $connector->pausePlacement($placement);

        if ($result->success) {
            $placement->update([
                'is_enabled' => $enabled,
                'sync_status' => $enabled ? DemandSyncStatus::InSync : DemandSyncStatus::Paused,
                'updated_by' => $actor->id,
            ]);
        }

        $this->log($placement->demandSite->account, $placement->demandSite, $placement, $enabled ? 'PLACEMENT_ACTIVATE' : 'PLACEMENT_PAUSE', $result, false);
        $this->audit->record($enabled ? 'demand.placement.activated' : 'demand.placement.paused', $placement->organization_id, $actor, $placement, newValues: [
            'success' => $result->success,
            'is_enabled' => $placement->is_enabled,
        ]);

        return $result;
    }


    private function approvalStatus(mixed $value): ?DemandApprovalStatus
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $normalized = strtoupper(str_replace(['-', ' '], '_', trim((string) $value)));

        return DemandApprovalStatus::tryFrom($normalized);
    }

    private function providerMapping(
        DemandAccount $account,
        string $localType,
        string $localId,
        string $remoteType,
        string $remoteId,
        string $idempotencyKey,
        array $metadata,
    ): void {
        DemandRemoteObject::withoutGlobalScopes()->updateOrCreate(
            [
                'demand_account_id' => $account->id,
                'connection_key' => 'PROVIDER',
                'local_object_type' => $localType,
                'local_object_id' => $localId,
                'remote_object_type' => $remoteType,
            ],
            [
                'organization_id' => $account->organization_id,
                'remote_object_id' => $remoteId,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR)),
                'remote_status' => 'ACTIVE',
                'metadata' => $metadata,
                'synced_at' => now(),
            ],
        );
    }

    private function log(
        DemandAccount $account,
        ?DemandSite $site,
        ?DemandPlacement $placement,
        string $action,
        DemandResult $result,
        bool $dryRun,
    ): void {
        DemandSyncLog::withoutGlobalScopes()->create([
            'organization_id' => $account->organization_id,
            'demand_account_id' => $account->id,
            'demand_site_id' => $site?->id,
            'demand_placement_id' => $placement?->id,
            'level' => $result->success ? 'INFO' : 'ERROR',
            'action' => $action,
            'dry_run' => $dryRun,
            'request_payload' => null,
            'response_payload' => $this->sanitizer->sanitize($result->data),
            'message' => $result->success ? "{$action} completed." : ($result->errorMessage ?: "{$action} failed."),
            'created_at' => now(),
        ]);

        if (! $result->success) {
            DemandError::withoutGlobalScopes()->create([
                'organization_id' => $account->organization_id,
                'demand_account_id' => $account->id,
                'demand_site_id' => $site?->id,
                'demand_placement_id' => $placement?->id,
                'category' => $result->errorCategory ?: 'UNKNOWN',
                'code' => $result->errorCode,
                'message' => $result->errorMessage ?: 'Demand operation failed.',
                'retryable' => $result->retryable,
                'context' => ['action' => $action],
                'occurred_at' => now(),
            ]);
        }
    }
}
