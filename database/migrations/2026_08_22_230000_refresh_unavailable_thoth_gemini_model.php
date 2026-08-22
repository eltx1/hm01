<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacyModels = ['gemini-2.5-flash-lite', 'gemini-2.5-flash', 'gemini-2.5-pro'];

        DB::table('ai_provider_connections')
            ->where('provider', 'GEMINI')
            ->whereIn('model', $legacyModels)
            ->where('status', 'NOT_CONFIGURED')
            ->update([
                'model' => 'gemini-3.1-flash-lite',
                'updated_at' => now(),
            ]);

        DB::table('ai_provider_connections')
            ->where('provider', 'GEMINI')
            ->whereIn('model', $legacyModels)
            ->where('status', 'ERROR')
            ->where('last_error_code', 'MODEL_UNAVAILABLE')
            ->update([
                'model' => 'gemini-3.1-flash-lite',
                'status' => 'UNTESTED',
                'last_error_code' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not restore a provider model that the account reported unavailable.
    }
};
