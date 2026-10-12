<?php

// Incident-scoped private metadata. No credentials, raw tags, amounts or users.
function horusFinancePrivateSourceEvidence(\App\Models\DemandAccount $account, \App\Models\FinancialPeriod $period): array
{
    $result = ['account_id' => $account->id, 'name' => $account->name, 'sites' => []];
    foreach ($account->sites()->withoutGlobalScopes()->where('is_enabled', true)->cursor() as $mapping) {
        $site = $mapping->site()->withoutGlobalScopes()->firstOrFail();
        $data = ['site_id' => $site->id, 'domain' => $site->primary_domain, 'created_at' => $mapping->created_at->toIso8601String(), 'reports' => [], 'widgets' => [], 'history' => []];
        foreach ([\App\Models\SiteGamReportBinding::class, \App\Models\SiteGamVideoReportBinding::class] as $model) {
            foreach ($model::withoutGlobalScopes()->where('organization_id', $site->organization_id)->where('site_id', $site->id)->get() as $binding) {
                $data['reports'][] = ['kind' => $binding->connectionType(), 'binding_id' => $binding->id,
                    'gam_connection_id' => $binding->gam_connection_id, 'network' => $binding->network_code,
                    'unit_id' => $binding->ad_unit_id, 'unit_code' => $binding->ad_unit_code,
                    'starts_on' => $binding->starts_on->toDateString(), 'ends_on' => $binding->ends_on?->toDateString()];
            }
        }
        foreach ($mapping->placements()->withoutGlobalScopes()->where('is_enabled', true)->cursor() as $placement) {
            foreach ($placement->widgets()->withoutGlobalScopes()->where('is_enabled', true)->cursor() as $widget) {
                $data['widgets'][] = ['demand_placement_id' => $placement->id, 'created_at' => $widget->created_at->toIso8601String(),
                    'updated_at' => $widget->updated_at->toIso8601String(), 'source' => horusFinanceTagMetadata((string) $widget->direct_tag_template)];
            }
        }
        $versions = \App\Models\ConfigVersion::withoutGlobalScopes()->where('organization_id', $site->organization_id)
            ->where('site_id', $site->id)->where('environment', 'PRODUCTION')->whereNotNull('published_at');
        $prior = (clone $versions)->where('published_at', '<', $period->starts_on->startOfDay())->orderByDesc('published_at')->value('id');
        foreach ($versions->where('published_at', '<=', $period->ends_on->endOfDay())
            ->where(fn ($q) => $q->where('published_at', '>=', $period->starts_on->startOfDay())->orWhere('id', $prior))
            ->select(['id', 'payload', 'published_at'])->cursor() as $version) {
            $demand = $version->payload['directDemand'] ?? $version->payload['nativeDemand'] ?? [];
            if (!($demand['enabled'] ?? false)) continue;
            $candidates = [];
            foreach ($demand['placements'] ?? [] as $slot) {
                if (!($slot['enabled'] ?? false)) continue;
                foreach ($slot['candidates'] ?? [] as $candidate) {
                    if (($candidate['network'] ?? '') !== $account->network->code->value) continue;
                    $attributes = $candidate['tag']['container']['attributes'] ?? [];
                    $path = $attributes['data-hm-gpt-ad-unit-path'] ?? '';
                    $raw = $path ?: (horusFinanceDecodePayload($attributes, 'data-hm-isolated-html') ?: horusFinanceDecodePayload($attributes, 'data-hm-vast-url'))
                        ?: (string) ($candidate['tag']['isolation']['html'] ?? '');
                    $metadata = horusFinanceTagMetadata($raw);
                    $candidates[] = ['container' => $candidate['tag']['container']['id'] ?? null, 'source' => $metadata,
                        'gam_managed' => (bool) ($candidate['gamManaged'] ?? false),
                        ...($metadata['kind'] === 'UNKNOWN' ? ['tag_fields' => array_keys($candidate['tag'] ?? []),
                            'attribute_fields' => array_keys($attributes),
                            'vast_payload_length' => strlen((string) ($attributes['data-hm-vast-url'] ?? '')),
                            'vast_decoded_length' => strlen(horusFinanceDecodePayload($attributes, 'data-hm-vast-url')),
                            'decoded_vast_shape' => horusFinanceUrlShape(horusFinanceDecodePayload($attributes, 'data-hm-vast-url')),
                            'literal_vast_metadata' => horusFinanceTagMetadata((string) ($attributes['data-hm-vast-url'] ?? ''))] : [])];
                }
            }
            $data['history'][] = ['published_at' => $version->published_at->toIso8601String(), 'candidates' => $candidates];
        }
        $result['sites'][] = $data;
    }
    return $result;
}

