<?php

use App\Services\StaticDelivery\SupplyChainStaticPublisher;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('production') && ! app()->runningUnitTests()) {
            app(SupplyChainStaticPublisher::class)->queueUrgent([
                'event' => 'TASK68_SUPPLY_CHAIN_HARDENING_DEPLOYED',
                'reason' => 'Rebuild sellers.json and supply-chain artifacts with repaired Publisher identity publication rules.',
            ]);
        }
    }

    public function down(): void
    {
        // Static supply-chain publication is append-only operational work; never roll it back.
    }
};
