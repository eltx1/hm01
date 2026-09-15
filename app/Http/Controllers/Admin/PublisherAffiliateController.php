<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalSetting;
use App\Models\Publisher;
use App\Models\PublisherAffiliateCommission;
use App\Services\Audit\AuditRecorder;
use App\Services\Reporting\PublisherAffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PublisherAffiliateController extends Controller
{
    public function index(Request $request, PublisherAffiliateService $affiliates): View
    {
        $publishers = Publisher::withoutGlobalScopes()
            ->with('referrer')
            ->withCount('referrals')
            ->withSum(['affiliateCommissions as affiliate_earned_minor' => fn ($query) => $query->where('status', 'EARNED')], 'commission_minor')
            ->orderByDesc('referrals_count')
            ->orderBy('display_name')
            ->paginate(50, ['*'], 'publishers_page');

        $commissions = PublisherAffiliateCommission::query()
            ->with(['referrer', 'referredPublisher', 'period', 'sourceStatement'])
            ->latest('created_at')
            ->paginate(50, ['*'], 'ledger_page');

        return view('admin.publisher-affiliates.index', [
            'publishers' => $publishers,
            'commissions' => $commissions,
            'defaultRateBp' => $affiliates->defaultRateBp(),
            'publisherOptions' => Publisher::withoutGlobalScopes()->orderBy('display_name')->get(['id', 'display_name', 'referral_code']),
        ]);
    }

    public function updateSettings(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $rateBp = (int) round(((float) $data['commission_percent']) * 100);
        $setting = GlobalSetting::query()->whereKey(PublisherAffiliateService::SETTING_KEY)->first();
        $before = $setting?->value;

        $setting = GlobalSetting::query()->updateOrCreate(
            ['key' => PublisherAffiliateService::SETTING_KEY],
            ['value' => ['commission_bp' => $rateBp], 'changed_by' => $request->user()->id],
        );

        $audit->record(
            'publisher_affiliate.settings.updated',
            $request->user()->organization_id,
            $request->user(),
            $setting,
            ['value' => $before],
            ['value' => $setting->value],
        );

        return back()->with('status', 'Default Publisher affiliate commission updated. Existing finalized commissions were not changed.');
    }

    public function updatePublisher(Request $request, Publisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referred_by_publisher_id' => [
                'nullable',
                'string',
                Rule::exists('publishers', 'id'),
                Rule::notIn([$publisher->id]),
            ],
        ]);

        $referrerId = $data['referred_by_publisher_id'] ?? null;
        if ($referrerId !== null) {
            $this->assertNoReferralCycle($publisher, $referrerId);
        }

        $before = [
            'affiliate_commission_override_bp' => $publisher->affiliate_commission_override_bp,
            'referred_by_publisher_id' => $publisher->referred_by_publisher_id,
            'referred_at' => optional($publisher->referred_at)->toIso8601String(),
        ];
        $overrideBp = filled($data['commission_percent'] ?? null)
            ? (int) round(((float) $data['commission_percent']) * 100)
            : null;
        $referrerChanged = $publisher->referred_by_publisher_id !== $referrerId;

        $publisher->update([
            'affiliate_commission_override_bp' => $overrideBp,
            'referred_by_publisher_id' => $referrerId,
            'referred_at' => $referrerChanged ? ($referrerId ? now() : null) : $publisher->referred_at,
        ]);

        $audit->record(
            'publisher_affiliate.publisher.updated',
            $publisher->organization_id,
            $request->user(),
            $publisher,
            $before,
            [
                'affiliate_commission_override_bp' => $publisher->affiliate_commission_override_bp,
                'referred_by_publisher_id' => $publisher->referred_by_publisher_id,
                'referred_at' => optional($publisher->referred_at)->toIso8601String(),
            ],
        );

        return back()->with('status', 'Publisher affiliate settings updated for future financial periods. Finalized historical commissions were not changed.');
    }

    private function assertNoReferralCycle(Publisher $publisher, string $referrerId): void
    {
        $visited = [];
        $cursorId = $referrerId;

        while ($cursorId !== null) {
            if ($cursorId === $publisher->id || isset($visited[$cursorId])) {
                throw ValidationException::withMessages([
                    'referred_by_publisher_id' => 'This change would create a referral cycle.',
                ]);
            }
            $visited[$cursorId] = true;
            $cursorId = Publisher::withoutGlobalScopes()->whereKey($cursorId)->value('referred_by_publisher_id');
        }
    }
}
