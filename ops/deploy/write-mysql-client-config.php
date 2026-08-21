<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: write-mysql-client-config.php APP_ROOT ENV_FILE OUTPUT_CNF\n");
    exit(2);
}

[$script, $appRoot, $envFile, $outputFile] = $argv;
$autoload = rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

if (! is_file($autoload) || ! is_file($envFile)) {
    fwrite(STDERR, "Application autoloader or environment file is missing.\n");
    exit(3);
}

require $autoload;

$values = Dotenv\Dotenv::createImmutable(dirname($envFile), basename($envFile))->safeLoad();
$connection = strtolower(trim((string) ($values['DB_CONNECTION'] ?? 'mysql')));

if (! in_array($connection, ['mysql', 'mariadb'], true)) {
    fwrite(STDERR, "Atomic deployment backup supports MySQL/MariaDB production connections only.\n");
    exit(4);
}

$host = (string) ($values['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($values['DB_PORT'] ?? '3306');
$database = (string) ($values['DB_DATABASE'] ?? '');
$username = (string) ($values['DB_USERNAME'] ?? '');
$password = (string) ($values['DB_PASSWORD'] ?? '');
$dbUrl = trim((string) ($values['DB_URL'] ?? ''));

if ($dbUrl !== '') {
    $parts = parse_url($dbUrl);
    if (! is_array($parts)) {
        fwrite(STDERR, "DB_URL could not be parsed.\n");
        exit(5);
    }

    $host = isset($parts['host']) ? (string) $parts['host'] : $host;
    $port = isset($parts['port']) ? (string) $parts['port'] : $port;
    $username = isset($parts['user']) ? rawurldecode((string) $parts['user']) : $username;
    $password = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : $password;
    if (isset($parts['path'])) {
        $database = rawurldecode(ltrim((string) $parts['path'], '/')) ?: $database;
    }
}

foreach (['host' => $host, 'port' => $port, 'database' => $database, 'username' => $username] as $label => $value) {
    if (trim($value) === '') {
        fwrite(STDERR, "Database {$label} is empty.\n");
        exit(6);
    }
}

$escape = static function (string $value): string {
    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new RuntimeException('Database client values must not contain line breaks.');
    }

    return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
};

try {
    $contents = "[client]\n"
        .'host="'.$escape($host)."\"\n"
        .'port="'.$escape($port)."\"\n"
        .'user="'.$escape($username)."\"\n"
        .'password="'.$escape($password)."\"\n";
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(7);
}

if (file_put_contents($outputFile, $contents, LOCK_EX) === false || ! chmod($outputFile, 0600)) {
    fwrite(STDERR, "Could not create secure MySQL client configuration.\n");
    exit(8);
}

fwrite(STDOUT, $database);