/** Extract only public unit paths and hostnames; never retain URL queries or code. */
function horusFinanceTagMetadata(string $tag): array
{
    $result = ['paths' => [], 'script_hosts' => [], 'kind' => 'UNKNOWN'];
    if (strlen($tag) > 100000) return $result;
    $tag = trim(html_entity_decode($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $url = parse_url($tag);
    if (($url['scheme'] ?? '') === 'https' && in_array($url['host'] ?? '', ['pubads.g.doubleclick.net', 'securepubads.g.doubleclick.net'], true) && ($url['path'] ?? '') === '/gampad/ads') {
        parse_str($url['query'] ?? '', $query);
        $paths = [$query['iu'] ?? '']; $result['kind'] = 'GOOGLE_VAST';
    } else {
        $paths = [];
        if (str_starts_with(trim($tag), '/') && !str_starts_with(trim($tag), '//')) $paths[] = trim($tag);
        preg_match_all('/googletag\s*\.\s*define(?:OutOfPage)?Slot\s*\(\s*([\'"])([^\'"]+)\1\s*,/', $tag, $matches);
        $paths = array_merge($paths, $matches[2] ?? []);
        $parsed = (new \App\Services\Demand\DirectTagRecipeParser())->parse($tag);
        foreach ($parsed['detectedScripts'] ?? [] as $script) {
            $host = parse_url($script['url'], PHP_URL_HOST);
            if (is_string($host) && preg_match('/^[a-zA-Z0-9.-]{1,253}$/D', $host)) $result['script_hosts'][] = $host;
        }
        if ($paths !== []) $result['kind'] = 'GOOGLE_PATH_PATTERN';
    }
    foreach ($paths as $path) {
        if (is_string($path) && preg_match('#^/[0-9]{1,20}(?:,[0-9]{1,20})?/[A-Za-z0-9_./-]{1,240}$#D', $path)) $result['paths'][] = $path;
    }
    $result['paths'] = array_values(array_unique($result['paths']));
    $result['script_hosts'] = array_values(array_unique($result['script_hosts']));
    return $result;
}

/** Unknown recipes expose only shape and a validated public inventory path, never URL/query values. */
function horusFinanceUrlShape(string $value): array
{
    if (strlen($value) > 100000) return ['kind' => 'OVERSIZED'];
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $url = parse_url($value);
    $host = $url['host'] ?? '';
    $scheme = $url['scheme'] ?? '';
    parse_str($url['query'] ?? '', $query);
    $unit = $query['iu'] ?? null;
    $adsenseEndpoint = $host === 'googleads.g.doubleclick.net' && ($url['path'] ?? '') === '/pagead/ads';
    $client = $query['client'] ?? null;
    $slot = $query['slotname'] ?? null;
    return ['kind' => $host !== '' ? 'URL' : (str_starts_with($value, '<') ? 'MARKUP' : 'OTHER'),
        'scheme' => in_array($scheme, ['http', 'https'], true) ? $scheme : 'OTHER',
        'host' => is_string($host) && preg_match('/^[a-zA-Z0-9.-]{1,253}$/D', $host) ? strtolower($host) : null,
        'google_ads_endpoint' => ($url['path'] ?? '') === '/gampad/ads',
        'adsense_video_endpoint' => $adsenseEndpoint,
        'public_adsense_client' => $adsenseEndpoint && is_string($client) && (preg_match('/^ca-(?:video-)?pub-[0-9]{16}$/D', $client) || $client === 'ca-video-afvtest') ? $client : null,
        'public_adsense_slot' => $adsenseEndpoint && is_string($slot) && preg_match('/^[0-9]{1,20}$/D', $slot) ? $slot : null,
        'unit_path' => is_string($unit) && preg_match('#^/[0-9]{1,20}(?:,[0-9]{1,20})?/[A-Za-z0-9_./-]{1,240}$#D', $unit) ? $unit : null];
}

function horusFinanceSealEvidence(array $evidence, string $publicKey): array
{
    $key = openssl_pkey_get_public($publicKey);
    $details = $key ? openssl_pkey_get_details($key) : false;
    if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] !== 3072) throw new \RuntimeException('Invalid diagnostic recipient');
    $plaintext = json_encode($evidence, JSON_THROW_ON_ERROR);
    if (strlen($plaintext) > 524288) throw new \RuntimeException('Private evidence limit exceeded');
    $secret = random_bytes(32); $iv = random_bytes(12); $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, $iv, $tag, 'horus-finance-evidence-v1');
    if ($ciphertext === false || !openssl_public_encrypt($secret, $wrapped, $key, OPENSSL_PKCS1_OAEP_PADDING)) throw new \RuntimeException('Diagnostic encryption unavailable');
    return ['algorithm' => 'RSA-OAEP+A256GCM', 'key_fingerprint' => hash('sha256', $details['key']),
        'wrapped_key' => base64_encode($wrapped), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'ciphertext' => base64_encode($ciphertext)];
}
