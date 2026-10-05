<?php

namespace App\Console\Commands;

use App\Enums\ConfigEnvironment;
use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Console\Command;

final class RefreshQuickRuntimeConfigs extends Command
{
    protected $signature = 'demand:refresh-quick-runtimes {--apply : Queue changed runtime URLs or Responsive Display sizes; otherwise preview only}';
    protected $description = 'Refresh immutable Direct Demand runtime references after a release for all active publishers';

    public function handle(SiteConfigPublisher $publisher): int
    {
        $assets = ['gpt' => 'hm-gpt-direct.js', 'video' => 'hm-video-direct.js', 'direct' => 'hm-isolated-direct.js'];
        $hashes = [];
        foreach ($assets as $kind => $file) $hashes[$kind] = substr(hash_file('sha256', public_path('assets/'.$file)), 0, 16);
        foreach (Site::withoutGlobalScopes()->whereNull('deleted_at')->where('status', SiteStatus::Active->value)->cursor() as $site) {
            $latest = $site->configVersions()->where('environment', ConfigEnvironment::Production->value)->orderByDesc('version')->first();
            if (! $latest) continue;
            $stale = false;
            $oldUrls = [];
            foreach ((array) data_get($latest->payload, 'directDemand.placements', []) as $placement) {
                foreach ((array) ($placement['candidates'] ?? []) as $candidate) {
                    foreach ((array) data_get($candidate, 'tag.scripts', []) as $script) {
                        $url = (string) ($script['url'] ?? '');
                        // Only Horus-owned canonical runtime URLs; provider URLs are never rewritten.
                        if (parse_url($url, PHP_URL_HOST) !== parse_url(config('horus.cdn_url') ?: 'https://cdn.horusmedia.net', PHP_URL_HOST)) continue;
                        $oldUrls[] = $url;
                        if (preg_match('#/runtime/(gpt|video|direct)/hm-(?:gpt|video|isolated)-direct\.([a-f0-9]{16})\.js$#', $url, $match)
                            && $hashes[$match[1]] !== $match[2]) $stale = true;
                    }
                }
            }
            $responsiveCodes = $site->placements()->withoutGlobalScopes()->whereNull('deleted_at')->get(['code', 'type', 'metadata'])
                ->filter(fn ($placement) => $placement->type->value === 'DISPLAY'
                    && data_get($placement->metadata, 'placement_preset') === 'responsive_display')
                ->pluck('code')->all();
            if (! $stale && $responsiveCodes === []) continue;
            $preview = $publisher->preview($site, ConfigEnvironment::Production);
            $newUrls = [];
            foreach ((array) data_get($preview, 'directDemand.placements', []) as $placement) {
                foreach ((array) ($placement['candidates'] ?? []) as $candidate) {
                    foreach ((array) data_get($candidate, 'tag.scripts', []) as $script) {
                        $url = (string) ($script['url'] ?? '');
                        if (parse_url($url, PHP_URL_HOST) === parse_url(config('horus.cdn_url') ?: 'https://cdn.horusmedia.net', PHP_URL_HOST)) $newUrls[] = $url;
                    }
                }
            }
            sort($oldUrls); sort($newUrls);
            $sizesChanged = $this->responsiveSizes($latest->payload, $responsiveCodes)
                != $this->responsiveSizes($preview, $responsiveCodes);
            if ($oldUrls === $newUrls && ! $sizesChanged) continue;
            $this->line(($this->option('apply') ? 'Queue: ' : 'Would queue: ').$site->public_key);
            if (! $this->option('apply')) continue;
            $actor = User::withoutGlobalScopes()->find($latest->created_by);
            if (! $actor) { $this->error('Original configuration actor is unavailable; refusing an unaudited update.'); return self::FAILURE; }
            $publisher->publishActiveProduction($site, $actor, StaticDeliveryPriority::Urgent);
        }
        return self::SUCCESS;
    }

    private function responsiveSizes(array $payload, array $codes): array
    {
        $sizes = [];
        foreach ((array) ($payload['placements'] ?? []) as $placement) {
            $code = $placement['code'] ?? '';
            if (! in_array($code, $codes, true)) continue;
            $sizes[$code] = [$placement['sizes'] ?? [], $placement['responsiveMappings'] ?? []];
        }
        ksort($sizes);

        return $sizes;
    }
}
