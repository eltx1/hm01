<?php

namespace App\Console\Commands;

use App\Services\Sites\PublisherSetupReminder;
use Illuminate\Console\Command;

final class RemindPublisherSetup extends Command
{
    protected $signature = 'sites:remind-setup {--apply : Queue reminders; default is a read-only dry run}';
    protected $description = 'Remind eligible publishers of their pending setup action using existing notification preferences.';

    public function handle(PublisherSetupReminder $reminders): int
    {
        $result = $reminders->run((bool) $this->option('apply'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
