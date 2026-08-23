<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publisher_applications', function (Blueprint $table): void {
            $table->string('primary_domain', 253)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('publisher_applications', function (Blueprint $table): void {
            $table->string('primary_domain', 253)->nullable(false)->change();
        });
    }
};
