<?php

namespace App\Services\Notifications;

use App\Enums\AdsTxtComplianceStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\PublisherPaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\SiteStatus;
use App\Enums\SupportTicketStatus;
use App\Models\PublisherPayment;
use App\Models\PublisherPaymentProfile;
use App\Models\PublisherStatement;
use App\Models\ReconciliationRun;
use App\Models\Site;
use App\Models\SupplyChainCheck;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class DomainNotificationService
{
    public function __construct(private readonly HorusNotificationService $notifications) {}

    public function supportCreated(SupportTicket $ticket): int
    {
        return $this->notifications->notify($this->notifications->horusRecipients('support.admin.view'), [
            'category' => NotificationCategory::Support,
            'type' => 'SUPPORT_TICKET_CREATED',
            'severity' => $ticket->priority->value === 'URGENT' ? NotificationSeverity::Critical : NotificationSeverity::Info,
            'title' => 'New support ticket '.$ticket->ticket_number,
            'message' => 'A customer created a '.$ticket->category->label().' support ticket.',
            'event_key' => 'support:created:'.$ticket->id,
            'related_type' => 'SUPPORT_TICKET', 'related_id' => $ticket->id,
            'action_route' => 'admin.support.tickets.show', 'action_parameters' => ['ticket' => $ticket->id],
        ]);
    }

    public function supportReply(SupportTicket $ticket, SupportTicketMessage $message, bool $fromHorus): int
    {
        $recipients = $fromHorus
            ? $this->notifications->organizationRecipients($ticket->organization_id, 'support.tickets.view_own')
            : $this->notifications->horusRecipients('support.admin.view');

        return $this->notifications->notify($recipients, [
            'category' => NotificationCategory::Support,
            'type' => $fromHorus ? 'SUPPORT_HORUS_REPLY' : 'SUPPORT_CUSTOMER_REPLY',
            'severity' => NotificationSeverity::Info,
            'title' => ($fromHorus ? 'Horus replied to ' : 'Customer replied to ').$ticket->ticket_number,
            'message' => 'A public reply was added to the support ticket. Open the ticket to read it securely.',
            'event_key' => 'support:reply:'.$message->id,
            'related_type' => 'SUPPORT_TICKET', 'related_id' => $ticket->id,
            'action_route' => $fromHorus ? 'support.tickets.show' : 'admin.support.tickets.show',
            'action_parameters' => ['ticket' => $ticket->id],
        ]);
    }

    public function supportAssigned(SupportTicket $ticket, ?User $assignee): int
    {
        if (! $assignee) {
            return 0;
        }

        return $this->notifications->notify([$assignee], [
            'category' => NotificationCategory::Support, 'type' => 'SUPPORT_TICKET_ASSIGNED',
            'severity' => NotificationSeverity::Warning,
            'title' => 'Ticket assigned to you',
            'message' => $ticket->ticket_number.' is awaiting your attention.',
            'event_key' => 'support:assigned:'.$ticket->id.':'.$assignee->id.':'.$ticket->updated_at?->getTimestamp(),
            'related_type' => 'SUPPORT_TICKET', 'related_id' => $ticket->id,
            'action_route' => 'admin.support.tickets.show', 'action_parameters' => ['ticket' => $ticket->id],
        ]);
    }

    public function supportStatus(SupportTicket $ticket, SupportTicketStatus $from): int
    {
        $customerVisible = in_array($ticket->status, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed, SupportTicketStatus::PendingHorus], true);
        if (! $customerVisible) {
            return 0;
        }

        return $this->notifications->notify(
            $this->notifications->organizationRecipients($ticket->organization_id, 'support.tickets.view_own'),
            [
                'category' => NotificationCategory::Support,
                'type' => 'SUPPORT_STATUS_CHANGED',
                'severity' => $ticket->status === SupportTicketStatus::Resolved ? NotificationSeverity::Success : NotificationSeverity::Info,
                'title' => $ticket->ticket_number.' is '.str($ticket->status->value)->lower()->replace('_', ' ')->value(),
                'message' => 'The support ticket moved from '.str($from->value)->lower()->replace('_', ' ')->value().' to '.str($ticket->status->value)->lower()->replace('_', ' ')->value().'.',
                'event_key' => 'support:status:'.$ticket->id.':'.$from->value.':'.$ticket->status->value.':'.$ticket->updated_at?->getTimestamp(),
                'related_type' => 'SUPPORT_TICKET', 'related_id' => $ticket->id,
                'action_route' => 'support.tickets.show', 'action_parameters' => ['ticket' => $ticket->id],
            ],
        );
    }

    public function adsTxtChanged(Site $site, SupplyChainCheck $check, ?string $previousStatus): int
    {
        $status = AdsTxtComplianceStatus::from($check->status);
        if ($previousStatus === $status->value || ($previousStatus === null && $status === AdsTxtComplianceStatus::Compliant)) {
            return 0;
        }
        $healthy = $status === AdsTxtComplianceStatus::Compliant;
        $payload = [
            'category' => NotificationCategory::Compliance,
            'type' => $healthy ? 'ADS_TXT_RECOVERED' : 'ADS_TXT_NON_COMPLIANT',
            'severity' => $healthy ? NotificationSeverity::Success : NotificationSeverity::Warning,
            'title' => $healthy ? 'Ads.txt compliance restored' : 'Ads.txt action required',
            'message' => $site->display_name.' is now '.str($status->value)->lower()->replace('_', ' ')->value().'.',
            'event_key' => 'ads-txt:'.$check->id.':'.$status->value,
            'related_type' => 'SITE', 'related_id' => $site->id,
        ];
        $publisherCount = $this->notifications->notify(
            $this->notifications->organizationRecipients($site->organization_id, 'publisher.ads_txt.view'),
            $payload + ['action_route' => 'publisher.ads-txt.index', 'action_parameters' => []],
        );
        $adminCount = $this->notifications->notify(
            $this->notifications->horusRecipients('supply_chain.ads_txt.view'),
            $payload + ['action_route' => 'admin.compliance.ads-txt.show', 'action_parameters' => ['site' => $site->id]],
        );

        return $publisherCount + $adminCount;
    }

    public function siteStatusChanged(Site $site, SiteStatus $from): int
    {
        $recipients = $site->status === SiteStatus::PendingReview
            ? $this->notifications->horusRecipients('sites.review')
            : $this->notifications->organizationRecipients($site->organization_id, 'sites.view');
        $adminTarget = $site->status === SiteStatus::PendingReview;

        return $this->notifications->notify($recipients, [
            'category' => NotificationCategory::Sites, 'type' => 'SITE_STATUS_CHANGED',
            'severity' => $site->status === SiteStatus::Rejected || $site->status === SiteStatus::Suspended ? NotificationSeverity::Warning : NotificationSeverity::Info,
            'title' => $site->display_name.' status changed',
            'message' => 'Website status changed from '.str($from->value)->lower()->headline()->value().' to '.str($site->status->value)->lower()->headline()->value().'.',
            'event_key' => 'site:status:'.$site->id.':'.$from->value.':'.$site->status->value.':'.$site->updated_at?->getTimestamp(),
            'related_type' => 'SITE', 'related_id' => $site->id,
            'action_route' => $adminTarget ? 'admin.sites.show' : 'publisher.sites.index',
            'action_parameters' => $adminTarget ? ['site' => $site->id] : [],
        ]);
    }

    public function paymentProfileChanged(PublisherPaymentProfile $profile): int
    {
        $status = $profile->verification_status;
        $publisherRecipients = $this->notifications->organizationRecipients($profile->organization_id, 'finance.publisher.view_own');
        $count = $this->notifications->notify($publisherRecipients, [
            'category' => NotificationCategory::Finance, 'type' => 'PAYMENT_PROFILE_STATUS',
            'severity' => $status === PublisherPaymentProfileStatus::Verified ? NotificationSeverity::Success : NotificationSeverity::Warning,
            'title' => 'Payment profile '.str($status->value)->lower()->replace('_', ' ')->value(),
            'message' => $status === PublisherPaymentProfileStatus::Verified ? 'Your payout destination has been verified.' : 'Your payment profile requires review or an update.',
            'event_key' => 'payment-profile:'.$profile->id.':'.$status->value.':'.$profile->updated_at?->getTimestamp(),
            'related_type' => 'PAYMENT_PROFILE', 'related_id' => $profile->id,
            'action_route' => 'publisher.finance.payment-method.edit', 'action_parameters' => [],
        ]);
        if (in_array($status, [PublisherPaymentProfileStatus::PendingVerification, PublisherPaymentProfileStatus::NeedsUpdate], true)) {
            $count += $this->notifications->notify($this->notifications->horusRecipients('finance.payment_profiles.verify'), [
                'category' => NotificationCategory::Finance, 'type' => 'PAYMENT_PROFILE_REVIEW',
                'severity' => NotificationSeverity::Warning, 'title' => 'Payment profile awaiting review',
                'message' => 'A Publisher payment profile is awaiting Finance review. Sensitive values remain masked.',
                'event_key' => 'payment-profile-admin:'.$profile->id.':'.$status->value.':'.$profile->updated_at?->getTimestamp(),
                'related_type' => 'PAYMENT_PROFILE', 'related_id' => $profile->id,
                'action_route' => 'admin.finance.payment-profiles.index', 'action_parameters' => [],
            ]);
        }

        return $count;
    }

    public function statementFinalized(PublisherStatement $statement): int
    {
        return $this->notifications->notify($this->notifications->organizationRecipients($statement->organization_id, 'finance.publisher.view_own'), [
            'category' => NotificationCategory::Finance, 'type' => 'STATEMENT_FINALIZED',
            'severity' => NotificationSeverity::Info, 'title' => 'Statement '.$statement->statement_number.' finalized',
            'message' => $statement->invoiceRequired() ? 'Your finalized statement is ready and an invoice is required.' : 'Your finalized statement is ready to review.',
            'event_key' => 'statement:finalized:'.$statement->id.':'.$statement->snapshot_hash,
            'related_type' => 'STATEMENT', 'related_id' => $statement->id,
            'action_route' => 'publisher.finance.statements.show', 'action_parameters' => ['publisherStatement' => $statement->id],
        ]);
    }

    public function payoutChanged(PublisherPayment $payment): int
    {
        $status = $payment->status;
        if (! in_array($status, [PublisherPaymentStatus::Approved, PublisherPaymentStatus::Scheduled, PublisherPaymentStatus::PartiallyPaid, PublisherPaymentStatus::Paid, PublisherPaymentStatus::Failed, PublisherPaymentStatus::Held], true)) {
            return 0;
        }
        $warning = in_array($status, [PublisherPaymentStatus::Failed, PublisherPaymentStatus::Held, PublisherPaymentStatus::PartiallyPaid], true);
        $message = match ($status) {
            PublisherPaymentStatus::Approved => 'Your payout was approved. This does not yet mean funds were paid.',
            PublisherPaymentStatus::Scheduled => 'Your payout was scheduled for external processing.',
            PublisherPaymentStatus::PartiallyPaid => 'Part of your payout was settled. The remaining balance is still outstanding.',
            PublisherPaymentStatus::Paid => 'Your payout was recorded as paid with a settlement reference.',
            PublisherPaymentStatus::Failed, PublisherPaymentStatus::Held => $payment->publisher_message ?: 'Your payout requires attention. Contact Horus Finance for details.',
            default => 'Your payout status changed.',
        };
        $payload = [
            'category' => NotificationCategory::Finance, 'type' => 'PAYOUT_STATUS_CHANGED',
            'severity' => $warning ? NotificationSeverity::Warning : ($status === PublisherPaymentStatus::Paid ? NotificationSeverity::Success : NotificationSeverity::Info),
            'title' => $payment->payment_number.' '.str($status->value)->lower()->replace('_', ' ')->value(),
            'message' => $message,
            'event_key' => 'payout:'.$payment->id.':'.$status->value.':'.$payment->updated_at?->getTimestamp(),
            'related_type' => 'PAYMENT', 'related_id' => $payment->id,
        ];
        $count = $this->notifications->notify(
            $this->notifications->organizationRecipients($payment->organization_id, 'finance.publisher.view_own'),
            $payload + ['action_route' => 'publisher.finance.payouts.index', 'action_parameters' => []],
        );
        if (in_array($status, [PublisherPaymentStatus::Failed, PublisherPaymentStatus::Held], true)) {
            $count += $this->notifications->notify($this->notifications->horusRecipients('finance.payments.view'),
                $payload + ['action_route' => 'admin.finance.payouts.index', 'action_parameters' => []]);
        }

        return $count;
    }

    public function reconciliationChanged(ReconciliationRun $run): int
    {
        if (! in_array($run->status, [ReconciliationStatus::Warning, ReconciliationStatus::Failed, ReconciliationStatus::Resolved], true)) {
            return 0;
        }

        return $this->notifications->notify($this->notifications->horusRecipients('finance.reconciliation.manage'), [
            'category' => NotificationCategory::Finance, 'type' => 'RECONCILIATION_STATUS',
            'severity' => $run->status === ReconciliationStatus::Resolved ? NotificationSeverity::Success : NotificationSeverity::Critical,
            'title' => 'Reconciliation '.str($run->status->value)->lower()->value(),
            'message' => $run->status === ReconciliationStatus::Resolved ? 'A reconciliation discrepancy was resolved without silently mutating financial totals.' : 'A reconciliation discrepancy requires Finance review.',
            'event_key' => 'reconciliation:'.$run->id.':'.$run->status->value.':'.$run->updated_at?->getTimestamp(),
            'related_type' => 'RECONCILIATION', 'related_id' => $run->id,
            'action_route' => 'admin.finance.reconciliation.index', 'action_parameters' => [],
        ]);
    }

    public function operationFailed(Model $record, string $kind, string $permission, string $route): int
    {
        return $this->notifications->notify($this->notifications->horusRecipients($permission), [
            'category' => NotificationCategory::Operations,
            'type' => 'OPERATION_FAILURE',
            'severity' => NotificationSeverity::Critical,
            'title' => str($kind)->replace('_', ' ')->headline()->value().' failed',
            'message' => 'A production operation requires authorized remediation. Open the operational record for safe details.',
            'event_key' => 'operation:'.$kind.':'.$record->getKey().':'.($record->updated_at?->getTimestamp() ?? 'created'),
            'related_type' => str($kind)->upper()->value(), 'related_id' => $record->getKey(),
            'action_route' => $route, 'action_parameters' => [],
        ]);
    }

    public function siteServingChanged(Site $site, string $from, string $to): int
    {
        return $this->notifications->notify($this->notifications->organizationRecipients($site->organization_id, 'sites.view'), [
            'category' => NotificationCategory::Sites, 'type' => 'SITE_SERVING_CHANGED',
            'severity' => $to === 'PAUSED' ? NotificationSeverity::Warning : NotificationSeverity::Info,
            'title' => $site->display_name.' serving mode changed',
            'message' => 'Serving mode changed from '.str($from)->lower()->headline()->value().' to '.str($to)->lower()->headline()->value().'.',
            'event_key' => 'site:serving:'.$site->id.':'.$from.':'.$to.':'.$site->updated_at?->getTimestamp(),
            'related_type' => 'SITE', 'related_id' => $site->id,
            'action_route' => 'publisher.sites.index', 'action_parameters' => [],
        ]);
    }
}
