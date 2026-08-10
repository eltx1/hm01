<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publisher_payment_profiles', function (Blueprint $table): void {
            $table->string('verification_status', 32)->default('INCOMPLETE')->after('is_verified')->index();
            $table->timestamp('verification_requested_at')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verification_requested_at');
            $table->foreignUlid('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->string('verification_reason', 1000)->nullable()->after('verified_by');
        });

        Schema::table('publisher_statements', function (Blueprint $table): void {
            $table->string('publisher_invoice_status', 24)->default('NOT_REQUIRED')->after('publisher_invoice_uploaded_by')->index();
            $table->timestamp('publisher_invoice_reviewed_at')->nullable()->after('publisher_invoice_status');
            $table->foreignUlid('publisher_invoice_reviewed_by')->nullable()->after('publisher_invoice_reviewed_at')->constrained('users')->nullOnDelete();
            $table->string('publisher_invoice_review_reason', 1000)->nullable()->after('publisher_invoice_reviewed_by');
        });

        Schema::table('publisher_payments', function (Blueprint $table): void {
            $table->bigInteger('settled_amount_minor')->default(0)->after('amount_minor');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('publisher_message', 1000)->nullable()->after('notes');
        });

        DB::table('publisher_payment_profiles')->where('is_verified', true)->update([
            'verification_status' => 'VERIFIED',
            'verified_at' => DB::raw('updated_at'),
        ]);
        DB::table('publisher_payment_profiles')->where('is_verified', false)->whereNotNull('payment_details')->update([
            'verification_status' => 'PENDING_VERIFICATION',
            'verification_requested_at' => DB::raw('updated_at'),
        ]);
        DB::table('publisher_statements')->whereNotNull('publisher_invoice_path')->update([
            'publisher_invoice_status' => 'RECEIVED',
        ]);
        DB::table('publisher_statements')->whereNull('publisher_invoice_path')->where('status', 'PENDING_INVOICE')->update([
            'publisher_invoice_status' => 'REQUIRED',
        ]);
        DB::table('publisher_payments')->whereIn('status', ['PAID', 'PARTIALLY_PAID'])->update([
            'settled_amount_minor' => DB::raw('amount_minor'),
        ]);
    }

    public function down(): void
    {
        Schema::table('publisher_payments', function (Blueprint $table): void {
            $table->dropColumn(['settled_amount_minor', 'approved_at', 'publisher_message']);
        });
        Schema::table('publisher_statements', function (Blueprint $table): void {
            $table->dropForeign(['publisher_invoice_reviewed_by']);
            $table->dropColumn([
                'publisher_invoice_status', 'publisher_invoice_reviewed_at',
                'publisher_invoice_reviewed_by', 'publisher_invoice_review_reason',
            ]);
        });
        Schema::table('publisher_payment_profiles', function (Blueprint $table): void {
            $table->dropForeign(['verified_by']);
            $table->dropColumn([
                'verification_status', 'verification_requested_at', 'verified_at',
                'verified_by', 'verification_reason',
            ]);
        });
    }
};
