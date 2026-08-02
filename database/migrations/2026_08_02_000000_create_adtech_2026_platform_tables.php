<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_formats', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('display_name');
            $table->string('placement_type', 24)->index();
            $table->string('media_type', 24);
            $table->json('default_sizes')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('defaults')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('placements', function (Blueprint $table): void {
            $table->foreignUlid('ad_format_id')->nullable()->after('ad_unit_id')->constrained('ad_formats')->nullOnDelete();
            $table->json('format_settings')->nullable()->after('metadata');
            $table->index(['site_id', 'ad_format_id'], 'placements_format_lookup');
        });

        Schema::table('site_configs', function (Blueprint $table): void {
            $table->json('privacy_settings')->nullable()->after('page_targeting');
            $table->json('gpt_settings')->nullable()->after('privacy_settings');
            $table->json('supply_chain_settings')->nullable()->after('gpt_settings');
            $table->json('observability_settings')->nullable()->after('supply_chain_settings');
        });

        Schema::create('seller_declarations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('seller_id', 255);
            $table->string('seller_type', 16)->default('PUBLISHER');
            $table->string('name');
            $table->string('domain');
            $table->boolean('is_confidential')->default(false);
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'site_id', 'seller_id'], 'seller_declarations_org_site_seller_unique');
        });

        Schema::create('supply_chain_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('check_type', 32);
            $table->string('status', 24)->index();
            $table->string('url', 1000)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->json('findings')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['site_id', 'check_type', 'checked_at'], 'supply_checks_site_type_time');
        });

        Schema::create('synthetic_probe_results', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('probe', 64);
            $table->string('environment', 20)->default('PRODUCTION');
            $table->string('status', 24)->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('checks')->nullable();
            $table->string('release', 64)->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamps();
            $table->index(['site_id', 'probe', 'observed_at'], 'synthetic_probe_site_time');
        });

        DB::table('gam_connections')->where('driver', 'SOAP')->update(['driver' => 'REST']);

        $appNexusAdapter = DB::table('prebid_adapters')->where('code', 'appnexus')->first();
        $microsoftAdapter = DB::table('prebid_adapters')->where('code', 'msft')->first();
        $microsoftDefinition = [
            'code' => 'msft', 'display_name' => 'Microsoft Monetize', 'module_code' => 'msftBidAdapter',
            'publisher_parameter' => null, 'placement_parameter' => 'placement_id',
            'required_public_parameters' => json_encode(['placement_id']),
            'optional_public_parameters' => json_encode(['member', 'inv_code', 'keywords']),
            'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/msft.html',
        ];
        if ($appNexusAdapter && $microsoftAdapter) {
            DB::table('prebid_bidders')->where('prebid_adapter_id', $appNexusAdapter->id)->update([
                'prebid_adapter_id' => $microsoftAdapter->id,
            ]);
            DB::table('prebid_adapters')->where('id', $appNexusAdapter->id)->delete();
            DB::table('prebid_adapters')->where('id', $microsoftAdapter->id)->update($microsoftDefinition);
        } elseif ($appNexusAdapter) {
            DB::table('prebid_adapters')->where('id', $appNexusAdapter->id)->update($microsoftDefinition);
        }

        DB::table('prebid_bidders')->where('code', 'appnexus')->orderBy('id')->get()->each(function ($bidder): void {
            $existing = DB::table('prebid_bidders')->where('code', 'msft')
                ->when($bidder->organization_id === null, fn ($query) => $query->whereNull('organization_id'))
                ->when($bidder->organization_id !== null, fn ($query) => $query->where('organization_id', $bidder->organization_id))
                ->exists();

            DB::table('prebid_bidders')->where('id', $bidder->id)->update($existing
                ? ['display_name' => 'AppNexus (legacy disabled)', 'enabled' => false]
                : ['code' => 'msft', 'display_name' => 'Microsoft Monetize']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synthetic_probe_results');
        Schema::dropIfExists('supply_chain_checks');
        Schema::dropIfExists('seller_declarations');
        Schema::table('site_configs', function (Blueprint $table): void {
            $table->dropColumn(['privacy_settings', 'gpt_settings', 'supply_chain_settings', 'observability_settings']);
        });
        Schema::table('placements', function (Blueprint $table): void {
            $table->dropIndex('placements_format_lookup');
            $table->dropConstrainedForeignId('ad_format_id');
            $table->dropColumn('format_settings');
        });
        Schema::dropIfExists('ad_formats');
    }
};
