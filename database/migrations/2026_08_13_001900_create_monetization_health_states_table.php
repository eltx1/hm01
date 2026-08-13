<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monetization_health_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id')->index();
            $table->string('state_key', 160);
            $table->string('status', 48);
            $table->string('fingerprint', 64)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'state_key']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monetization_health_states');
    }
};
