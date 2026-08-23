<?php

use App\Services\Settings\GlobalSettingsService;
use App\Services\StaticDelivery\SupplyChainStaticPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('global_settings')->updateOrInsert(
            ['key' => 'supply_chain.contact_email'],
            [
                'value' => json_encode('mohamed@horusmedia.net', JSON_THROW_ON_ERROR),
                'changed_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        app(GlobalSettingsService::class)->invalidate();
        app(SupplyChainStaticPublisher::class)->queueUrgent([
            'event' => 'PUBLIC_CONTACT_EMAIL_MIGRATED',
            'setting_key' => 'supply_chain.contact_email',
        ]);
    }

    public function down(): void
    {
        // Never restore the obsolete public adops contact.
    }
};
