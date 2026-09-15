<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table): void {
            $table->string('referral_code', 32)->nullable()->unique()->after('id');
            $table->foreignUlid('referred_by_publisher_id')->nullable()->after('referral_code')
                ->constrained('publishers')->nullOnDelete();
            $table->unsignedInteger('affiliate_commission_override_bp')->nullable()->after('referred_by_publisher_id');
            $table->timestamp('referred_at')->nullable()->after('affiliate_commission_override_bp');
            $table->index('referred_by_publisher_id', 'publishers_referrer_index');
        });

        DB::table('publishers')->select('id')->orderBy('id')->get()->each(function (object $publisher): void {
            DB::table('publishers')->where('id', $publisher->id)->update([
                'referral_code' => 'HM'.strtoupper(substr(hash('sha256', 'publisher-referral:'.$publisher->id), 0, 20)),
            ]);
        });

        Schema::table('publisher_statements', function (Blueprint $table): void {
            $table->bigInteger('affiliate_earnings_minor')->default(0)->after('publisher_earnings_minor');
        });

        Schema::create('publisher_affiliate_commissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('referrer_publisher_id')->constrained('publishers')->restrictOnDelete();
            $table->foreignUlid('referred_publisher_id')->constrained('publishers')->restrictOnDelete();
            $table->foreignUlid('source_publisher_statement_id')->unique()->constrained('publisher_statements')->restrictOnDelete();
            $table->foreignUlid('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('basis_minor');
            $table->unsignedInteger('commission_rate_bp');
            $table->bigInteger('commission_minor');
            $table->string('status', 24)->default('EARNED')->index();
            $table->json('metadata')->nullable();
            $table->foreignUlid('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['referrer_publisher_id', 'financial_period_id'], 'publisher_affiliate_referrer_period');
            $table->index(['referred_publisher_id', 'financial_period_id'], 'publisher_affiliate_referred_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_affiliate_commissions');

        Schema::table('publisher_statements', function (Blueprint $table): void {
            $table->dropColumn('affiliate_earnings_minor');
        });

        Schema::table('publishers', function (Blueprint $table): void {
            $table->dropIndex('publishers_referrer_index');
            $table->dropForeign(['referred_by_publisher_id']);
            $table->dropUnique(['referral_code']);
            $table->dropColumn([
                'referral_code',
                'referred_by_publisher_id',
                'affiliate_commission_override_bp',
                'referred_at',
            ]);
        });
    }
};
