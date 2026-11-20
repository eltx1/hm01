<?php

declare(strict_types=1);

/**
 * Read-only post-deployment check of optional reCAPTCHA v3 observation.
 *
 * For every site with observation enabled it reports closed codes and booleans
 * only: whether the site and its published configuration can run observation,
 * the Cloudflare gate mode, and whether the public home page already loads the
 * publisher's own reCAPTCHA (observation skips such pages). Never writes, never
 * calls Google, never prints site keys, secrets, hostnames or identities.
 */
ini_set('display_errors', '0');
$print = static fn (array $result) => print(json_encode(['schema_version' => 1, 'diagnostic' => 'RECAPTCHA_OBSERVATION'] + $result, JSON_UNESCAPED_SLASHES).PHP_EOL);
try {
    $expected = getenv('HORUS_EXPECTED_RELEASE');
    $marker = @file_get_contents(getcwd().'/.horus-release');
    if (! is_string($expected) || ! preg_match('/^[a-f0-9]{40}$/D', $expected)
        || ! is_string($marker) || ! in_array('release_id='.$expected, explode("\n", trim($marker)), true)) {
        $print(['reason' => 'RELEASE_MISMATCH']);

        return;
    }
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $keyPattern = '/^[A-Za-z0-9_-]{20,200}$/D';
    $settings = App\Models\SiteServingSetting::withoutGlobalScopes()->where('recaptcha_observation_enabled', true)->orderBy('site_id')->limit(20)->get();
    $resolver = app(App\Services\TrafficGate\TrafficGateConfigurationResolver::class);
    $sites = [];
    foreach ($settings as $setting) {
        $site = App\Models\Site::withoutGlobalScopes()->with('siteConfig')->find($setting->site_id);
        if (! $site) continue;
        $gate = null;
        try { $gate = $resolver->resolve($site)->toPublicArray(); } catch (Throwable) {}
        $confirmedNumber = (int) ($site->siteConfig?->production_version ?? 0);
        $confirmed = $confirmedNumber > 0 ? App\Models\ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)
            ->where('environment', 'PRODUCTION')->where('version', $confirmedNumber)->first() : null;
        $published = $confirmed?->payload['recaptchaObservation'] ?? null;
        $publishedKey = is_array($published) ? ($published['siteKey'] ?? null) : null;

        // Public home page, fetched like a browser would see the HTML (scripts never executed).
        $page = ['checked' => false, 'http' => null, 'has_horus_loader' => null, 'has_publisher_recaptcha' => null];
        $host = strtolower((string) $site->primary_domain);
        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)) {
            try {
                $response = Illuminate\Support\Facades\Http::timeout(8)->connectTimeout(4)->withoutRedirecting()
                    ->withHeaders(['User-Agent' => 'HorusMedia-InstallationCheck/1.0', 'Accept' => 'text/html'])
                    ->get('https://'.$host.'/');
                $status = $response->status();
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $location = (string) $response->header('Location');
                    $next = parse_url($location);
                    $nextHost = strtolower((string) ($next['host'] ?? $host));
                    if (($next['scheme'] ?? 'https') === 'https' && ($nextHost === $host || $nextHost === 'www.'.$host || 'www.'.$nextHost === $host)) {
                        $response = Illuminate\Support\Facades\Http::timeout(8)->connectTimeout(4)->withoutRedirecting()
                            ->withHeaders(['User-Agent' => 'HorusMedia-InstallationCheck/1.0', 'Accept' => 'text/html'])
                            ->get('https://'.$nextHost.($next['path'] ?? '/'));
                        $status = $response->status();
                    }
                }
                $html = substr((string) $response->body(), 0, 1048576);
                $page = ['checked' => true, 'http' => $status >= 200 && $status < 300 ? 'OK' : ($status >= 300 && $status < 400 ? 'REDIRECT' : ($status === 403 ? 'FORBIDDEN' : 'ERROR')),
                    'has_horus_loader' => (bool) preg_match('/hm-loader|horusmedia/i', $html),
                    'has_publisher_recaptcha' => (bool) preg_match('#/recaptcha/(api|enterprise)\.js|grecaptcha-badge|g-recaptcha#i', $html)];
            } catch (Throwable) {
                $page = ['checked' => true, 'http' => 'UNREACHABLE', 'has_horus_loader' => null, 'has_publisher_recaptcha' => null];
            }
        }

        $sites[] = [
            'site_active' => $site->status?->value === 'ACTIVE',
            'config_active' => strtoupper((string) ($site->siteConfig?->status ?? '')) === 'ACTIVE' && ! $site->siteConfig?->immediate_pause,
            'cloudflare_gate_enabled' => is_array($gate) ? (bool) ($gate['enabled'] ?? false) : null,
            'cloudflare_gate_readiness' => is_array($gate) && is_string($gate['readiness'] ?? null) && preg_match('/^[A-Z_]{1,40}$/D', $gate['readiness']) ? $gate['readiness'] : null,
            'saved_key_valid' => is_string($setting->recaptcha_observation_site_key) && (bool) preg_match($keyPattern, $setting->recaptcha_observation_site_key),
            'published_enabled' => is_array($published) ? (($published['enabled'] ?? false) === true) : null,
            'published_key_valid' => is_string($publishedKey) ? (bool) preg_match($keyPattern, $publishedKey) : null,
            'published_key_matches_saved' => is_array($published) ? $publishedKey === $setting->recaptcha_observation_site_key : null,
            'published_delivery' => is_string($confirmed?->deliveryItem?->status?->value) ? $confirmed->deliveryItem->status->value : null,
            'page' => $page,
        ];
    }
    $print(['reason' => 'OK', 'enabled_sites' => count($sites), 'sites' => $sites]);
} catch (Throwable $error) {
    $print(['reason' => 'EXECUTION_FAILED', 'class' => (new ReflectionClass($error))->getShortName()]);
}
