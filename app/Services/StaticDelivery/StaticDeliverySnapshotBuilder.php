<?php

namespace App\Services\StaticDelivery;

use App\Models\ConfigVersion;
use App\Models\PlatformControl;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Models\SyntheticProbeResult;

final class StaticDeliverySnapshotBuilder
{
    public function __construct(
        private readonly CanonicalJson $canonicalJson,
        private readonly PublicPayloadGuard $payloadGuard,
        private readonly StaticPathGuard $pathGuard,
        private readonly SupplyChainArtifactBuilder $supplyChain,
    ) {
    }

    public function build(): StaticDeliverySnapshot
    {
        $files = array_merge($this->baseAssets(), $this->supplyChain->files());
        $generatedAt = $this->snapshotTimestamp();
        $retention = max(1, (int) config('static-delivery.retention_per_environment', 5));
        $versions = ConfigVersion::withoutGlobalScopes()
            ->with('site:id,public_key')
            ->orderBy('site_id')->orderBy('environment')->orderByDesc('version')
            ->get()
            ->groupBy(fn (ConfigVersion $version) => $version->site_id.'|'.$version->environment->value);

        $siteManifests = [];
        foreach ($versions as $group) {
            /** @var Collection<int, ConfigVersion> $group */
            $latest = $group->first();
            if (! $latest?->site) {
                continue;
            }
            $siteKey = $this->pathGuard->siteKey($latest->site->public_key);
            $environment = strtolower($latest->environment->value);
            foreach ($group->take($retention) as $version) {
                $this->payloadGuard->validate($version->payload);
                $encoded = $this->canonicalJson->encode($version->payload);
                if (! hash_equals($version->checksum, hash('sha256', $encoded))) {
                    throw new StaticDeliveryException('CHECKSUM_MISMATCH', "Stored configuration checksum mismatch for {$siteKey} v{$version->version}.");
                }
                $path = "configs/{$siteKey}/{$environment}.v{$version->version}.".substr($version->checksum, 0, 16).'.json';
                $files[$this->pathGuard->path($path)] = $encoded;
            }

            $current = $this->canonicalJson->encode($latest->payload);
            $immutablePath = "configs/{$siteKey}/{$environment}.v{$latest->version}.".substr($latest->checksum, 0, 16).'.json';
            $files[$this->pathGuard->path("configs/{$siteKey}/{$environment}.json")] = $current;
            $siteManifests[$siteKey]['siteKey'] = $siteKey;
            $siteManifests[$siteKey]['generatedAt'] = $latest->created_at->utc()->toIso8601String();
            $siteManifests[$siteKey]['environments'][$environment] = [
                'version' => $latest->version,
                'path' => '/'.$immutablePath,
                'sha256' => $latest->checksum,
            ];
        }

        foreach ($siteManifests as $siteKey => $manifest) {
            ksort($manifest['environments']);
            $files[$this->pathGuard->path("configs/{$siteKey}/manifest.json")] = $this->canonicalJson->encode($manifest);
        }

        $files['configs/_global/control.json'] = $this->canonicalJson->encode($this->globalControl());
        $files['health/delivery.json'] = $this->canonicalJson->encode($this->deliveryHealth());
        ksort($files);
        $manifestHash = $this->hashFiles($files);
        $files['delivery-manifest.json'] = $this->canonicalJson->encode([
            'schemaVersion' => 1,
            'manifestHash' => $manifestHash,
            'generatedAt' => $generatedAt,
            'fileCount' => count($files) + 1,
            'files' => collect($files)->map(fn (string $contents) => hash('sha256', $contents))->all(),
        ]);
        ksort($files);

        $hardLimit = max(1, (int) config('static-delivery.file_budget.hard_limit', 20000));
        $warning = min($hardLimit, max(1, (int) config('static-delivery.file_budget.warning_threshold', 18000)));
        $maxFileBytes = max(1, (int) config('static-delivery.file_budget.max_file_bytes', 26214400));
        foreach ($files as $path => $contents) {
            if (strlen($contents) > $maxFileBytes) {
                throw new StaticDeliveryException('FILE_SIZE_EXCEEDED', "Static file {$path} exceeds the configured Pages file-size limit.");
            }
        }
        if (count($files) > $hardLimit) {
            throw new StaticDeliveryException('FILE_BUDGET_EXCEEDED', "Static snapshot has ".count($files)." files; configured hard limit is {$hardLimit}.");
        }

        return new StaticDeliverySnapshot(
            files: $files,
            manifestHash: $manifestHash,
            totalBytes: array_sum(array_map('strlen', $files)),
            nearFileBudget: count($files) >= $warning,
        );
    }

