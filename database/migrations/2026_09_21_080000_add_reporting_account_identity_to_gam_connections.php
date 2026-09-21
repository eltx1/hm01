<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gam_connections', function (Blueprint $table): void {
            $table->boolean('is_reporting_only')->default(false);
            $table->string('reporting_account_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('gam_connections', function (Blueprint $table): void {
            $table->dropUnique(['reporting_account_key']);
            $table->dropColumn(['is_reporting_only', 'reporting_account_key']);
        });
    }
};
