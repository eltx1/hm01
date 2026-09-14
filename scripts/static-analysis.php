<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$forbidden = ['dd', 'dump', 'var_dump'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') continue;
    $contents = file_get_contents($file->getPathname());
    if ($contents === false) continue;

    // Tokenize PHP instead of substring-scanning. A helper named `$add(...)`
    // contains the characters `dd(` but is not Laravel's dd() debug helper.
    // We still reject real function/method calls to the forbidden debug names.
    $tokens = token_get_all($contents);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (! is_array($token) || $token[0] !== T_STRING) continue;

        $name = strtolower($token[1]);
        if (! in_array($name, $forbidden, true)) continue;

        $next = $index + 1;
        while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $next++;
        }
        if (($tokens[$next] ?? null) === '(') {
            $errors[] = $file->getPathname().': forbidden '.$name.'(';
        }
    }
}
$env = file_get_contents($root.'/.env.production.example');
foreach (['APP_DEBUG=false', 'SESSION_SECURE_COOKIE=true', 'QUEUE_CONNECTION=database', 'GAM_DRY_RUN_DEFAULT=true'] as $required) {
    if (! str_contains($env, $required)) $errors[] = '.env.production.example missing '.$required;
}
foreach (['public/.htaccess', 'storage/.htaccess', 'release/INSTALLATION.md', 'release/SECURITY_REPORT.md'] as $requiredFile) {
    if (! is_file($root.'/'.$requiredFile)) $errors[] = 'missing '.$requiredFile;
}
if ($errors) { fwrite(STDERR, implode("\n", $errors)."\n"); exit(1); }
echo "Production static analysis passed.\n";