    /** @return array<string, string> */
    private function baseAssets(): array
    {
        $loader = $this->readRequired(public_path('assets/hm-loader.min.js'));
        $loaderHash = hash('sha256', $loader);
        $prebid = $this->readRequired(public_path('assets/prebid/horus-prebid.min.js'));
        $prebidHash = hash('sha256', $prebid);

        return [
            'hm-loader.js' => $loader,
            'assets/hm-loader.min.js' => $loader,
            'assets/loader/hm-loader.'.substr($loaderHash, 0, 16).'.min.js' => $loader,
            'assets/prebid/horus-prebid.min.js' => $prebid,
            'assets/prebid/horus-prebid.'.substr($prebidHash, 0, 16).'.min.js' => $prebid,
            'assets/prebid/horus-prebid.sha256' => $prebidHash."\n",
            '_headers' => $this->headers(),
            '404.html' => "<!doctype html><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex\"><title>Not found</title>\n",
        ];
    }

    private function globalControl(): array
    {
        $records = PlatformControl::query()->where('scope_type', 'PLATFORM')->get()->keyBy('control_key');
        $changedAt = $records->max('changed_at');
        $timestamp = $changedAt ? Carbon::parse($changedAt)->utc() : Carbon::createFromTimestampUTC(0);

        return [
            'version' => $timestamp->getTimestampMs(),
            'generatedAt' => $timestamp->toIso8601String(),
            'controls' => [
                'adServingDisabled' => (bool) $records->get('AD_SERVING')?->is_disabled,
                'prebidDisabled' => (bool) $records->get('PREBID')?->is_disabled,
                'nativeDemandDisabled' => (bool) $records->get('NATIVE_DEMAND')?->is_disabled,
            ],
        ];
    }

    private function snapshotTimestamp(): string
    {
        $configTime = ConfigVersion::withoutGlobalScopes()->max('created_at');
        $controlTime = PlatformControl::query()->max('changed_at');
        $timestamps = array_filter([$configTime, $controlTime]);
        if ($timestamps === []) {
            return Carbon::createFromTimestampUTC(0)->toIso8601String();
        }

        return collect($timestamps)->map(fn ($value) => Carbon::parse($value)->utc())->sortDesc()->first()->toIso8601String();
    }

    private function deliveryHealth(): array
    {
        $latest = SyntheticProbeResult::withoutGlobalScopes()->latest('observed_at')->limit(100)->get()->unique('site_id')->values();
        return [
            'schemaVersion' => 1,
            'status' => $latest->isNotEmpty() && $latest->every(fn ($probe) => $probe->status === 'PASS') ? 'healthy' : 'unknown',
            'probeCount' => $latest->count(),
            'lastObservedAt' => $latest->max('observed_at')?->utc()->toIso8601String(),
        ];
    }

    private function headers(): string
    {
        return <<<'HEADERS'
/*
  Access-Control-Allow-Origin: *
  X-Content-Type-Options: nosniff

/hm-loader.js
  Cache-Control: public, max-age=300, stale-while-revalidate=86400
  Content-Type: application/javascript; charset=utf-8

/assets/loader/*
  Cache-Control: public, max-age=31536000, immutable
  Content-Type: application/javascript; charset=utf-8

/assets/prebid/*.min.js
  Cache-Control: public, max-age=31536000, immutable
  Content-Type: application/javascript; charset=utf-8

/assets/hm-loader.min.js
  Cache-Control: public, max-age=300, stale-while-revalidate=86400
  Content-Type: application/javascript; charset=utf-8

/assets/prebid/horus-prebid.min.js
  Cache-Control: public, max-age=300, stale-while-revalidate=86400
  Content-Type: application/javascript; charset=utf-8

/configs/_global/control.json
  Cache-Control: public, max-age=10, must-revalidate, stale-while-revalidate=30
  Content-Type: application/json; charset=utf-8
  X-Robots-Tag: noindex

/configs/*/manifest.json
  Cache-Control: public, max-age=15, must-revalidate, stale-while-revalidate=60
  Content-Type: application/json; charset=utf-8
  X-Robots-Tag: noindex

/configs/*/*.v*.json
  Cache-Control: public, max-age=31536000, immutable
  Content-Type: application/json; charset=utf-8
  X-Robots-Tag: noindex

/configs/*/*.json
  Cache-Control: public, max-age=30, must-revalidate, stale-while-revalidate=60
  Content-Type: application/json; charset=utf-8
  X-Robots-Tag: noindex

/supply/*
  Cache-Control: public, max-age=300, must-revalidate, stale-while-revalidate=3600
  X-Content-Type-Options: nosniff
HEADERS;
    }

    private function readRequired(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false || $contents === '') {
            throw new StaticDeliveryException('ASSET_MISSING', 'Required compiled static asset is missing: '.basename($path));
        }

        return $contents;
    }

    /** @param array<string, string> $files */
    private function hashFiles(array $files): string
    {
        $context = hash_init('sha256');
        foreach ($files as $path => $contents) {
            hash_update($context, $path."\0".hash('sha256', $contents)."\n");
        }

        return hash_final($context);
    }
}
