<?php

declare(strict_types=1);
$root = dirname(__DIR__); $errors = [];
$requireText = static function (string $path, array $needles) use ($root, &$errors): void {
    $absolute = $root.'/'.$path;
    if (! is_file($absolute)) { $errors[] = "Missing required file: {$path}"; return; }
    $contents = (string) file_get_contents($absolute);
    foreach ($needles as $needle) if (! str_contains($contents, $needle)) $errors[] = "{$path} does not contain required marker: {$needle}";
};
$requireText('app/Enums/ServingMode.php', ['HORUS_GAM', 'MCM_PARTNER_GAM', 'PUBLISHER_GAM']);
$requireText('app/Services/Inventory/SiteConfigurationBuilder.php', ['gam_enabled', 'prebid_enabled', 'native_enabled']);
$requireText('public/assets/hm-loader.js', ['control.json', 'adsEnabled', 'gamEnabled']);
$requireText('bootstrap/app.php', ['SecurityHeaders::class', 'ValidateTrustedHost::class', 'EnsurePlatformAvailable::class']);
$requireText('routes/console.php', ['operations:heartbeat', 'queue:work database', '--stop-when-empty']);
$requireText('config/filesystems.php', ["'serve' => false"]);
$requireText('database/seeders/IdentityAccessSeeder.php', ['operations.view', 'operations.manage']);
$requireText('.env.production.example', ['APP_DEBUG=false', 'SESSION_SECURE_COOKIE=true', 'QUEUE_CONNECTION=database']);
$requireText('public/.htaccess', ['Options -Indexes', 'X-Content-Type-Options', 'Cache-Control']);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views'));
foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), '{!!')) $errors[] = 'Unescaped Blade output requires review: '.str_replace($root.'/', '', $file->getPathname());
foreach (['eval(', 'shell_exec(', 'passthru(', 'proc_open('] as $dangerous) foreach (['app', 'routes'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));
    foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), $dangerous)) $errors[] = "Dangerous runtime call {$dangerous} in ".str_replace($root.'/', '', $file->getPathname());
}
if ($errors !== []) { fwrite(STDERR, "Production static analysis failed:\n- ".implode("\n- ", array_unique($errors))."\n"); exit(1); }
echo "Production static analysis passed. Fixed architecture, security middleware, private storage, scheduler, and deployment markers are present.\n";
