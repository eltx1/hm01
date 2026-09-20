<?php

// Run on the trusted production host. The credential is accepted only on stdin,
// never in arguments, source control, command output, or public storage.
$options = getopt('', ['env:', 'account:', 'project:', 'apply']);
$envPath = realpath((string) ($options['env'] ?? ''));
if (! $envPath || ! is_file($envPath) || ! is_writable($envPath)) {
    fwrite(STDERR, "A writable shared environment file is required.\n");
    exit(1);
}
$account = (string) ($options['account'] ?? '');
$project = (string) ($options['project'] ?? '');
if (! preg_match('/^[a-f0-9]{32}$/', $account) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,57}$/', $project)) {
    fwrite(STDERR, "A valid existing Cloudflare account and Pages project are required.\n");
    exit(1);
}
$token = trim(stream_get_contents(STDIN, 4097));
if (strlen($token) < 20 || strlen($token) > 4096 || preg_match('/\s/', $token)) {
    fwrite(STDERR, "A valid non-empty Cloudflare deployment credential must be supplied on stdin.\n");
    exit(1);
}
if (! array_key_exists('apply', $options)) {
    echo "Dry run: would install a private credential and enable five-minute active delivery. No files changed.\n";
    exit(0);
}
umask(0077);
$directory = dirname($envPath).'/secrets';
$credentialPath = $directory.'/edge-cloudflare-token';
if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || is_link($directory) || is_link($credentialPath)) {
    fwrite(STDERR, "Private credential directory could not be prepared.\n");
    exit(1);
}
$lock = fopen($envPath.'.automation.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX)) {
    fwrite(STDERR, "Could not acquire configuration lock.\n");
    exit(1);
}
$environment = file_get_contents($envPath);
if ($environment === false || str_contains($credentialPath, '"') || str_contains($credentialPath, "\n")) {
    fwrite(STDERR, "Could not read a safe configuration path.\n");
    exit(1);
}
$settings = [
    'HORUS_STATIC_DELIVERY_DRIVER' => 'cloudflare-pages-direct',
    'HORUS_EDGE_CLOUDFLARE_TOKEN_REFERENCE' => '"file:'.$credentialPath.'"',
    'HORUS_EDGE_CLOUDFLARE_ACCOUNT_ID' => $account,
    'HORUS_EDGE_CLOUDFLARE_PROJECT' => $project,
    'HORUS_EDGE_CLOUDFLARE_BRANCH' => 'main',
    'HORUS_STATIC_DELIVERY_BATCH_INTERVAL_MINUTES' => '5',
    'HORUS_STATIC_DELIVERY_DRY_RUN' => 'false',
];
foreach ($settings as $key => $value) {
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
    $environment = preg_match($pattern, $environment)
        ? preg_replace_callback($pattern, fn () => $key.'='.$value, $environment)
        : rtrim($environment)."\n".$key.'='.$value."\n";
}
// Write the credential first; never enable active submission with a missing file.
foreach ([$credentialPath => $token."\n", $envPath => $environment] as $path => $contents) {
    $temporary = tempnam(dirname($path), '.automation-');
    if ($temporary === false || file_put_contents($temporary, $contents) !== strlen($contents)
        || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
        fwrite(STDERR, "Could not atomically install automation configuration.\n");
        exit(1);
    }
}
file_put_contents(dirname($envPath).'/static-delivery-automation.log',
    gmdate('c')." active delivery provisioned; batch interval=5; credential redacted\n", FILE_APPEND | LOCK_EX);
flock($lock, LOCK_UN);
fclose($lock);
echo "Active delivery configuration installed. Rebuild the application config cache and verify scheduler execution.\n";
