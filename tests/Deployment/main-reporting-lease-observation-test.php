<?php

declare(strict_types=1);

define('HORUS_MAIN_LEASE_TEST_ONLY', true);
require dirname(__DIR__, 2).'/ops/audit/observe-main-reporting-lease.php';

function check(bool $condition): void
{
    if (! $condition) throw new RuntimeException('Fixture failed');
}
function rejects(callable $fixture): void
{
    try { $fixture(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Fixture failed to reject');
}

$helper = HorusMainReportingLeaseObservation::class;
$expected = str_repeat('a', 40);
foreach ([...$helper::KNOWN_RELEASES, $expected] as $release) {
    check($helper::release("release_id=$release\nartifact_sha256=fixture\n", $expected) === $release);
}
foreach (["release_id=".str_repeat('b', 40), "release_id=$expected\nrelease_id=$expected\n", "release_id=$expected\r\n", '', "release_id=$expected injected"] as $bad) {
    rejects(fn () => $helper::release($bad, $expected));
}
rejects(fn () => $helper::release("release_id=$expected\n", 'invalid'));
foreach (['reporting:sync-site-gam', "'/usr/bin/php8.4' 'artisan' reporting:sync-site-gam", "php artisan 'reporting:sync-site-gam'", 'php artisan reporting:sync-site-gam --site=example'] as $command) check($helper::isMainCommand($command));
foreach (['reporting:sync-site-gam-video', 'reporting:sync-site-gam-suffix', 'reporting:sync-site-gam_video', 'not-reporting:sync-site-gam', ''] as $command) check(! $helper::isMainCommand($command));
$active = $helper::lease(2000, 1000, 1440);
check($active['status'] === 'ACTIVE' && $active['expiry_age_seconds'] === -1000 && $active['configured_schedule_ttl_seconds'] === 86400);
check($active['expiration_utc'] === '1970-01-01T00:33:20Z' && $active['lease_created_at'] === 'UNPROVEN');
check($active['remaining_seconds'] === 1000 && $active['lease_age_seconds'] === 'UNKNOWN');
$legacy = $helper::lease(2000, 1000, 10);
check($legacy['duration_relation'] === 'LEGACY_OR_DIFFERENT_DURATION');
check($legacy['expiration_utc'] === $active['expiration_utc'] && $legacy['remaining_seconds'] === 1000);
check($legacy['lease_age_seconds'] === 'UNKNOWN' && $legacy['original_lease_ttl'] === 'UNPROVEN');
check($helper::lease(1000, 2000, 10)['remaining_seconds'] === 0);
check($helper::lease(1000, 1000, 10)['status'] === 'EXPIRED');
check($helper::lease(999, 1000, 10)['expiry_age_seconds'] === 1);
check($helper::lease(null, 1000, 10)['expiration_utc'] === null);
check($helper::lease(null, 1000, 10)['status'] === 'ABSENT');
foreach ([[0, 1000, 10], [2000, 0, 10], [2000, 1000, 0], [2000, 1000, 10081]] as $bad) rejects(fn () => $helper::lease(...$bad));
$operation = hash('sha256', 'eltx1/hm01:fixture');
$id = $helper::auditId($operation);
check((bool) preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id));
check($id === $helper::auditId($operation));
check($id !== $helper::auditId(hash('sha256', 'eltx1/hm01:other')));
foreach (['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('G', 64)] as $bad) rejects(fn () => $helper::auditId($bad));
$metadata = ['schema' => $helper::SCHEMA, 'operation_id' => $operation, 'lease' => $active,
    'configuration_status' => 'PASS', 'single_writer_inventory' => 'UNPROVEN', 'nonmultiplexed_topology' => 'UNPROVEN',
    'private_extra' => 'not-public'];
$public = $helper::publicResult($metadata, 'RECORDED');
check(array_keys($public) === ['schema_version', 'observation', 'audit_status', 'lease_status', 'configuration_status', 'global_topology']);
check(! str_contains(json_encode($public), 'not-public') && ! str_contains(json_encode($public), $operation));
foreach (['observed_at_utc', 'expiration_utc', 'expiry_age_seconds', 'remaining_seconds', 'configured_schedule_ttl_seconds'] as $privateKey) check(! str_contains(json_encode($public), $privateKey));
rejects(fn () => $helper::publicResult($metadata, 'private'));
rejects(fn () => $helper::publicResult(array_replace($metadata, ['single_writer_inventory' => 'PROVEN']), 'RECORDED'));
$existing = (object) ['event' => $helper::EVENT, 'actor_id' => null, 'actor_type' => null,
    'organization_id' => null, 'auditable_id' => null, 'auditable_type' => null, 'metadata' => $metadata];
check($helper::matchesExisting($existing, $operation));
check(! $helper::matchesExisting($existing, hash('sha256', 'other')));
foreach (['event', 'actor_id', 'actor_type', 'organization_id', 'auditable_id', 'auditable_type'] as $field) {
    $changed = clone $existing;
    $changed->$field = 'wrong';
    check(! $helper::matchesExisting($changed, $operation));
}

// A deploy to another allowed release must still invalidate the pinned observation.
$directory = sys_get_temp_dir().'/hm-lease-fixture-'.bin2hex(random_bytes(8));
$cwd = getcwd();
mkdir($directory);
mkdir($directory.'/one');
mkdir($directory.'/two');
file_put_contents($directory.'/one/.horus-release', "release_id=$expected\n");
file_put_contents($directory.'/two/.horus-release', 'release_id='.$helper::KNOWN_RELEASES[0]."\n");
symlink($directory.'/one', $directory.'/current');
try {
    chdir($directory.'/one');
    $snapshot = $helper::snapshot($directory.'/current', $expected);
    $helper::assertSnapshot($directory.'/current', $expected, $snapshot);
    file_put_contents($directory.'/one/.horus-release', "release_id=$expected\nchanged=1\n");
    rejects(fn () => $helper::assertSnapshot($directory.'/current', $expected, $snapshot));
    file_put_contents($directory.'/one/.horus-release', "release_id=$expected\n");
    unlink($directory.'/current');
    symlink($directory.'/two', $directory.'/current');
    rejects(fn () => $helper::assertSnapshot($directory.'/current', $expected, $snapshot));
    unlink($directory.'/one/.horus-release');
    symlink($directory.'/two/.horus-release', $directory.'/one/.horus-release');
    rejects(fn () => $helper::snapshot($directory.'/one', $expected));
} finally {
    chdir($cwd);
    unlink($directory.'/current');
    unlink($directory.'/one/.horus-release');
    unlink($directory.'/two/.horus-release');
    rmdir($directory.'/one');
    rmdir($directory.'/two');
    rmdir($directory);
}
echo "Main reporting lease pure fixtures: PASS\n";
