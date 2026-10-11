<?php

namespace App\Services\MobileApps;

use App\Models\{MobileApp, Publisher, SellerDeclaration, User};
use App\Services\Audit\AuditRecorder;
use App\Services\SupplyChain\HorusSellerIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MobileAppService
{
    public function create(Publisher $publisher, array $data, User $actor): MobileApp
    {
        $identity = app(AppStoreUrl::class)->parse($data['store_url'], $data['package_name'] ?? null);
        $domain = app(AppStoreUrl::class)->domain($data['developer_domain']);
        return DB::transaction(function () use ($publisher, $identity, $domain, $data, $actor) {
            Publisher::withoutGlobalScopes()->whereKey($publisher->id)->lockForUpdate()->firstOrFail();
            if (MobileApp::withoutGlobalScopes()->where('platform', $identity['platform'])->where('store_app_id', $identity['store_app_id'])->exists()) {
                throw ValidationException::withMessages(['store_url' => 'This app is already registered. Contact support for an ownership review.']);
            }
            $hmp = app(HorusSellerIdentityService::class)->ensureForPublisher($publisher, $actor);
            $app = MobileApp::withoutGlobalScopes()->create($identity + [
                'name' => $data['name'], 'organization_id' => $publisher->organization_id,
                'publisher_id' => $publisher->id, 'public_id' => 'hma_'.Str::lower(Str::random(24)),
                'developer_domain' => $domain, 'status' => 'PENDING',
            ]);
            SellerDeclaration::withoutGlobalScopes()->create([
                'organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id,
                'mobile_app_id' => $app->id, 'identity_source' => 'HORUS_MANAGED', 'identity_scope' => 'MOBILE_APP',
                'seller_id' => 'HMA-'.Str::ulid(), 'seller_type' => 'PUBLISHER', 'ads_txt_relationship' => 'DIRECT',
                'name' => $hmp->name, 'domain' => $hmp->domain, 'is_confidential' => $hmp->is_confidential,
                'status' => 'DISABLED', 'review_status' => 'REVIEW_REQUIRED', 'identity_issued_at' => now(),
                'metadata' => ['lifecycle' => 'mobile_app_reserved'],
            ]);
            app(AuditRecorder::class)->record('mobile_app.created', $app->organization_id, $actor, $app,
                newValues: $app->only(['public_id', 'store', 'store_app_id', 'developer_domain']));
            return $app;
        });
    }

    public function changeDomain(MobileApp $app, string $domain, User $actor): void
    {
        $domain = app(AppStoreUrl::class)->domain($domain);
        DB::transaction(function () use ($app, $domain, $actor) {
            $app = MobileApp::withoutGlobalScopes()->lockForUpdate()->findOrFail($app->id);
            if ($app->developer_domain === $domain) return;
            $before = $app->only(['developer_domain', 'status']);
            $app->update(['developer_domain' => $domain, 'store_reviewed_at' => null, 'reviewed_by' => null,
                'app_ads_evidence' => null, 'status' => 'PENDING']);
            app(AuditRecorder::class)->record('mobile_app.domain_changed', $app->organization_id, $actor, $app,
                oldValues: $before, newValues: $app->only(['developer_domain', 'status']));
        });
    }
}
