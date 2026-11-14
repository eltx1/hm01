<?php

namespace App\Services\Sites;

/** Guidance follows the existing lifecycle; it never authorizes a transition. */
final class PublisherSetupGuidance
{
    public function describe(string $status): array
    {
        [$title, $owner, $detail, $target] = match ($status) {
            'DRAFT', 'PENDING_VERIFICATION' => ['Verify the website ads.txt', 'Publisher', 'Copy the complete assigned ads.txt block, then verify it. Successful verification submits the website for review.', '#ads-txt-setup'],
            'PENDING_REVIEW' => ['Website review is pending', 'Horus Media', 'Horus is reviewing this website. Check this page for the review result.', null],
            'APPROVED' => ['Website activation is pending', 'Horus Media', 'The website has been approved. Horus will handle activation through the existing process.', null],
            'ACTIVE' => ['Review installation and monetization', 'Publisher', 'Keep the permanent loader installed on the pages you want to monetize. The health section shows the current recorded configuration evidence.', '#site-installation'],
            'REJECTED' => ['Review the website feedback', 'Publisher', 'Review the publisher-visible decision on this page and contact Horus if you need clarification.', null],
            'SUSPENDED' => ['Contact Horus about the suspension', 'Horus Media', 'The website is suspended. Contact the account team to review the reason and next action.', null],
            'ARCHIVED' => ['Website is archived', 'Horus Media', 'Contact Horus if you need to discuss this archived website.', null],
            default => ['Contact Horus for the next action', 'Horus Media', 'The next step is not available for the recorded website state.', null],
        };

        return compact('title', 'owner', 'detail', 'target');
    }
}
