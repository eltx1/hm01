<?php

namespace App\Services\ControlPlane\Actions;

use App\Enums\ReportImportStatus;
use App\Models\Publisher;
use App\Models\PublisherStatement;
use App\Models\ReportImportJob;
use App\Models\User;
use App\Services\ControlPlane\Contracts\ActionCenterProvider;

final class FinanceActions implements ActionCenterProvider
{
    public function actions(User $user): array
    {
        $items = [];

        if ($user->hasPermission('reporting.import')) {
            $items[] = $this->item('report-imports', 'Failed report imports', ReportImportJob::withoutGlobalScopes()
                ->where('status', ReportImportStatus::Failed->value)->count(),
                'Aggregated report imports are eligible for investigation or retry.', 'admin.reporting.index', 10, 'danger');
        }

        if ($user->hasPermission('publisher_payments.manage')) {
            $items[] = $this->item('payment-profiles', 'Payment profiles not verified', Publisher::withoutGlobalScopes()
                ->where(function ($query): void {
                    $query->whereDoesntHave('paymentProfile')
                        ->orWhereHas('paymentProfile', fn ($profile) => $profile->where('verification_status', '!=', 'VERIFIED'));
                })->count(),
                'Publisher payment details are missing or awaiting verification.', 'admin.publishers.index', 30);
        }

        if ($user->hasPermission('finance.publisher.view') || $user->hasPermission('reporting.admin.view')) {
            $items[] = $this->item('publisher-balances', 'Outstanding publisher balances', PublisherStatement::withoutGlobalScopes()
                ->where('balance_due_minor', '>', 0)->count(),
                'Finalized statements have a remaining publisher balance.', 'admin.reporting.index', 40, 'neutral');
        }

        return $items;
    }

    private function item(string $key, string $label, int $count, string $description, string $route, int $priority, string $severity = 'warning'): array
    {
        return compact('key', 'label', 'count', 'description', 'route', 'priority', 'severity') + ['parameters' => []];
    }
}
