<?php
// Read-only, bounded incident probe. Never output keys, secrets, IPs or raw logs.
declare(strict_types=1);
$root = $argv[1] ?? '';
if (! is_file($root.'/vendor/autoload.php')) exit(2);
try {
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function emit(string $kind, array $data): void { echo json_encode(['kind' => $kind] + $data, JSON_UNESCAPED_SLASHES)."\n"; }
$since = '2026-11-14 14:10:00';
emit('probe', ['read_only' => true, 'since_utc' => $since, 'now_utc' => gmdate('c')]);
$events = App\Models\AuditLog::query()->where('event', 'recaptcha.observation_settings_changed')->where('created_at', '>=', $since)->latest()->limit(10)->get();
foreach ($events as $event) emit('save_audit', ['site_id' => $event->auditable_id, 'at' => (string) $event->created_at, 'enabled' => $event->new_values['enabled'] ?? null]);
$ids = $events->pluck('auditable_id')->unique()->all();
foreach (App\Models\Site::withoutGlobalScopes()->with('siteConfig')->whereIn('id', $ids)->limit(10)->get() as $site) {
    $s = App\Models\SiteServingSetting::withoutGlobalScopes()->where('site_id', $site->id)->first();
    $decryptable = null;
    try { if ($s?->getRawOriginal('recaptcha_observation_secret')) $decryptable = is_string($s->recaptcha_observation_secret); } catch (Throwable) { $decryptable = false; }
    $version = App\Models\ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->where('environment', 'PRODUCTION')->orderByDesc('version')->first();
    $observation = $version?->payload['recaptchaObservation'] ?? [];
    $confirmedNumber = (int) ($site->siteConfig?->production_version ?? 0);
    $confirmed = $confirmedNumber > 0 ? App\Models\ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)
        ->where('environment', 'PRODUCTION')->where('version', $confirmedNumber)->first() : null;
    $confirmedObservation = $confirmed?->payload['recaptchaObservation'] ?? [];
    $expectedKey = $s?->recaptcha_observation_enabled ? $s->recaptcha_observation_site_key : null;
    emit('saved_state', ['site_id' => $site->id, 'domain' => $site->primary_domain, 'site_status' => $site->status?->value,
        'enabled' => (bool) $s?->recaptcha_observation_enabled, 'site_key_present' => (bool) $s?->recaptcha_observation_site_key,
        'secret_present' => (bool) $s?->getRawOriginal('recaptcha_observation_secret'), 'secret_decryptable' => $decryptable,
        'version' => $version?->version, 'version_status' => $version?->status?->value,
        'queued_enabled' => $observation['enabled'] ?? null,
        'queued_key_matches' => $version ? ($observation['siteKey'] ?? null) === $expectedKey : null,
        'confirmed_version' => $confirmed?->version, 'confirmed_version_status' => $confirmed?->status?->value,
        'confirmed_delivery_status' => $confirmed?->deliveryItem?->status?->value,
        'confirmed_enabled' => $confirmedObservation['enabled'] ?? null,
        'confirmed_key_matches' => $confirmed ? ($confirmedObservation['siteKey'] ?? null) === $expectedKey : null,
        'delivery_status' => $version?->deliveryItem?->status?->value]);
}
$categories = ['memory' => '/Allowed memory size|out of memory/i', 'execution_timeout' => '/Maximum execution time/i',
 'db_lock' => '/deadlock|lock wait timeout/i', 'db_connection' => '/too many connections|server has gone away/i',
 'encryption' => '/DecryptException|MAC is invalid|payload is invalid/i', 'validation' => '/ValidationException/i',
 'connection_closed' => '/upstream prematurely closed|connection reset|connection refused/i',
 'upstream_timeout' => '/upstream timed out/i', 'php_error' => '/Fatal error|TypeError|ErrorException/i'];
$paths = glob($root.'/storage/logs/laravel*.log') ?: [];
rsort($paths); $paths = array_slice($paths, 0, 3);
foreach ($paths as $path) {
    $f = @fopen($path, 'rb'); if (! $f) continue;
    $size = filesize($path); if ($size > 4194304) fseek($f, -4194304, SEEK_END);
    $total = 0; $counts = []; $recent = [];
    while (($line = fgets($f)) !== false) {
        if (! preg_match('/^\[(2026-11-14 [0-9:]{8})\].*\.(ERROR|CRITICAL|EMERGENCY):/', $line, $m) || $m[1] < $since) continue;
        $total++; $matched = [];
        foreach ($categories as $key => $pattern) if (preg_match($pattern, $line)) { $counts[$key] = ($counts[$key] ?? 0) + 1; $matched[] = $key; }
        $class = null;
        if (preg_match('/"exception":"([A-Za-z\\\\]+)"/', $line, $c)) $class = $c[1];
        $recent[] = ['at' => $m[1], 'categories' => $matched, 'class' => $class, 'mentions_observation' => str_contains($line, 'RecaptchaObservation')];
        if (count($recent) > 20) array_shift($recent);
    }
    fclose($f); emit('app_error_summary', ['file' => basename($path), 'tail_bounded' => $size > 4194304, 'count' => $total, 'categories' => $counts, 'recent' => $recent]);
}

} catch (Throwable) {
    fwrite(STDERR, "Read-only reCAPTCHA audit failed.\n");
    exit(1);
}
