<?php

/**
 * Read-only evidence for a blocked Demand account. No identifiers, tags or money
 * leave this function. Matches are diagnostic evidence, never an attestation.
 */
function horusFinanceDemandEvidence(\App\Models\DemandAccount $account, \App\Models\FinancialPeriod $period): array
{
    $result = [
        'provider' => $account->network->code->value,
        'mode' => $account->integration_mode->value,
        'quick_managed' => (bool) data_get($account->configuration, 'quick_monetize_managed', false),
        'created_month' => $account->created_at->format('Y-m'),
        'historical_account_attribution' => 'UNVERIFIED',
        'mappings_before_end' => 0, 'mappings_after_end' => 0,
        'widgets_before_end' => 0, 'widgets_changed_after_end' => 0,
        'current_google_paths' => 0, 'current_exact_report_matches' => 0,
        'current_other_or_unparseable' => 0,
        'current_other_kinds' => [], 'site_provider_other_kinds' => [],
        'site_provider_snapshots' => 0, 'site_provider_candidates' => 0,
        'site_provider_google_paths' => 0, 'site_provider_exact_report_matches' => 0,
    ];
    foreach ($account->sites()->withoutGlobalScopes()->where('is_enabled', true)->cursor() as $mapping) {
        $before = $mapping->created_at->lte($period->ends_on->endOfDay());
        $result[$before ? 'mappings_before_end' : 'mappings_after_end']++;
        $bindings = \App\Models\SiteGamReportBinding::withoutGlobalScopes()
            ->where('organization_id', $mapping->organization_id)->where('site_id', $mapping->site_id)
            ->whereDate('starts_on', '<=', $period->starts_on)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $period->ends_on))
            ->get(['network_code', 'ad_unit_code']);
        $paths = $bindings->map(fn ($b) => '/'.$b->network_code.'/'.$b->ad_unit_code)->all();
        foreach ($mapping->placements()->withoutGlobalScopes()->where('is_enabled', true)->cursor() as $placement) {
            foreach ($placement->widgets()->withoutGlobalScopes()->where('is_enabled', true)->cursor() as $widget) {
                if ($widget->created_at->lte($period->ends_on->endOfDay())) $result['widgets_before_end']++;
                if ($widget->updated_at->gt($period->ends_on->endOfDay())) $result['widgets_changed_after_end']++;
                try {
                    $tag = (string) $widget->direct_tag_template;
                    $path = (new \App\Services\Demand\GoogleAdUnitPath())->parse($tag)
                        ?? ((new \App\Services\Demand\GoogleGptManualTagParser())->parse($tag)['adUnitPath'] ?? null);
                } catch (\Throwable) { $path = null; }
                if ($path) {
                    $result['current_google_paths']++;
                    if (in_array($path, $paths, true)) $result['current_exact_report_matches']++;
                } else {
                    $result['current_other_or_unparseable']++;
                    $kind = horusFinanceOtherTagKind($tag, $paths);
                    $result['current_other_kinds'][$kind] = ($result['current_other_kinds'][$kind] ?? 0) + 1;
                }
            }
        }
        // Public snapshots omit account identity. These are SITE/PROVIDER
        // observations only, not evidence that this account was deployed.
        // Include the last published snapshot before the month plus publications
        // in the month. Never hydrate the full version history at once.
        $versions = \App\Models\ConfigVersion::withoutGlobalScopes()
            ->where('organization_id', $mapping->organization_id)->where('site_id', $mapping->site_id)
            ->where('environment', 'PRODUCTION')->whereNotNull('published_at');
        $prior = (clone $versions)->where('published_at', '<', $period->starts_on->startOfDay())
            ->orderByDesc('published_at')->value('id');
        foreach ($versions->where('published_at', '<=', $period->ends_on->endOfDay())
            ->where(fn ($q) => $q->where('published_at', '>=', $period->starts_on->startOfDay())->orWhere('id', $prior))
            ->select(['id', 'payload'])->cursor() as $version) {
            $result['site_provider_snapshots']++;
            $demand = $version->payload['directDemand'] ?? $version->payload['nativeDemand'] ?? [];
            if (! ($demand['enabled'] ?? false)) continue;
            foreach ($demand['placements'] ?? [] as $slot) {
                if (! ($slot['enabled'] ?? false)) continue;
                foreach ($slot['candidates'] ?? [] as $candidate) {
                    if (($candidate['network'] ?? '') !== $result['provider']) continue;
                    $result['site_provider_candidates']++;
                    $path = $candidate['tag']['container']['attributes']['data-hm-gpt-ad-unit-path'] ?? null;
                    if (is_string($path) && $path !== '') {
                        $result['site_provider_google_paths']++;
                        if (in_array($path, $paths, true)) $result['site_provider_exact_report_matches']++;
                    } else {
                        $attributes = $candidate['tag']['container']['attributes'] ?? [];
                        $html = horusFinanceDecodePayload($attributes, 'data-hm-isolated-html')
                            ?: horusFinanceDecodePayload($attributes, 'data-hm-vast-url');
                        $kind = horusFinanceOtherTagKind($html, $paths);
                        $result['site_provider_other_kinds'][$kind] = ($result['site_provider_other_kinds'][$kind] ?? 0) + 1;
                    }
                }
            }
        }
    }

    return $result;
}

