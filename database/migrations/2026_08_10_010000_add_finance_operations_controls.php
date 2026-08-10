<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publisher_payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('payment_number')->unique();
            $table->string('failure_reason', 1000)->nullable()->after('publisher_message');
            $table->timestamp('failed_at')->nullable()->after('paid_at');
            $table->foreignUlid('failed_by')->nullable()->after('failed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('held_at')->nullable()->after('failed_by');
            $table->foreignUlid('held_by')->nullable()->after('held_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('publisher_payment_settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_payment_id')->constrained('publisher_payments')->restrictOnDelete();
            $table->string('settlement_reference', 255);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->timestamp('settled_on');
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique('settlement_reference', 'publisher_payment_settlements_reference_unique');
            $table->index(['publisher_id', 'settled_on'], 'publisher_payment_settlements_publisher');
        });

        Schema::table('financial_periods', function (Blueprint $table): void {
            $table->json('readiness_snapshot')->nullable()->after('totals');
            $table->string('close_override_reason', 2000)->nullable()->after('readiness_snapshot');
            $table->timestamp('close_override_at')->nullable()->after('close_override_reason');
            $table->foreignUlid('close_override_by')->nullable()->after('close_override_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('revenue_adjustments', function (Blueprint $table): void {
            $table->string('decision_reason', 2000)->nullable()->after('reason');
        });

        Schema::table('reconciliation_runs', function (Blueprint $table): void {
            $table->string('remediation_note', 2000)->nullable()->after('error_message');
            $table->timestamp('remediated_at')->nullable()->after('remediation_note');
            $table->foreignUlid('remediated_by')->nullable()->after('remediated_at')->constrained('users')->nullOnDelete();
        });

        foreach (DB::table('publisher_payments')->where('settled_amount_minor', '>', 0)->orderBy('id')->get() as $payment) {
            $reference = trim((string) ($payment->horus_payment_reference ?: 'LEGACY-'.$payment->payment_number));
            $deduplicated = DB::table('publisher_payment_settlements')
                ->where('settlement_reference', $reference)
                ->exists();
            if ($deduplicated) {
                $reference = 'LEGACY-DUP-'.$payment->id;
            }

            DB::table('publisher_payment_settlements')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $payment->organization_id,
                'publisher_id' => $payment->publisher_id,
                'publisher_payment_id' => $payment->id,
                'settlement_reference' => $reference,
                'amount_minor' => $payment->settled_amount_minor,
                'currency' => $payment->currency,
                'settled_on' => $payment->paid_at ?: $payment->updated_at,
                'recorded_by' => $payment->processed_by,
                'metadata' => json_encode([
                    'source' => 'TASK_6_BACKFILL',
                    'legacy_reference_deduplicated' => $deduplicated,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $payment->paid_at ?: $payment->updated_at,
                'updated_at' => $payment->paid_at ?: $payment->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('reconciliation_runs', function (Blueprint $table): void {
            $table->dropForeign(['remediated_by']);
            $table->dropColumn(['remediation_note', 'remediated_at', 'remediated_by']);
        });
        Schema::table('revenue_adjustments', function (Blueprint $table): void {
            $table->dropColumn('decision_reason');
        });
        Schema::table('financial_periods', function (Blueprint $table): void {
            $table->dropForeign(['close_override_by']);
            $table->dropColumn(['readiness_snapshot', 'close_override_reason', 'close_override_at', 'close_override_by']);
        });
        Schema::dropIfExists('publisher_payment_settlements');
        Schema::table('publisher_payments', function (Blueprint $table): void {
            $table->dropForeign(['failed_by']);
            $table->dropForeign(['held_by']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'failure_reason', 'failed_at', 'failed_by', 'held_at', 'held_by']);
        });
    }
};
