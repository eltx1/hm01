<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_chain_checks', function (Blueprint $table): void {
            $table->string('required_checksum', 64)->nullable()->after('checksum');
            $table->string('snapshot_hash', 64)->nullable()->after('required_checksum');
            $table->longText('response_body')->nullable()->after('snapshot_hash');
            $table->unsignedInteger('response_bytes')->nullable()->after('response_body');
            $table->unsignedInteger('duration_ms')->nullable()->after('response_bytes');
            $table->string('content_type', 255)->nullable()->after('duration_ms');
            $table->string('trigger', 24)->default('SCHEDULED')->after('content_type');
            $table->foreignUlid('initiated_by')->nullable()->after('trigger')->constrained('users')->nullOnDelete();
            $table->string('final_url', 1000)->nullable()->after('initiated_by');
            $table->timestamp('first_checked_at')->nullable()->after('checked_at');
            $table->unsignedInteger('occurrence_count')->default(1)->after('first_checked_at');
            $table->index(['site_id', 'check_type', 'snapshot_hash'], 'supply_checks_snapshot_lookup');
        });

        DB::table('supply_chain_checks')->whereNull('first_checked_at')
            ->update(['first_checked_at' => DB::raw('checked_at')]);
    }

    public function down(): void
    {
        Schema::table('supply_chain_checks', function (Blueprint $table): void {
            $table->dropIndex('supply_checks_snapshot_lookup');
            $table->dropForeign(['initiated_by']);
            $table->dropColumn([
                'required_checksum', 'snapshot_hash', 'response_body', 'response_bytes',
                'duration_ms', 'content_type', 'trigger', 'initiated_by', 'final_url',
                'first_checked_at', 'occurrence_count',
            ]);
        });
    }
};
