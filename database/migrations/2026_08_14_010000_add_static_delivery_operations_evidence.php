<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_delivery_batches', function (Blueprint $table): void {
            $table->string('trigger', 16)->default('SCHEDULED')->after('priority')->index();
            $table->boolean('is_deduplicated')->default(false)->after('manifest_hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('static_delivery_batches', function (Blueprint $table): void {
            $table->dropIndex(['trigger']);
            $table->dropIndex(['is_deduplicated']);
            $table->dropColumn(['trigger', 'is_deduplicated']);
        });
    }
};
