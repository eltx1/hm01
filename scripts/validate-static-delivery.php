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
    '_routes.json',
    '404.html',
    'delivery-manifest.json',
    'configs/_global/control.json',
    'sellers.json',
    'supply/sellers.json',
    'traffic-gate/index.html',
    'assets/traffic-gate/horus-traffic-gate.js',
];
foreach ($required as $path) {
    if (! is_file($root.DIRECTORY_SEPARATOR.$path)) {
        fwrite(STDERR, "Missing static delivery file: {$path}.\n");
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

$routes = json_decode($files['_routes.json'], true, 512, JSON_THROW_ON_ERROR);
if (($routes['version'] ?? null) !== 1 || ($routes['include'] ?? null) !== ['/*']) {
    fwrite(STDERR, "Cloudflare Pages _routes.json schema/include contract is invalid.\n");
    exit(1);
}
$routeExclusions = array_values((array) ($routes['exclude'] ?? []));
foreach (['/traffic-gate/*', '/assets/traffic-gate/*'] as $excludedRoute) {
    if (! in_array($excludedRoute, $routeExclusions, true)) {
        fwrite(STDERR, "Traffic Gate Pages Function exclusion missing: {$excludedRoute}.\n");
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

$gateHtml = $files['traffic-gate/index.html'];
$gateJs = $files['assets/traffic-gate/horus-traffic-gate.js'];
$gateCombined = strtolower($gateHtml."\n".$gateJs);
foreach (['siteverify', 'turnstile/v0/siteverify', 'cloudflare_api_token', 'worker secret', 'turnstile secret'] as $forbiddenGateValue) {
    if (str_contains($gateCombined, $forbiddenGateValue)) {
        fwrite(STDERR, "Forbidden backend/secret concept in Traffic Gate static implementation: {$forbiddenGateValue}.\n");
        exit(1);
    }
}
if (! str_contains($gateJs, 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit')) {
    fwrite(STDERR, "Traffic Gate must load the official explicit-render Turnstile script directly from Cloudflare.\n");
    exit(1);
}
if (preg_match('/postMessage\s*\([^,]+,\s*[\'\"]\*[\'\"]\s*\)/', $gateJs)) {
    fwrite(STDERR, "Traffic Gate must not use wildcard postMessage for parent communication.\n");
    exit(1);
}
if (! str_contains($gateJs, "'response-field': false")) {
    fwrite(STDERR, "Traffic Gate must disable Turnstile response-field token storage.\n");
    exit(1);
}
foreach (['HORUS_TRAFFIC_GATE_HELLO', 'HORUS_TRAFFIC_GATE_READY', 'HORUS_TRAFFIC_GATE_PASS', 'HORUS_TRAFFIC_GATE_ERROR', 'HORUS_TRAFFIC_GATE_TIMEOUT', 'HORUS_TRAFFIC_GATE_DENIED'] as $messageType) {
    if (! str_contains($gateJs, $messageType)) {
        fwrite(STDERR, "Traffic Gate protocol message missing: {$messageType}.\n");
        exit(1);
    }
}
foreach (['1x00000000000000000000BB', '2x00000000000000000000BB'] as $testSitekey) {
    if (str_contains($gateJs, $testSitekey)) {
        fwrite(STDERR, "Cloudflare deterministic test Sitekey must not ship in the production Traffic Gate asset.\n");
        exit(1);
    }
}

$loader = $files['hm-loader.js'];
foreach ([
    'HORUS_TRAFFIC_GATE_HELLO',
    'HORUS_TRAFFIC_GATE_PASS',
    'HORUS_TRAFFIC_GATE_DENIED',
    'WAITING_FOR_ACTIVITY',
    'SOFT_ALLOWED',
    'trafficGateDisabled',
    'verify.horusmedia.net',
    'getRandomValues',
] as $loaderContract) {
    if (! str_contains($loader, $loaderContract)) {
        fwrite(STDERR, "Compiled Loader Traffic Gate contract missing: {$loaderContract}.\n");
        exit(1);
    }
}
if (preg_match('/postMessage\s*\([^,]+,\s*[\'\"]\*[\'\"]\s*\)/', $loader)) {
    fwrite(STDERR, "Compiled Loader must not use wildcard postMessage for Traffic Gate communication.\n");
    exit(1);
}
if (str_contains(strtolower($loader), 'turnstile/v0/siteverify')) {
    fwrite(STDERR, "Compiled Loader must not contain Turnstile Siteverify.\n");
    exit(1);
}
foreach (['1x00000000000000000000BB', '2x00000000000000000000BB'] as $testSitekey) {
    if (str_contains($loader, $testSitekey)) {
        fwrite(STDERR, "Cloudflare deterministic test Sitekey must not ship in the compiled Loader.\n");
        exit(1);
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
foreach (['/traffic-gate/*', "script-src 'self' https://challenges.cloudflare.com", 'frame-src https://challenges.cloudflare.com', 'frame-ancestors https:', '/assets/traffic-gate/*'] as $header) {
    if (! str_contains($headers, $header)) {
        fwrite(STDERR, "Traffic Gate _headers policy missing: {$header}.\n");
        exit(1);
    }
}
if (str_contains($headers, 'X-Frame-Options: DENY') || str_contains($headers, 'X-Frame-Options: SAMEORIGIN')) {
    fwrite(STDERR, "Traffic Gate static snapshot cannot carry a frame-blocking X-Frame-Options policy.\n");
    exit(1);
}

fwrite(STDOUT, "Validated ".count($files)." static files; manifest {$manifest['manifestHash']}.\n");
