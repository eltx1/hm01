<?php

namespace App\Console\Commands;

use App\Enums\ConfigEnvironment;
use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\TrafficGate\TrafficGateConfigurationResolver;
use Illuminate\Console\Command;

final class RefreshTrafficGateConfigs extends Command
{
    protected $signature = 'traffic-gate:refresh-configs {--apply : Publish changed gate configuration; default is dry-run}';
    protected $description = 'Republish changed Traffic Gate contracts for active publishers without changing ad inventory';

    public function handle(SiteConfigPublisher $publisher, TrafficGateConfigurationResolver $resolver): int
    {
        foreach (Site::withoutGlobalScopes()->where('status', SiteStatus::Active->value)->whereNull('deleted_at')->cursor() as $site) {
            $latest = $site->configVersions()->where('environment', ConfigEnvironment::Production->value)->orderByDesc('version')->first();
            if (! $latest) continue;
            $next = $resolver->resolve($site)->toPublicArray();
            if (data_get($latest->payload, 'trafficGate') == $next) continue;
            $this->line(($this->option('apply') ? 'Publish: ' : 'Would publish: ').$site->public_key);
            if (! $this->option('apply')) continue;
            $actor = User::withoutGlobalScopes()->find($latest->created_by);
            if (! $actor) {
                $this->error('Original configuration actor is unavailable; refusing an unaudited update.');
                return self::FAILURE;
            }
            $publisher->publishActiveProduction($site, $actor, StaticDeliveryPriority::Urgent);
        }
        return self::SUCCESS;
    }
}
