<?php

namespace App\Console\Commands;

use App\Enums\DemandApprovalStatus;
use App\Enums\DemandNetworkCode;
use App\Enums\PlacementStatus;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Models\Site;
use App\Models\User;
use App\Services\Demand\GoogleGptManualTagParser;
use App\Services\Demand\QuickMonetizeService;
use App\Services\Inventory\PlacementPresetCatalog;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CloneQuickMonetizeDisplaySuite extends Command
{
    /** @var list<string> */
    public const SUPPORTED_PRESETS = [
        'responsive_display',
        'in_article_display',
        'high_impact_display',
        'mobile_display',
        'sticky_top',
        'side_rail_right',
        'side_rail_left',
    ];

    protected $signature = 'quick-monetize:clone-display-suite
        {siteKey : Horus public site key}
        {--from=quick_sticky_bottom : Existing Quick Monetize placement used as the trusted GPT blueprint}
        {--preset=* : Explicit display/edge preset(s) to clone}
        {--actor= : Optional user id override for audit attribution}';

    protected $description = 'Clone one reviewed Quick Monetize GPT setup into explicitly selected size-compatible display/edge presets';

    public function handle(
        QuickMonetizeService $quick,
        PlacementPresetCatalog $catalog,
        GoogleGptManualTagParser $gptParser,
    ): int {
        $siteKey = trim((string) $this->argument('siteKey'));
        $sourceCode = trim((string) $this->option('from'));
        if ($siteKey === '' || $sourceCode === '') {
            $this->error('A site key and source placement code are required.');
            return self::FAILURE;
        }

        $presets = array_values(array_unique(array_filter(array_map(
            fn ($preset): string => trim((string) $preset),
            (array) $this->option('preset'),
        ))));
        if ($presets === []) {
            $this->error('Refusing an implicit display-suite rollout. Pass at least one explicit --preset=<surface>.');
            return self::FAILURE;
        }

        $unsupported = array_values(array_diff($presets, self::SUPPORTED_PRESETS));
        if ($unsupported !== []) {
            $this->error('Unsupported display-suite preset(s): '.implode(', ', $unsupported).'. Video/native/provider-managed formats require their own compatible demand tag and are intentionally excluded.');
            return self::FAILURE;
        }

        $site = Site::withoutGlobalScopes()->where('public_key', $siteKey)->whereNull('deleted_at')->first();
        if (! $site) {
            $this->error("Site [{$siteKey}] was not found.");
            return self::FAILURE;
        }

        $source = Placement::withoutGlobalScopes()->withTrashed()
            ->where('site_id', $site->id)
            ->where('code', $sourceCode)
            ->first();
        if (! $source && $sourceCode === 'quick_sticky_bottom') {
            $source = Placement::withoutGlobalScopes()->withTrashed()
                ->where('site_id', $site->id)
                ->get()
                ->first(fn (Placement $placement): bool => (bool) data_get($placement->metadata, 'quick_monetize_generated', false)
                    && (string) data_get($placement->metadata, 'placement_preset', '') === 'sticky_bottom');
        }
        if (! $source || $source->trashed() || $source->status !== PlacementStatus::Active) {
            $this->error("Source placement [{$sourceCode}] must exist and be active on [{$siteKey}].");
            return self::FAILURE;
        }

        $network = DemandNetwork::query()->where('code', DemandNetworkCode::CustomThirdPartyTag->value)->first();
        if (! $network) {
            $this->error('The CUSTOM_THIRD_PARTY_TAG network is unavailable.');
            return self::FAILURE;
        }

        $accounts = DemandAccount::withoutGlobalScopes()
            ->where('demand_network_id', $network->id)
            ->where('publisher_id', $site->publisher_id)
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (DemandAccount $candidate): bool => (bool) data_get($candidate->configuration, 'quick_monetize_managed', false));
        $demandSites = DemandSite::withoutGlobalScopes()
            ->whereIn('demand_account_id', $accounts->pluck('id'))
            ->where('site_id', $site->id)
            ->where('is_enabled', true)
            ->get();
        $demandPlacement = DemandPlacement::withoutGlobalScopes()
            ->whereIn('demand_site_id', $demandSites->pluck('id'))
            ->where('placement_id', $source->id)
            ->where('is_enabled', true)
            ->latest('id')
            ->first();
        $demandSite = $demandPlacement
            ? $demandSites->firstWhere('id', $demandPlacement->demand_site_id)
            : null;
        $account = $demandSite
            ? $accounts->firstWhere('id', $demandSite->demand_account_id)
            : null;
        $widget = $demandPlacement
            ? DemandWidget::withoutGlobalScopes()
                ->where('demand_placement_id', $demandPlacement->id)
                ->where('approval_status', DemandApprovalStatus::Approved->value)
                ->where('is_enabled', true)
                ->latest('id')
                ->get()
                ->first(fn (DemandWidget $candidate): bool => (bool) data_get($candidate->configuration, 'quick_monetize_managed', false)
                    && trim((string) $candidate->direct_tag_template) !== '')
            : null;

        if (! $widget || ! $demandSite || ! $account) {
            $this->error('The source placement has no enabled, approved Quick Monetize mapping/widget to clone.');
            return self::FAILURE;
        }

        try {
            $blueprint = $gptParser->parse((string) $widget->direct_tag_template);
        } catch (Throwable $exception) {
            $this->error('The source Quick Monetize tag is not a reusable canonical Google GPT tag: '.$exception->getMessage());
            return self::FAILURE;
        }
        if (! is_array($blueprint)) {
            $this->error('The source Quick Monetize tag is not Google GPT. This command intentionally clones only reviewed display GPT inventory.');
            return self::FAILURE;
        }

        $sourceSizes = collect((array) ($blueprint['sizes'] ?? []))
            ->filter(fn ($size): bool => is_array($size) && count($size) === 2 && (int) $size[0] > 0 && (int) $size[1] > 0)
            ->map(fn (array $size): array => [(int) $size[0], (int) $size[1]])
            ->unique(fn (array $size): string => $size[0].'x'.$size[1])
            ->values()
            ->all();
        if ($sourceSizes === []) {
            $this->error('The source Google GPT tag has no reusable fixed sizes.');
            return self::FAILURE;
        }

        $actor = $this->resolveActor($source, $demandPlacement, $demandSite, $account);
        if (! $actor) {
            $this->error('Could not resolve a live user for audit attribution. Pass --actor=<user-id>.');
            return self::FAILURE;
        }

        $created = 0;
        $alreadyActive = 0;
        $incompatible = 0;
        foreach ($presets as $preset) {
            try {
                $sizes = $this->compatiblePresetFixedSizes($catalog, $preset, $sourceSizes);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->implode(' ');
                $this->error("Could not resolve [{$preset}]: {$message}");
                return self::FAILURE;
            }

            if ($sizes === []) {
                $this->warn("SKIP {$preset}: the source GPT tag has no size supported by this preset.");
                $incompatible++;
                continue;
            }

            $existing = $this->existingPreset($site, $preset);
            if ($existing) {
                if ($existing->trashed() || $existing->status !== PlacementStatus::Active) {
                    $this->error("Quick preset [{$preset}] already exists but is deleted or inactive. Refusing to create a duplicate; repair or remove that placement first.");
                    return self::FAILURE;
                }
                $this->line("SKIP {$preset}: active Quick placement [{$existing->code}] already exists.");
                $alreadyActive++;
                continue;
            }

            try {
                $tag = $this->canonicalGptTag(
                    (string) $blueprint['scriptUrl'],
                    (string) $blueprint['adUnitPath'],
                    $sizes,
                    $preset,
                );
                $choice = $catalog->choices()[$preset] ?? [];
                $label = trim((string) ($choice['label'] ?? $preset));
                $result = $quick->activate(
                    $site->fresh(),
                    $network,
                    $actor,
                    $tag,
                    null,
                    $preset,
                    'Quick · '.$label,
                );
                /** @var Placement $placement */
                $placement = $result['placement'];
                $this->info("CREATED {$preset}: {$placement->code} (".$this->formatSizes($sizes).')');
                $created++;
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->implode(' ');
                $this->error("Failed to activate [{$preset}]: {$message}");
                return self::FAILURE;
            } catch (Throwable $exception) {
                $this->error("Failed to activate [{$preset}]: {$exception->getMessage()}");
                return self::FAILURE;
            }
        }

        $this->info("Quick display suite complete for {$siteKey}: {$created} created, {$alreadyActive} already active, {$incompatible} incompatible with the source GPT sizes.");
        return self::SUCCESS;
    }

    private function resolveActor(
        Placement $source,
        ?DemandPlacement $demandPlacement,
        ?DemandSite $demandSite,
        ?DemandAccount $account,
    ): ?User {
        $requested = trim((string) $this->option('actor'));
        $ids = array_values(array_unique(array_filter([
            $requested !== '' ? $requested : null,
            $source->updated_by,
            $source->created_by,
            $demandPlacement?->updated_by,
            $demandPlacement?->created_by,
            $demandSite?->updated_by,
            $demandSite?->created_by,
            $account?->updated_by,
            $account?->created_by,
        ])));

        foreach ($ids as $id) {
            $user = User::withoutGlobalScopes()->with('organization')->whereNull('deleted_at')->find($id);
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    private function existingPreset(Site $site, string $preset): ?Placement
    {
        return Placement::withoutGlobalScopes()->withTrashed()
            ->where('site_id', $site->id)
            ->get()
            ->first(function (Placement $placement) use ($preset): bool {
                return (bool) data_get($placement->metadata, 'quick_monetize_generated', false)
                    && (string) data_get($placement->metadata, 'placement_preset', '') === $preset;
            });
    }

    /** @return list<array{0:int,1:int}> */
    private function presetFixedSizes(PlacementPresetCatalog $catalog, string $preset): array
    {
        $resolved = $catalog->apply($preset, []);
        $sizes = collect((array) ($resolved['sizes'] ?? []))
            ->filter(fn ($size): bool => is_array($size)
                && strtoupper((string) ($size['size_type'] ?? '')) === 'FIXED'
                && (int) ($size['width'] ?? 0) > 0
                && (int) ($size['height'] ?? 0) > 0)
            ->map(fn (array $size): array => [(int) $size['width'], (int) $size['height']])
            ->unique(fn (array $size): string => $size[0].'x'.$size[1])
            ->values()
            ->all();

        if ($sizes === []) {
            throw ValidationException::withMessages(['placement_preset' => "Preset [{$preset}] has no fixed display sizes and cannot inherit a Google GPT display tag."]);
        }

        return $sizes;
    }

    /**
     * @param list<array{0:int,1:int}> $sourceSizes
     * @return list<array{0:int,1:int}>
     */
    private function compatiblePresetFixedSizes(PlacementPresetCatalog $catalog, string $preset, array $sourceSizes): array
    {
        $source = collect($sourceSizes)->mapWithKeys(fn (array $size): array => [$size[0].'x'.$size[1] => true]);

        return collect($this->presetFixedSizes($catalog, $preset))
            ->filter(fn (array $size): bool => isset($source[$size[0].'x'.$size[1]]))
            ->values()
            ->all();
    }

    /** @param list<array{0:int,1:int}> $sizes */
    private function canonicalGptTag(string $scriptUrl, string $adUnitPath, array $sizes, string $preset): string
    {
        $containerId = 'gpt-horus-'.str_replace('_', '-', $preset);
        $script = htmlspecialchars($scriptUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $path = json_encode($adUnitPath, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $id = json_encode($containerId, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encodedSizes = json_encode($sizes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return '<script async src="'.$script.'"></script>'
            .'<div id="'.$containerId.'"></div>'
            .'<script>window.googletag = window.googletag || {cmd: []};'
            .'googletag.cmd.push(function () {'
            .'googletag.defineSlot('.$path.', '.$encodedSizes.', '.$id.').addService(googletag.pubads());'
            .'googletag.enableServices();'
            .'googletag.display('.$id.');'
            .'});</script>';
    }

    /** @param list<array{0:int,1:int}> $sizes */
    private function formatSizes(array $sizes): string
    {
        return implode(', ', array_map(fn (array $size): string => $size[0].'x'.$size[1], $sizes));
    }
}
