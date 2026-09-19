<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/verify-lordai-in-article-live.php <static-snapshot-root>\n");
    exit(2);
}

$root = rtrim((string) $argv[1], DIRECTORY_SEPARATOR);
if ($root === '' || ! is_dir($root)) {
    fwrite(STDERR, "Static snapshot root is missing or invalid.\n");
    exit(2);
}

$matches = [];
foreach (glob($root.'/configs/*/production.json') ?: [] as $path) {
    try {
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Unable to parse production config '.$path.': '.$error->getMessage()."\n");
        exit(1);
    }

    $hosts = array_map(
        static fn ($host): string => strtolower((string) preg_replace('/^www\./i', '', trim((string) $host))),
        (array) ($config['allowedHostnames'] ?? []),
    );

    if (in_array('lordai.net', $hosts, true)) {
        $matches[] = ['path' => $path, 'config' => $config];
    }
}

if (count($matches) !== 1) {
    fwrite(STDERR, 'Expected exactly one production config for lordai.net; found '.count($matches)."\n");
    exit(1);
}

$configPath = (string) $matches[0]['path'];
$config = (array) $matches[0]['config'];

$placements = array_values(array_filter(
    (array) ($config['placements'] ?? []),
    static fn ($placement): bool => (string) ($placement['code'] ?? '') === 'quick_in_article_display',
));

if (count($placements) !== 1) {
    fwrite(STDERR, 'Expected exactly one quick_in_article_display placement; found '.count($placements)."\n");
    exit(1);
}

$placement = (array) $placements[0];
$format = (array) ($placement['format'] ?? []);
$settings = (array) ($format['settings'] ?? []);
$formatCode = (string) ($format['code'] ?? '');
$target = strtolower((string) ($settings['autoMountTarget'] ?? ''));
$renderer = (string) ($placement['renderer'] ?? '');

if (($placement['enabled'] ?? false) !== true
    || strtolower((string) ($placement['status'] ?? '')) !== 'active') {
    fwrite(STDERR, "LordAI quick_in_article_display is not active and enabled.\n");
    exit(1);
}

if ($formatCode !== 'display_in_article') {
    fwrite(STDERR, "LordAI quick_in_article_display does not use display_in_article.\n");
    exit(1);
}

if (($settings['autoMount'] ?? null) !== true) {
    fwrite(STDERR, "LordAI quick_in_article_display is not auto-mounted.\n");
    exit(1);
}

if (! in_array($target, ['content_mid', 'article_mid', 'content_end', 'article_end'], true)) {
    fwrite(STDERR, 'LordAI quick_in_article_display has an invalid in-content autoMountTarget: '.$target."\n");
    exit(1);
}

if ($renderer !== 'DIRECT_JS') {
    fwrite(STDERR, 'LordAI quick_in_article_display is not owned by DIRECT_JS; renderer='.$renderer."\n");
    exit(1);
}

$loaderPath = $root.'/hm-loader.js';
if (! is_file($loaderPath)) {
    fwrite(STDERR, "Production hm-loader.js is missing from the static snapshot.\n");
    exit(1);
}

$loader = (string) file_get_contents($loaderPath);

// Production serves the minified Loader. Function identifiers are not a stable
// contract because esbuild shortens them, so validate semantic literals that
// must survive minification and jointly prove both wrapper and Direct/GPT-child
// centering behavior is present.
$loaderContract = [
    'display_in_article',
    'contentPosition',
    'content_mid',
    'article_mid',
    'align-items',
    'align-self',
    'margin-left',
    'margin-right',
    'text-align',
    'data-hm-auto-mount-target',
];
foreach ($loaderContract as $needle) {
    if (! str_contains($loader, $needle)) {
        fwrite(STDERR, 'Production loader is missing a minification-safe LordAI centering marker: '.$needle."\n");
        exit(1);
    }
}

// These value pairings are the behavioral core of the fix. They remain literal
// strings after minification even though surrounding function names change.
foreach ([
    '"align-items","center"',
    '"align-self","center"',
    '"margin-left","auto"',
    '"margin-right","auto"',
    '"text-align","center"',
] as $needle) {
    if (! str_contains($loader, $needle)) {
        fwrite(STDERR, 'Production loader is missing a minification-safe LordAI centering behavior: '.$needle."\n");
        exit(1);
    }
}

$relativeConfigPath = ltrim(substr($configPath, strlen($root)), DIRECTORY_SEPARATOR);
if (! preg_match('#^configs/[^/]+/production\.json$#', $relativeConfigPath)) {
    fwrite(STDERR, 'Unexpected LordAI production config path: '.$relativeConfigPath."\n");
    exit(1);
}

$result = [
    'config_path' => $relativeConfigPath,
    'config_sha256' => hash_file('sha256', $configPath),
    'loader_sha256' => hash_file('sha256', $loaderPath),
    'placement_code' => 'quick_in_article_display',
    'format_code' => $formatCode,
    'auto_mount_target' => $target,
    'renderer' => $renderer,
];

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
