<?php

declare(strict_types=1);

$root = realpath($argv[1] ?? 'cloudflare-pages-dist');
if ($root === false || ! is_dir($root)) {
    fwrite(STDERR, "Static delivery directory is missing.\n");
    exit(1);
}

$forbidden = ['functions', '_worker.js', '.env', '.git'];
foreach ($forbidden as $entry) {
    if (file_exists($root.DIRECTORY_SEPARATOR.$entry)) {
        fwrite(STDERR, "Forbidden Pages runtime entry: {$entry}\n");
        exit(1);
    }
}

$required = [
    'hm-loader.js',
    '_headers',
    '404.html',
    'delivery-manifest.json',
    'configs/_global/control.json',
    'sellers.json',
    'supply/sellers.json',
];
foreach ($required as $path) {
    if (! is_file($root.DIRECTORY_SEPARATOR.$path)) {
        fwrite(STDERR, "Missing static delivery file: {$path}\n");
        exit(1);
    }
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$files = [];
$secretPattern = '/(-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|sk-[A-Za-z0-9_-]{20,}|CLOUDFLARE_API_TOKEN\s*=\s*\S+|GITHUB_TOKEN\s*=\s*\S+)/';
foreach ($iterator as $file) {
    if (! $file->isFile()) continue;
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    $contents = file_get_contents($file->getPathname());
    if ($contents === false || preg_match($secretPattern, $contents)) {
        fwrite(STDERR, "Unreadable file or secret-like value in {$relative}.\n");
        exit(1);
    }
    $files[$relative] = $contents;
}

$limit = max(1, (int) (getenv('HORUS_STATIC_DELIVERY_FILE_LIMIT') ?: 20000));
if (count($files) > $limit) {
    fwrite(STDERR, "File budget exceeded: ".count($files)." > {$limit}.\n");
    exit(1);
}
$maxFileBytes = max(1, (int) (getenv('HORUS_STATIC_DELIVERY_MAX_FILE_BYTES') ?: 26214400));
foreach ($files as $path => $contents) {
    if (strlen($contents) > $maxFileBytes) {
        fwrite(STDERR, "File size exceeded: {$path}.\n");
        exit(1);
    }
}

$manifest = json_decode($files['delivery-manifest.json'], true, 512, JSON_THROW_ON_ERROR);
foreach ((array) ($manifest['files'] ?? []) as $path => $sha) {
    if (! isset($files[$path]) || ! hash_equals((string) $sha, hash('sha256', $files[$path]))) {
        fwrite(STDERR, "Manifest checksum mismatch: {$path}.\n");
        exit(1);
    }
}

$sellers = json_decode($files['sellers.json'], true, 512, JSON_THROW_ON_ERROR);
if (($sellers['version'] ?? null) !== '1.0' || ! is_array($sellers['sellers'] ?? null)
    || ! hash_equals($files['sellers.json'], $files['supply/sellers.json'])) {
    fwrite(STDERR, "Invalid or divergent sellers.json static artifacts.\n");
    exit(1);
}

foreach ($files as $path => $contents) {
    if (! preg_match('#^configs/[^/]+/(?:production|test|preview)(?:\.v[^/]*)?\.json$#', $path)) continue;
    $config = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    $urls = [$config['loader']['assetUrl'] ?? null, $config['prebid']['build']['url'] ?? null];
    foreach (array_filter($urls, 'is_string') as $url) {
        $parts = parse_url($url);
        if (($parts['host'] ?? null) !== 'cdn.horusmedia.net') continue;
        $asset = ltrim((string) ($parts['path'] ?? ''), '/');
        if ($asset !== '' && ! isset($files[$asset])) {
            fwrite(STDERR, "Configuration {$path} references missing static asset {$asset}.\n");
            exit(1);
        }
    }
}

$headers = $files['_headers'];
foreach (['Access-Control-Allow-Origin: *', 'X-Content-Type-Options: nosniff', 'immutable', 'stale-while-revalidate', 'X-Robots-Tag: noindex'] as $header) {
    if (! str_contains($headers, $header)) {
        fwrite(STDERR, "Required _headers policy missing: {$header}.\n");
        exit(1);
    }
}
foreach (['/sellers.json', '/supply/sellers.json', 'Content-Type: application/json; charset=utf-8'] as $header) {
    if (! str_contains($headers, $header)) {
        fwrite(STDERR, "sellers.json _headers policy missing: {$header}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Validated ".count($files)." static files; manifest {$manifest['manifestHash']}.\n");
