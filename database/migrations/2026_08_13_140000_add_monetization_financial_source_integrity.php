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
        foreach ([
            'EXOCLICK' => ['name' => 'ExoClick', 'capabilities' => ['API', 'CSV', 'MANUAL', 'FINALIZED_API', 'FINALIZED_CSV', 'FINALIZED_MANUAL']],
            'ONETAG' => ['name' => 'OneTag', 'capabilities' => ['CSV', 'MANUAL', 'ESTIMATED', 'FINALIZED_CSV', 'FINALIZED_MANUAL']],
        ] as $code => $definition) {
            if (! DB::table('report_sources')->where('code', $code)->exists()) {
                DB::table('report_sources')->insert([
                    'id' => (string) Str::ulid(),
                    'code' => $code,
                    'name' => $definition['name'],
                    'is_primary' => false,
                    'is_enabled' => true,
                    'capabilities' => json_encode($definition['capabilities'], JSON_THROW_ON_ERROR),
                    'metadata' => json_encode(['introduced_by' => 'TASK_21'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::create('monetization_financial_bindings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 32);
            $table->string('subject_id', 64);
            $table->foreignUlid('report_source_id')->constrained('report_sources')->restrictOnDelete();
            $table->foreignUlid('report_source_connection_id')->nullable()->constrained('report_source_connections')->nullOnDelete();
            $table->string('reporting_method', 16);
            $table->string('currency', 3);
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_finalized_capable')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('configuration')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id'], 'monetization_financial_binding_subject_unique');
            $table->index(['organization_id', 'is_enabled'], 'monetization_financial_binding_org_enabled');
            $table->index(['report_source_id', 'reporting_method'], 'monetization_financial_binding_source_method');
        });

        Schema::table('report_import_jobs', function (Blueprint $table): void {
            $table->boolean('settlement_eligible')->default(false)->after('finality')->index();
            $table->string('settlement_ineligibility_reason', 64)->nullable()->after('settlement_eligible');
        });
        Schema::table('hourly_reports', function (Blueprint $table): void {
            $table->boolean('settlement_eligible')->default(false)->after('finality')->index();
        });
        Schema::table('daily_reports', function (Blueprint $table): void {
            $table->boolean('settlement_eligible')->default(false)->after('finality')->index();
        });
        Schema::table('monthly_reports', function (Blueprint $table): void {
            $table->boolean('settlement_eligible')->default(false)->after('currency')->index();
        });

        $ineligibleConnectionIds = DB::table('report_source_connections')
            ->whereIn('connection_type', ['DEMAND_ACCOUNT', 'BIDDER_ACCOUNT'])
            ->pluck('id');
        $estimateSourceIds = DB::table('report_sources')->where('code', 'PREBID_ESTIMATES')->pluck('id');
        $ineligibleConnectionIds = $ineligibleConnectionIds->merge(
            DB::table('report_source_connections')->whereIn('report_source_id', $estimateSourceIds)->pluck('id')
        )->unique()->values();

        if ($ineligibleConnectionIds->isNotEmpty()) {
            DB::table('report_import_jobs')->whereIn('report_source_connection_id', $ineligibleConnectionIds)->update([
                'settlement_eligible' => false,
                'settlement_ineligibility_reason' => 'LEGACY_UNBOUND_OR_ESTIMATE_SOURCE',
            ]);
            DB::table('hourly_reports')->whereIn('report_source_connection_id', $ineligibleConnectionIds)->update(['settlement_eligible' => false]);
            DB::table('daily_reports')->whereIn('report_source_connection_id', $ineligibleConnectionIds)->update(['settlement_eligible' => false]);
            DB::table('monthly_reports')->whereIn('report_source_connection_id', $ineligibleConnectionIds)->update(['settlement_eligible' => false]);
        }

        $eligibleConnectionIds = DB::table('report_source_connections')
            ->when($ineligibleConnectionIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $ineligibleConnectionIds))
            ->pluck('id');
        if ($eligibleConnectionIds->isNotEmpty()) {
            DB::table('report_import_jobs')->whereIn('report_source_connection_id', $eligibleConnectionIds)->where('finality', 'FINALIZED')->update(['settlement_eligible' => true]);
            DB::table('hourly_reports')->whereIn('report_source_connection_id', $eligibleConnectionIds)->where('finality', 'FINALIZED')->update(['settlement_eligible' => true]);
            DB::table('daily_reports')->whereIn('report_source_connection_id', $eligibleConnectionIds)->where('finality', 'FINALIZED')->update(['settlement_eligible' => true]);
            DB::table('monthly_reports')->whereIn('report_source_connection_id', $eligibleConnectionIds)->update(['settlement_eligible' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('monthly_reports', fn (Blueprint $table) => $table->dropIndex(['settlement_eligible']));
        Schema::table('monthly_reports', fn (Blueprint $table) => $table->dropColumn('settlement_eligible'));
        Schema::table('daily_reports', fn (Blueprint $table) => $table->dropIndex(['settlement_eligible']));
        Schema::table('daily_reports', fn (Blueprint $table) => $table->dropColumn('settlement_eligible'));
        Schema::table('hourly_reports', fn (Blueprint $table) => $table->dropIndex(['settlement_eligible']));
        Schema::table('hourly_reports', fn (Blueprint $table) => $table->dropColumn('settlement_eligible'));
        Schema::table('report_import_jobs', fn (Blueprint $table) => $table->dropIndex(['settlement_eligible']));
        Schema::table('report_import_jobs', fn (Blueprint $table) => $table->dropColumn(['settlement_eligible', 'settlement_ineligibility_reason']));
        Schema::dropIfExists('monetization_financial_bindings');
    }
};
