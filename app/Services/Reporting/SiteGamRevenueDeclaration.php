<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/** A declaration can cover one reviewed month without attesting future revenue. */
final class SiteGamRevenueDeclaration
{
    public static function validate(array $configuration): void
    {
        $month = $configuration['site_gam_included_period'] ?? null;
        if ($month !== null && $month !== '' && (! is_string($month)
            || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month))) {
            throw ValidationException::withMessages(['site_gam_included_period' => 'Choose a calendar month in YYYY-MM format.']);
        }
    }

    public static function covers(array $configuration, CarbonInterface $from, CarbonInterface $to): bool
    {
        if (! ($configuration['site_gam_included'] ?? false)) return false;
        self::validate($configuration);
        $month = $configuration['site_gam_included_period'] ?? null;
        return ! $month || ($from->format('Y-m') === $month && $to->format('Y-m') === $month);
    }

    public static function overlaps(array $configuration, CarbonInterface $from, CarbonInterface $to): bool
    {
        if (! ($configuration['site_gam_included'] ?? false)) return false;
        self::validate($configuration);
        $month = $configuration['site_gam_included_period'] ?? null;
        if (! $month) return true;
        $start = CarbonImmutable::parse($month.'-01', $from->getTimezone())->startOfDay();
        return $from->lte($start->endOfMonth()) && $to->gte($start);
    }
}