/** Classify patterns only; never execute provider code or certify revenue coverage. */
function horusFinanceOtherTagKind(string $tag, array $reportPaths): string
{
    if ($tag === '' || strlen($tag) > 100000) return 'UNREADABLE';
    $tag = trim(html_entity_decode($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $url = parse_url($tag);
    if (strtolower($url['scheme'] ?? '') === 'https' && in_array(strtolower($url['host'] ?? ''), ['pubads.g.doubleclick.net', 'securepubads.g.doubleclick.net'], true)
        && ($url['path'] ?? '') === '/gampad/ads') {
        parse_str($url['query'] ?? '', $query);
        return is_string($query['iu'] ?? null) && in_array($query['iu'], $reportPaths, true)
            ? 'GOOGLE_VAST_EXACT_PATH' : 'GOOGLE_VAST_OTHER_PATH';
    }
    $parsed = (new \App\Services\Demand\DirectTagRecipeParser())->parse($tag);
    $scripts = array_column($parsed['detectedScripts'] ?? [], 'url');
    $googleOnly = count($scripts) === 1 && in_array($scripts[0], [
        'https://securepubads.g.doubleclick.net/tag/js/gpt.js',
        'https://pagead2.googlesyndication.com/tag/js/gpt.js',
    ], true);
    if ($googleOnly) {
        preg_match_all('/googletag\s*\.\s*defineOutOfPageSlot\s*\(\s*([\'"])([^\'"]+)\1\s*,/', $tag, $matches);
        if (($matches[2] ?? []) !== []) {
            return array_diff($matches[2], $reportPaths) === []
                ? 'GOOGLE_OUT_OF_PAGE_EXACT_PATH' : 'GOOGLE_OUT_OF_PAGE_OTHER_PATH';
        }
        return 'GOOGLE_MARKUP_UNVERIFIED';
    }
    return 'OTHER_OR_MIXED_MARKUP';
}

function horusFinanceDecodePayload(array $attributes, string $key): string
{
    $encoded = $attributes[$key] ?? '';
    if ($encoded === '' && isset($attributes[$key.'-parts'])) {
        $count = filter_var($attributes[$key.'-parts'], FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 64) return '';
        for ($i = 0; $i < $count; $i++) {
            if (!isset($attributes[$key.'-'.$i]) || !is_string($attributes[$key.'-'.$i])) return '';
            $encoded .= $attributes[$key.'-'.$i];
            if (strlen($encoded) > 140000) return '';
        }
    }
    if (!is_string($encoded) || strlen($encoded) > 140000) return '';
    return base64_decode($encoded, true) ?: '';
}
