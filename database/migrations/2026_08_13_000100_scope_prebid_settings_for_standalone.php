<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prebid_settings', function (Blueprint $table): void {
            $table->string('scope', 32)->default('GAM_CONNECTION')->after('organization_id')->index();
            $table->foreignUlid('site_id')->nullable()->after('gam_connection_id')->constrained('sites')->cascadeOnDelete();
        });

        // Existing rows are deterministically connection-owned. No remote GAM
        // mapping, price bucket, or setup-run record is rewritten or deleted.
        DB::table('prebid_settings')->update(['scope' => 'GAM_CONNECTION']);

        Schema::table('prebid_settings', function (Blueprint $table): void {
            $table->foreignUlid('gam_connection_id')->nullable()->change();
            $table->unique('site_id', 'prebid_settings_site_unique');
            $table->index(['scope', 'site_id'], 'prebid_settings_scope_site_index');
        });
    }

    public function down(): void
    {
        // Standalone-only profiles cannot exist in the old connection-only
        // schema. Removing them is required only when explicitly rolling this
        // migration back; established GAM profiles remain untouched.
        DB::table('prebid_settings')->where('scope', 'SITE_STANDALONE')->delete();

        Schema::table('prebid_settings', function (Blueprint $table): void {
            $table->dropUnique('prebid_settings_site_unique');
            $table->dropIndex('prebid_settings_scope_site_index');
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
            $table->dropIndex(['scope']);
            $table->dropColumn('scope');
            $table->foreignUlid('gam_connection_id')->nullable(false)->change();
        });
    }
};
