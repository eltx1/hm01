<?php

namespace App\Services\ControlPlane\Actions;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\ContractStatus;
use App\Enums\SiteStatus;
use App\Models\Campaign;
use App\Models\Publisher;
use App\Models\PublisherContract;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlPlane\Contracts\ActionCenterProvider;

final class ReviewActions implements ActionCenterProvider
{
    public function actions(User $user): array
    {
        $items = [];

        if ($user->hasPermission('publishers.view')) {
            $items[] = $this->item('publisher-reviews', 'Publishers awaiting review', Publisher::withoutGlobalScopes()
                ->where('status', AccountStatus::Pending->value)->whereNotNull('onboarding_submitted_at')->count(),
                'Submitted publisher accounts need an approval decision.', 'admin.publishers.index', ['status' => AccountStatus::Pending->value], 10);
        }

        if ($user->hasPermission('sites.view')) {
            $items[] = $this->item('site-reviews', 'Websites awaiting review', Site::withoutGlobalScopes()
                ->where('status', SiteStatus::PendingReview->value)->count(),
                'Publisher websites are ready for an operational review.', 'admin.sites.index', ['status' => SiteStatus::PendingReview->value], 10);
        }

        if ($user->hasPermission('campaigns.review')) {
            $items[] = $this->item('campaign-reviews', 'Campaigns awaiting review', Campaign::withoutGlobalScopes()
                ->where('status', CampaignStatus::PendingReview->value)->count(),
                'Campaigns and creatives need an approval decision.', 'admin.campaigns.index', ['status' => CampaignStatus::PendingReview->value], 20);
        }

        if ($user->hasPermission('contracts.view')) {
            $items[] = $this->item('contract-actions', 'Contract actions', PublisherContract::withoutGlobalScopes()
                ->where(function ($query): void {
                    $query->where('status', ContractStatus::Sent->value)
                        ->orWhere(fn ($active) => $active->where('status', ContractStatus::Active->value)
                            ->whereNotNull('ends_at')->whereDate('ends_at', '<=', now()->addDays(30)));
                })->count(),
                'Sent or expiring publisher contracts require follow-up.', 'admin.publishers.index', [], 40, 'neutral');
        }

        return $items;
    }

    private function item(string $key, string $label, int $count, string $description, string $route, array $parameters, int $priority, string $severity = 'warning'): array
    {
        return compact('key', 'label', 'count', 'description', 'route', 'parameters', 'priority', 'severity');
    }
}
