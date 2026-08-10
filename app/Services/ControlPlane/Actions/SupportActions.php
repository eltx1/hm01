<?php

namespace App\Services\ControlPlane\Actions;

use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\ControlPlane\Contracts\ActionCenterProvider;

final class SupportActions implements ActionCenterProvider
{
    public function actions(User $user): array
    {
        if ($user->isHorusAdministrator() && $user->hasPermission('support.admin.view')) {
            $open = [SupportTicketStatus::Open->value, SupportTicketStatus::PendingHorus->value];
            $counts = SupportTicket::withoutGlobalScopes()->selectRaw(
                'SUM(CASE WHEN status IN (?, ?) AND priority IN (?, ?) THEN 1 ELSE 0 END) AS priority_count, SUM(CASE WHEN status NOT IN (?, ?) AND sla_paused_at IS NULL AND ((first_response_at IS NULL AND first_response_due_at < ?) OR resolution_due_at < ?) THEN 1 ELSE 0 END) AS breached_count',
                [...$open, SupportTicketPriority::High->value, SupportTicketPriority::Urgent->value, SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value, now(), now()],
            )->toBase()->first();

            return [
                $this->item('support-priority', 'High-priority tickets awaiting Horus', (int) ($counts->priority_count ?? 0),
                    'High or urgent customer tickets require a Horus response.', 'admin.support.tickets.index', 5, 'danger'),
                $this->item('support-sla', 'Support SLA breaches', (int) ($counts->breached_count ?? 0),
                    'Open tickets have exceeded a first-response or resolution deadline.', 'admin.support.tickets.index', 1, 'danger'),
            ];
        }

        if ($user->hasPermission('support.tickets.view_own')) {
            return [$this->item('support-customer-response', 'Support replies awaiting you', SupportTicket::withoutGlobalScopes()
                ->where('organization_id', $user->organization_id)
                ->where('status', SupportTicketStatus::PendingCustomer->value)->count(),
                'Horus Support is waiting for your organization to respond.', 'support.tickets.index', 20)];
        }

        return [];
    }

    private function item(string $key, string $label, int $count, string $description, string $route, int $priority, string $severity = 'warning'): array
    {
        return compact('key', 'label', 'count', 'description', 'route', 'priority', 'severity') + ['parameters' => []];
    }
}
