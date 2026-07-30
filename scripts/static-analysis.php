<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$forbidden = ['dd(', 'dump(', 'var_dump('];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') continue;
    $contents = file_get_contents($file->getPathname());
    foreach ($forbidden as $needle) if (str_contains($contents, $needle)) $errors[] = $file->getPathname().': forbidden '.$needle;
}
$env = file_get_contents($root.'/.env.production.example');
foreach (['APP_DEBUG=false', 'SESSION_SECURE_COOKIE=true', 'QUEUE_CONNECTION=database', 'GAM_DRY_RUN_DEFAULT=true'] as $required) {
    if (! str_contains($env, $required)) $errors[] = '.env.production.example missing '.$required;
}
foreach (['public/.htaccess', 'storage/.htaccess', 'release/INSTALLATION.md', 'release/SECURITY_REPORT.md'] as $requiredFile) {
    if (! is_file($root.'/'.$requiredFile)) $errors[] = 'missing '.$requiredFile;
}
if ($errors) { fwrite(STDERR, implode("
", $errors)."
"); exit(1); }
echo "Production static analysis passed.
";
