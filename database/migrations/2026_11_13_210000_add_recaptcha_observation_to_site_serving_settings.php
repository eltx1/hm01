<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->boolean('recaptcha_observation_enabled')->default(false);
            $table->string('recaptcha_observation_site_key', 200)->nullable();
            $table->text('recaptcha_observation_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->dropColumn(['recaptcha_observation_enabled', 'recaptcha_observation_site_key', 'recaptcha_observation_secret']);
        });
    }
};
