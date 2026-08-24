<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->string('ads_txt_redirect_status', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->string('ads_txt_redirect_status', 32)->nullable()->change();
        });
    }
};
