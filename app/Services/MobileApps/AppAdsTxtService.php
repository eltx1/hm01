<?php

namespace App\Services\MobileApps;

use App\Models\{MobileApp, PlatformAdsTxtRecord, SellerDeclaration, User};
use App\Services\Audit\AuditRecorder;
use App\Services\Compliance\{AdsTxtFetcher, AdsTxtParser};
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AppAdsTxtService
{
    public function content(MobileApp $app): string
    {
        $lines = PlatformAdsTxtRecord::query()->where('status', 'ACTIVE')->where('review_status', 'VERIFIED')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->get()->toBase()->map(fn ($r) => implode(', ', array_filter([$r->advertising_system_domain, $r->publisher_account_id, $r->relationship, $r->certification_authority_id])));
        $domain = app(SupplyChainStandardsContract::class)->horusAdvertisingSystemDomain();
        $sellers = SellerDeclaration::withoutGlobalScopes()->where('publisher_id', $app->publisher_id)
            ->where('identity_source', 'HORUS_MANAGED')
            ->where(fn ($q) => $q->where('identity_scope', 'PUBLISHER')->orWhere('mobile_app_id', $app->id))->get();
        foreach ($sellers as $seller) $lines->push($domain.', '.$seller->seller_id.', DIRECT');
        return $lines->unique()->sort()->implode("\n")."\n";
    }

    public function current(MobileApp $app): bool
    {
        return data_get($app->app_ads_evidence, 'verified') === true
            && data_get($app->app_ads_evidence, 'developer_domain') === $app->developer_domain
            && data_get($app->app_ads_evidence, 'required_hash') === hash('sha256', $this->content($app));
    }

    public function verify(MobileApp $app, User $actor): bool
    {
        $domain = $app->developer_domain;
        $fetch = app(AdsTxtFetcher::class)->fetchAppDomain($domain);
        return DB::transaction(function () use ($app, $domain, $fetch, $actor) {
            $app = MobileApp::withoutGlobalScopes()->lockForUpdate()->findOrFail($app->id);
            if ($app->developer_domain !== $domain) throw ValidationException::withMessages(['developer_domain' => 'The developer domain changed during verification. Retry.']);
            $required = $this->content($app);
            $parser = app(AdsTxtParser::class);
            $requiredLines = array_column($parser->parse($required)['records'], 'canonical');
            $live = $parser->parse($fetch['body'] ?? '');
            $missing = array_values(array_diff($requiredLines, array_column($live['records'], 'canonical')));
            $comparison = app(\App\Services\Compliance\AdsTxtComparator::class)->compare($required, $fetch['body'] ?? '');
            $verified = $fetch['ok'] && count($requiredLines) >= 2 && $comparison['status'] === 'COMPLIANT';
            $app->update(['app_ads_evidence' => ['verified' => $verified, 'developer_domain' => $domain,
                'required_hash' => hash('sha256', $required), 'checked_at' => now()->toIso8601String(),
                'missing' => $missing, 'error' => $fetch['error'], 'url' => $fetch['final_url']]]);
            app(AuditRecorder::class)->record('mobile_app.app_ads_checked', $app->organization_id, $actor, $app,
                newValues: ['verified' => $verified, 'developer_domain' => $domain]);
            return $verified;
        });
    }
}
