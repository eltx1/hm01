<?php

namespace App\Services\ControlPlane\Actions;

use App\Enums\AdsTxtComplianceStatus;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\PublisherStatementStatus;
use App\Models\PublisherStatement;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlPlane\Contracts\ActionCenterProvider;

final class PublisherActions implements ActionCenterProvider
{
    public function actions(User $user): array
    {
        if ($user->isHorusAdministrator() || ! $user->organization?->publisher) {
            return [];
        }
        $items = [];
        $publisher = $user->organization->publisher;

        if ($user->hasPermission('finance.publisher.view_own')) {
            $profile = $publisher->paymentProfile;
            $paymentRelevant = PublisherStatement::withoutGlobalScopes()
                ->where('publisher_id', $publisher->id)
                ->where('balance_due_minor', '>', 0)
                ->whereIn('status', [
                    PublisherStatementStatus::PendingInvoice->value,
                    PublisherStatementStatus::Payable->value,
                    PublisherStatementStatus::PartiallyPaid->value,
                ])
                ->exists();
            $requiresAction = $paymentRelevant
                && (! $profile || $profile->verification_status !== PublisherPaymentProfileStatus::Verified);
            $items[] = $this->item('publisher-payment-profile', 'Payment profile action required', $requiresAction ? 1 : 0,
                'Complete or update the payout destination before a payout can proceed.', 'publisher.finance.payment-method.edit', 10);
        }

        if ($user->hasPermission('publisher.ads_txt.view')) {
            $compliant = AdsTxtComplianceStatus::Compliant->value;
            $count = Site::withoutGlobalScopes()->where('organization_id', $user->organization_id)
                ->whereHas('supplyChainChecks', function ($query) use ($compliant): void {
                    $query->where('check_type', 'ADS_TXT')->where('status', '!=', $compliant)
                        ->whereRaw('supply_chain_checks.id = (SELECT latest_check.id FROM supply_chain_checks AS latest_check WHERE latest_check.site_id = sites.id AND latest_check.check_type = ? ORDER BY latest_check.checked_at DESC, latest_check.id DESC LIMIT 1)', ['ADS_TXT']);
                })->count();
            $items[] = $this->item('publisher-ads-txt', 'Ads.txt action required', $count,
                'One or more websites are not currently compliant.', 'publisher.ads-txt.index', 5, 'danger');
        }

        return $items;
    }

    private function item(string $key, string $label, int $count, string $description, string $route, int $priority, string $severity = 'warning'): array
    {
        return compact('key', 'label', 'count', 'description', 'route', 'priority', 'severity') + ['parameters' => []];
    }
}
