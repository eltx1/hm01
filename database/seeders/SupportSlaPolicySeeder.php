<?php

namespace Database\Seeders;

use App\Enums\SupportTicketPriority;
use App\Models\SupportSlaPolicy;
use Illuminate\Database\Seeder;

class SupportSlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        foreach (SupportTicketPriority::cases() as $priority) {
            $settings = config('support.sla.'.$priority->value);
            SupportSlaPolicy::query()->updateOrCreate(
                ['priority' => $priority->value],
                $settings + ['pause_while_waiting_on_customer' => true, 'is_active' => true],
            );
        }
    }
}
