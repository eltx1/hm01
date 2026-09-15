<?php

namespace App\Services\Reporting;

use App\Enums\AccountStatus;
use App\Models\FinancialPeriod;
use App\Models\GlobalSetting;
use App\Models\Publisher;
use App\Models\PublisherAffiliateCommission;
use App\Models\PublisherStatement;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublisherAffiliateService
{
    public const DEFAULT_COMMISSION_BP = 500;
    public const SETTING_KEY = 'publisher_affiliate.default_commission_bp';

    public function defaultRateBp(): int
    {
        $value = GlobalSetting::query()->whereKey(self::SETTING_KEY)->value('value');
        $raw = is_array($value) ? ($value['commission_bp'] ?? null) : $value;

        return $this->normalizeRate($raw ?? self::DEFAULT_COMMISSION_BP);
    }

    public function effectiveRateBp(Publisher $referrer): int
    {
        return $this->normalizeRate($referrer->affiliate_commission_override_bp ?? $this->defaultRateBp());
    }

    public function ensureReferralCode(Publisher $publisher): string
    {
        if (filled($publisher->referral_code)) {
            return (string) $publisher->referral_code;
        }

        do {
            $code = 'HM'.Str::upper(Str::random(18));
        } while (Publisher::withoutGlobalScopes()->where('referral_code', $code)->exists());

        $publisher->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    public function referralUrl(Publisher $publisher): string
    {
        return route('publisher-registration.create', ['ref' => $this->ensureReferralCode($publisher)]);
    }

    public function attribute(Publisher $referredPublisher, ?string $referralCode): Publisher
    {
        $this->ensureReferralCode($referredPublisher);

        if (blank($referralCode)) {
            return $referredPublisher->refresh();
        }
        if ($referredPublisher->referred_by_publisher_id) {
            return $referredPublisher->refresh();
        }

        $code = Str::upper(trim((string) $referralCode));
        $referrer = Publisher::withoutGlobalScopes()
            ->where('referral_code', $code)
            ->where('status', AccountStatus::Active->value)
            ->first();

        if (! $referrer) {
            throw ValidationException::withMessages([
                'referral_code' => 'This Publisher referral link is no longer valid.',
            ]);
        }
        if ($referrer->id === $referredPublisher->id) {
            throw ValidationException::withMessages([
                'referral_code' => 'A Publisher cannot refer itself.',
            ]);
        }

        $referredPublisher->forceFill([
            'referred_by_publisher_id' => $referrer->id,
            'referred_at' => now(),
        ])->save();

        return $referredPublisher->refresh();
    }

    /**
     * Rebuild the affiliate ledger for an in-progress period close.
     *
     * @return array<string, array{total_minor:int,line_items:array<int,array<string,mixed>>}>
     */
    public function reconcilePeriod(FinancialPeriod $period, ?User $actor): array
    {
        PublisherAffiliateCommission::query()
            ->where('financial_period_id', $period->id)
            ->delete();

        $statements = PublisherStatement::withoutGlobalScopes()
            ->with('publisher')
            ->where('financial_period_id', $period->id)
            ->where('currency', $period->currency)
            ->get();

        $result = [];

        foreach ($statements as $statement) {
            $referredPublisher = $statement->publisher;
            if (! $referredPublisher?->referred_by_publisher_id) {
                continue;
            }

            $basis = max(0, (int) $statement->publisher_earnings_minor);
            if ($basis === 0) {
                continue;
            }

            $referrer = Publisher::withoutGlobalScopes()->find($referredPublisher->referred_by_publisher_id);
            if (! $referrer || $referrer->id === $referredPublisher->id) {
                continue;
            }

            $rate = $this->effectiveRateBp($referrer);
            $commission = $this->calculateCommission($basis, $rate);
            if ($commission === 0) {
                continue;
            }

            PublisherAffiliateCommission::query()->create([
                'referrer_publisher_id' => $referrer->id,
                'referred_publisher_id' => $referredPublisher->id,
                'source_publisher_statement_id' => $statement->id,
                'financial_period_id' => $period->id,
                'currency' => $period->currency,
                'basis_minor' => $basis,
                'commission_rate_bp' => $rate,
                'commission_minor' => $commission,
                'status' => 'EARNED',
                'metadata' => [
                    'source_statement_number' => $statement->statement_number,
                    'basis' => 'publisher_earnings_minor',
                ],
                'calculated_by' => $actor?->id,
            ]);

            $result[$referrer->id] ??= ['total_minor' => 0, 'line_items' => []];
            $result[$referrer->id]['total_minor'] += $commission;
            $result[$referrer->id]['line_items'][] = [
                'source' => 'AFFILIATE',
                'referred_publisher_id' => $referredPublisher->id,
                'referred_publisher' => $referredPublisher->display_name,
                'description' => 'Publisher referral commission',
                'impressions' => 0,
                'gross_revenue_minor' => 0,
                'net_revenue_minor' => 0,
                'publisher_earnings_minor' => 0,
                'affiliate_basis_minor' => $basis,
                'affiliate_commission_rate_bp' => $rate,
                'affiliate_earnings_minor' => $commission,
            ];
        }

        return $result;
    }

    public function calculateCommission(int $publisherEarningsMinor, int $commissionRateBp): int
    {
        $basis = max(0, $publisherEarningsMinor);
        $rate = $this->normalizeRate($commissionRateBp);

        return intdiv(($basis * $rate) + 5000, 10000);
    }

    private function normalizeRate(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_COMMISSION_BP;
        }

        return max(0, min(10000, (int) $value));
    }
}
