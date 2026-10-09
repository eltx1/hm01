<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_configs', function (Blueprint $table): void {
            // Null means accompanying (plcmt=2); never copy the old global value.
            $table->string('video_inventory_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_configs', function (Blueprint $table): void {
            $table->dropColumn('video_inventory_type');
        });
    }
};
