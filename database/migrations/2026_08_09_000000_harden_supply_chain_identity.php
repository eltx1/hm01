<?php

use App\Services\SupplyChain\SupplyChainIdentityBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table): void {
            $table->string('business_domain', 253)->nullable()->after('display_name');
            $table->string('supply_chain_review_status', 24)->default('REVIEW_REQUIRED')->after('business_domain')->index();
            $table->timestamp('supply_chain_reviewed_at')->nullable()->after('supply_chain_review_status');
            $table->foreignUlid('supply_chain_reviewed_by')->nullable()->after('supply_chain_reviewed_at')->constrained('users')->nullOnDelete();
            $table->index(['business_domain', 'supply_chain_review_status'], 'publishers_supply_identity_lookup');
        });

        Schema::table('seller_declarations', function (Blueprint $table): void {
            $table->foreignUlid('publisher_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->string('review_status', 24)->default('REVIEW_REQUIRED')->after('status')->index();
            $table->timestamp('reviewed_at')->nullable()->after('last_verified_at');
            $table->foreignUlid('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->index(['publisher_id', 'site_id', 'status'], 'seller_declarations_entity_site_status');
            $table->index(['seller_id', 'status'], 'seller_declarations_seller_status');
        });

        Schema::table('demand_ads_txt_records', function (Blueprint $table): void {
            $table->string('review_status', 24)->default('REVIEW_REQUIRED')->after('status')->index();
            $table->timestamp('reviewed_at')->nullable()->after('last_verified_at');
            $table->foreignUlid('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->index(['demand_account_id', 'site_id', 'status'], 'demand_ads_txt_account_site_status');
            $table->index(['site_id', 'status'], 'demand_ads_txt_site_status');
        });

        app(SupplyChainIdentityBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('demand_ads_txt_records', function (Blueprint $table): void {
            $table->dropIndex('demand_ads_txt_account_site_status');
            $table->dropIndex('demand_ads_txt_site_status');
            $table->dropIndex('demand_ads_txt_records_review_status_index');
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['review_status', 'reviewed_at', 'reviewed_by']);
        });

        Schema::table('seller_declarations', function (Blueprint $table): void {
            $table->dropIndex('seller_declarations_entity_site_status');
            $table->dropIndex('seller_declarations_seller_status');
            $table->dropIndex('seller_declarations_review_status_index');
            $table->dropForeign(['publisher_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['publisher_id', 'review_status', 'reviewed_at', 'reviewed_by']);
        });

        Schema::table('publishers', function (Blueprint $table): void {
            $table->dropIndex('publishers_supply_identity_lookup');
            $table->dropIndex('publishers_supply_chain_review_status_index');
            $table->dropForeign(['supply_chain_reviewed_by']);
            $table->dropColumn(['business_domain', 'supply_chain_review_status', 'supply_chain_reviewed_at', 'supply_chain_reviewed_by']);
        });
    }
};
