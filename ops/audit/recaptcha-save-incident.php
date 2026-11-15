<?php
// Read-only, bounded incident probe. Never output keys, secrets, IPs or raw logs.
declare(strict_types=1);
function hmIncidentLogRecord(string $line): ?array
{
    $json = json_decode($line, true);
    $format = 'text'; $class = null;
    if (is_array($json)) {
        $format = 'json';
        $date = $json['datetime'] ?? null;
        $level = $json['level_name'] ?? null;
        if (! is_string($date) || ! is_string($level) || ! is_string($json['message'] ?? null)) return null;
        try { $at = (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); } catch (Throwable) { return null; }
        $message = $json['message'];
        $exception = $json['context']['exception'] ?? null;
        if (is_array($exception)) {
            $class = $exception['class'] ?? null;
            if (is_string($exception['message'] ?? null)) $message .= ' '.$exception['message'];
        } elseif (is_string($exception)) $message .= ' '.$exception;
    } else {
        if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} [0-9:]{8})\].*\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):/', $line, $match)) return null;
        $at = $match[1]; $level = $match[2]; $message = $line;
    }
    if (! is_string($class) || strlen($class) > 200 || ! preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/D', $class)) $class = null;
$categories = ['memory' => '/Allowed memory size|out of memory/i', 'execution_timeout' => '/Maximum execution time/i',
 'db_lock' => '/deadlock|lock wait timeout/i', 'db_connection' => '/too many connections|server has gone away/i',
 'encryption' => '/DecryptException|MAC is invalid|payload is invalid/i', 'validation' => '/ValidationException/i',
 'connection_closed' => '/upstream prematurely closed|connection reset|connection refused/i',
 'upstream_timeout' => '/upstream timed out/i', 'php_error' => '/Fatal error|TypeError|ErrorException/i'];
    $matched = [];
    foreach ($categories as $key => $pattern) if (preg_match($pattern, $message)) $matched[] = $key;
    return ['at' => $at, 'level' => strtoupper($level), 'format' => $format,
        'categories' => $matched, 'class' => $class, 'mentions_observation' => str_contains($message, 'RecaptchaObservation')];
}
// Importing this file for parser tests does not bootstrap or inspect an app.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
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
$paths = glob($root.'/storage/logs/laravel*.log') ?: [];
rsort($paths); $paths = array_slice($paths, 0, 3);
foreach ($paths as $path) {
    $f = @fopen($path, 'rb'); if (! $f) continue;
    $size = filesize($path); if ($size > 4194304) fseek($f, -4194304, SEEK_END);
    $total = 0; $counts = []; $recent = []; $formats = []; $unrecognized = 0; $records = 0;
    while (($line = fgets($f)) !== false) {
        $record = hmIncidentLogRecord($line);
        if (! $record) { $unrecognized++; continue; }
        $formats[$record['format']] = ($formats[$record['format']] ?? 0) + 1;
        if ($record['at'] < $since) continue;
        $records++;
        if (! in_array($record['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) continue;
        $total++;
        foreach ($record['categories'] as $category) $counts[$category] = ($counts[$category] ?? 0) + 1;
        unset($record['format']);
        $recent[] = $record;
        if (count($recent) > 20) array_shift($recent);
    }
    fclose($f); emit('app_error_summary', ['file' => basename($path), 'tail_bounded' => $size > 4194304,
        'recognized_formats' => $formats, 'unrecognized_lines' => $unrecognized, 'records_in_window' => $records,
        'count' => $total, 'categories' => $counts, 'recent' => $recent]);
}

} catch (Throwable) {
    fwrite(STDERR, "Read-only reCAPTCHA audit failed.\n");
    exit(1);
}
