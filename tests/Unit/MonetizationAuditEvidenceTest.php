<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reporting\MonetizationAuditEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonetizationAuditEvidenceTest extends TestCase
{
    #[DataProvider('signalCases')]
    public function test_candidate_signals_do_not_prove_paid_serve_or_revenue(int $gam, int $demand, string $candidate): void
    {
        $checks = array_column(MonetizationAuditEvidence::checks($gam, $demand), null, 'key');

        self::assertSame($candidate, $checks['revenue_pilot_candidate']['status']);
        self::assertSame('P1', $checks['revenue_pilot_candidate']['priority']);
        self::assertSame([
            'healthy_live_gam' => $gam,
            'demand_with_test_timestamp' => $demand,
        ], $checks['revenue_pilot_candidate']['evidence']);
        foreach (['real_revenue_path', 'reconciled_paid_revenue'] as $key) {
            self::assertSame('INFO', $checks[$key]['status']);
            self::assertSame(['verification' => 'NOT_VERIFIED'], $checks[$key]['evidence']);
        }
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function signalCases(): iterable
    {
        yield 'no candidate' => [0, 0, 'BLOCKED'];
        yield 'demand test timestamp alone' => [0, 1, 'PASS'];
        yield 'healthy live GAM alone' => [1, 0, 'PASS'];
        yield 'both categories' => [2, 5, 'PASS'];
    }
}
