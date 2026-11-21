<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/**
 * The production audit only has integration-health and test-timestamp aggregates.
 * It cannot infer paid serving or a reconciliation tied to the same pilot.
 */
final class MonetizationAuditEvidence
{
    /** @return list<array{key: string, status: string, priority: string, message: string, evidence: array}> */
    public static function checks(int $healthyLiveGam, int $demandWithTestTimestamp): array
    {
        $candidate = $healthyLiveGam > 0 || $demandWithTestTimestamp > 0;

        return [
            [
                'key' => 'revenue_pilot_candidate',
                'status' => $candidate ? 'PASS' : 'BLOCKED',
                'priority' => 'P1',
                'message' => $candidate
                    ? 'A configured candidate is available for pilot validation. This does not establish paid serving or revenue.'
                    : 'No enabled healthy non-dry-run GAM connection or approved enabled Demand account with a test timestamp is available for pilot validation.',
                'evidence' => [
                    'healthy_live_gam' => $healthyLiveGam,
                    'demand_with_test_timestamp' => $demandWithTestTimestamp,
                ],
            ],
            [
                'key' => 'real_revenue_path',
                'status' => 'INFO',
                'priority' => 'P1',
                'message' => 'Paid serving is not verified by this audit. It requires provider-backed evidence for the same pilot site and placement.',
                'evidence' => ['verification' => 'NOT_VERIFIED'],
            ],
            [
                'key' => 'reconciled_paid_revenue',
                'status' => 'INFO',
                'priority' => 'P1',
                'message' => 'Reconciled paid revenue is not verified by this audit. A finalized import alone does not establish a match to the same paid pilot.',
                'evidence' => ['verification' => 'NOT_VERIFIED'],
            ],
        ];
    }
}
