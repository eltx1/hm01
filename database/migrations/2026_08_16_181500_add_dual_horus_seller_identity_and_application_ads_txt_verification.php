<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_declarations', function (Blueprint $table): void {
            $table->string('identity_scope', 16)->default('PUBLISHER')->after('identity_source')->index();
            $table->ulid('publisher_application_domain_claim_id')->nullable()->after('site_id')->unique('seller_application_domain_claim_unique');
            $table->index(['publisher_id', 'identity_scope'], 'seller_publisher_identity_scope_idx');
            $table->index(['site_id', 'identity_scope'], 'seller_site_identity_scope_idx');
        });

        Schema::table('publisher_application_domain_claims', function (Blueprint $table): void {
            $table->ulid('publisher_seller_declaration_id')->nullable()->after('normalized_domain')->index();
            $table->ulid('website_seller_declaration_id')->nullable()->after('publisher_seller_declaration_id')->unique('application_domain_website_seller_unique');
            $table->string('verification_status', 24)->default('PENDING')->after('claim_status')->index();
            $table->timestamp('verification_requested_at')->nullable()->after('claimed_at');
            $table->timestamp('last_checked_at')->nullable()->after('verification_requested_at');
            $table->timestamp('verified_at')->nullable()->after('last_checked_at');
            $table->text('final_ads_txt_url')->nullable()->after('verified_at');
            $table->unsignedSmallInteger('verification_http_status')->nullable()->after('final_ads_txt_url');
            $table->string('verification_content_type', 255)->nullable()->after('verification_http_status');
            $table->string('evidence_sha256', 64)->nullable()->after('verification_content_type')->index();
            $table->string('failure_code', 80)->nullable()->after('evidence_sha256');
            $table->unsignedInteger('verification_attempt_count')->default(0)->after('failure_code');
        });
    }

    public function down(): void
    {
        Schema::table('publisher_application_domain_claims', function (Blueprint $table): void {
            $table->dropUnique('application_domain_website_seller_unique');
            $table->dropIndex(['publisher_seller_declaration_id']);
            $table->dropIndex(['verification_status']);
            $table->dropIndex(['evidence_sha256']);
            $table->dropColumn([
                'publisher_seller_declaration_id', 'website_seller_declaration_id',
                'verification_status', 'verification_requested_at', 'last_checked_at',
                'verified_at', 'final_ads_txt_url', 'verification_http_status',
                'verification_content_type', 'evidence_sha256', 'failure_code',
                'verification_attempt_count',
            ]);
        });

        Schema::table('seller_declarations', function (Blueprint $table): void {
            $table->dropUnique('seller_application_domain_claim_unique');
            $table->dropIndex('seller_publisher_identity_scope_idx');
            $table->dropIndex('seller_site_identity_scope_idx');
            $table->dropIndex(['identity_scope']);
            $table->dropColumn(['publisher_application_domain_claim_id', 'identity_scope']);
        });
    }
};
