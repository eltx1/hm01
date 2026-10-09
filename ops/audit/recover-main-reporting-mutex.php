<?php
/** Targeted legacy main-lease recovery for the operator-confirmed single scheduler host. */
ini_set('display_errors', '0');
ini_set('log_errors', '0');

function hmMainRecoveryStage(string $stage): void { $GLOBALS['hm_main_recovery_stage']=$stage; }

function hmMainRecoveryLeaseEligible(?array $row, int $now): bool
{
    if (!$row || !is_string($row['key'] ?? null) || $row['key']==='' || strlen($row['key'])>255
        || !is_string($row['owner'] ?? null) || $row['owner']==='' || strlen($row['owner'])>255
        || !preg_match('/^[0-9]{1,12}$/D', (string) ($row['expiration'] ?? ''))) return false;
    $expiration=(int)$row['expiration'];
    // More than 15 minutes remaining distinguishes a legacy 24-hour lease
    // from a new 10-minute lease, including bounded app/DB clock skew.
    return $expiration>$now+900 && $now-($expiration-86400)>21600;
}

function hmMainRecoverySameLease(?array $first, ?array $second): bool
{
    return $first!==null && $second!==null
        && hash_equals((string)$first['key'],(string)$second['key'])
        && hash_equals((string)$first['owner'],(string)$second['owner'])
        && (string)$first['expiration']===(string)$second['expiration'];
}

function hmMainRecoveryEligible(array $first, array $second, float $elapsed): bool
{
    return $elapsed>=65 && $elapsed<=180 && $second['now']>$first['now']
        && abs(($second['now']-$first['now'])-$elapsed)<=5
        && hmMainRecoveryLeaseEligible($first['lease'],$first['now'])
        && hmMainRecoveryLeaseEligible($second['lease'],$second['now'])
        && hmMainRecoverySameLease($first['lease'],$second['lease'])
        && is_int($first['heartbeat']) && is_int($second['heartbeat'])
        && $first['heartbeat']<=$first['now'] && $first['now']-$first['heartbeat']<=300
        && $second['heartbeat']>$first['heartbeat'] && $second['heartbeat']<=$second['now']
        && $second['now']-$second['heartbeat']<=300
        && $first['process']==='ABSENT' && $second['process']==='ABSENT';
}

function hmMainRecoveryProcess(string|false $status, string|false $cmd, bool $exists): bool
{
    if (!$exists) return false;
    if ($status===false || strlen($status)>16384 || !preg_match('/^State:\s+([A-Za-z])/m',$status,$state)) throw new RuntimeException();
    if (in_array($state[1],['Z','X','x'],true) || preg_match('/^Kthread:\s+1\s*$/m',$status)) return false;
    if ($cmd===false || $cmd==='' || strlen($cmd)>65536 || !str_ends_with($cmd,chr(0))) throw new RuntimeException();
    $args=explode(chr(0),$cmd);
    $command='reporting:sync-site-gam';
    $index=array_search($command,$args,true);
    if ($index!==false && $index>0 && in_array('artisan',array_map('basename',array_slice($args,0,$index)),true)) return true;
    foreach ($args as $arg) {
        if (str_contains($arg,'artisan') && preg_match('/(?<![A-Za-z0-9_:-])'.preg_quote($command,'/').'(?![A-Za-z0-9_:-])/',$arg)) return true;
    }
    return false;
}

function hmMainRecoveryProcVisibility(string|false $mountinfo): bool
{
    if ($mountinfo===false || strlen($mountinfo)>1048576) return false;
    $found=0;
    foreach (explode("\n",$mountinfo) as $line) {
        $parts=explode(' - ',$line);
        if (count($parts)!==2) continue;
        $before=explode(' ',$parts[0]); $after=explode(' ',$parts[1]);
        if (($before[4] ?? '')!=='/proc') continue;
        if (($before[3] ?? '')!=='/' || ($after[0] ?? '')!=='proc' || !isset($before[5],$after[2])) return false;
        foreach (explode(',',$before[5].','.$after[2]) as $option) {
            if (str_starts_with($option,'hidepid=') && $option!=='hidepid=0' && $option!=='hidepid=off') return false;
        }
        $found++;
    }
    return $found===1;
}

function hmMainRecoveryHostEvidence(string|false $selfNamespace,string|false $initNamespace,string|false $initName,
    string|false $selfStatus,string|false $initStatus,string|false $bootId): bool
{
    if (!is_string($selfNamespace) || !preg_match('/^pid:\[[0-9]+\]$/D',$selfNamespace) || $selfNamespace!==$initNamespace
        || !is_string($initName) || !in_array(trim($initName),['systemd','init'],true)
        || !is_string($bootId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',trim($bootId))) return false;
    foreach ([$selfStatus,$initStatus] as $status) {
        if (!is_string($status) || !preg_match('/^NSpid:[ \t]+([0-9]+)[ \t]*$/m',$status)) return false;
    }
    return preg_match('/^NSpid:[ \t]+1[ \t]*$/m',$initStatus)===1;
}
function hmMainRecoveryHostIdentity(): string
{
    hmMainRecoveryStage('HOST_NAMESPACE');
    $selfNamespace=@readlink('/proc/self/ns/pid'); $initNamespace=@readlink('/proc/1/ns/pid');
    $bootId=@file_get_contents('/proc/sys/kernel/random/boot_id',false,null,0,128);
    if (is_file('/.dockerenv') || is_file('/run/.containerenv')
        || !hmMainRecoveryHostEvidence($selfNamespace,$initNamespace,@file_get_contents('/proc/1/comm',false,null,0,128),
            @file_get_contents('/proc/self/status',false,null,0,16385),@file_get_contents('/proc/1/status',false,null,0,16385),$bootId)) throw new RuntimeException();
    hmMainRecoveryStage('PROC_VISIBILITY');
    if (!hmMainRecoveryProcVisibility(@file_get_contents('/proc/self/mountinfo',false,null,0,1048577))) throw new RuntimeException();
    return hash('sha256',$selfNamespace.'|'.trim($bootId));
}

function hmMainRecoveryProcessAbsent(): bool
{
    hmMainRecoveryHostIdentity();
    if (!hmMainRecoveryProcVisibility(@file_get_contents('/proc/self/mountinfo',false,null,0,1048577))) throw new RuntimeException();
    hmMainRecoveryStage('PROC_SCAN');
    $paths=glob('/proc/[0-9]*/status');
    if (!is_array($paths) || !$paths || count($paths)>8192) throw new RuntimeException();
    foreach ($paths as $path) {
        $directory=dirname($path);
        $status=@file_get_contents($path,false,null,0,16385);
        $cmd=@file_get_contents($directory.'/cmdline',false,null,0,65537);
        clearstatcache(true,$directory);
        if (hmMainRecoveryProcess($status,$cmd,is_dir($directory))) return false;
    }
    return true;
}

function hmMainRecoveryCronTarget(string|false|null $cron): ?array
{
    hmMainRecoveryStage('CRON_TARGET');
    if (!is_string($cron) || strlen($cron)>65536) return null;
    $entries=[];
    foreach (explode("\n",$cron) as $line) {
        if (preg_match('/^\s*#/',$line) || !str_contains($line,'artisan') || !str_contains($line,'schedule:run')) continue;
        $prefix='^\s*\*\s+\*\s+\*\s+\*\s+\*\s+';
        $suffix='(?:\s+>>?\s+/[A-Za-z0-9/_.-]+(?:\s+2>&1)?)?\s*$';
        if (preg_match('~'.$prefix.'(/[A-Za-z0-9/_.-]+php[0-9.]*)\s+(/[A-Za-z0-9/_.-]+artisan)\s+schedule:run'.$suffix.'~D',$line,$matches)) {
            $entries[]=[$matches[1],$matches[2]];
        } elseif (preg_match('~'.$prefix.'cd\s+(/[A-Za-z0-9/_.-]+)\s+&&\s+(/[A-Za-z0-9/_.-]+php[0-9.]*)\s+artisan\s+schedule:run'.$suffix.'~D',$line,$matches)) {
            $entries[]=[$matches[2],rtrim($matches[1],'/').'/artisan'];
        } else return null;
    }
    return count($entries)===1 ? $entries[0] : null;
}

function hmMainRecoveryCronEvidencePayload(array $evidence,int $now,int $uid): ?array
{
    if (($evidence['schema_version'] ?? null)!==1 || ($evidence['available'] ?? null)!==true
        || ($evidence['uid'] ?? null)!==$uid || !is_int($evidence['observed_at'] ?? null)
        || $evidence['observed_at']>$now || $now-$evidence['observed_at']>5
        || !is_string($evidence['cron_base64'] ?? null) || strlen($evidence['cron_base64'])>87384) return null;
    $cron=base64_decode($evidence['cron_base64'],true);
    return $cron===false ? null : hmMainRecoveryCronTarget($cron);
}
function hmMainRecoveryFreshCron(): ?array
{
    hmMainRecoveryStage('CRON_EVIDENCE');
    // Fresh same-account SSH-shell evidence; no disabled PHP execution function
    // or privilege escalation is used. The private collector refreshes every second.
    $file=(string)getenv('HM_RECOVERY_CRON_EVIDENCE');
    if (!preg_match('~^/[A-Za-z0-9/_.-]+$~D',$file)) return null;
    clearstatcache(true,$file); clearstatcache(true,dirname($file));
    if (!is_file($file) || is_link($file) || is_link(dirname($file)) || !is_dir(dirname($file))
        || @fileowner($file)!==posix_geteuid() || @fileowner(dirname($file))!==posix_geteuid()
        || (@fileperms($file)&0077)!==0 || (@fileperms(dirname($file))&0077)!==0) return null;
    $bytes=@file_get_contents($file,false,null,0,131073);
    if (!is_string($bytes) || strlen($bytes)>131072) return null;
    $evidence=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    return is_array($evidence) ? hmMainRecoveryCronEvidencePayload($evidence,time(),posix_geteuid()) : null;
}

function hmMainRecoveryEnvironment(string $link, string $root, string $expected, ?array $initialCron=null): array
{
    hmMainRecoveryStage('ENVIRONMENT_IDENTITY');
    clearstatcache(true,$link);
    if (realpath($link)!==$root || realpath('.')!==$root
        || !function_exists('posix_geteuid') || posix_geteuid()!==posix_getuid()
        || @fileowner($root.'/artisan')!==posix_geteuid()) throw new RuntimeException();
    hmMainRecoveryStage('RELEASE_MARKER');
    $marker=@file_get_contents('.horus-release');
    if (!is_string($marker) || preg_match_all('/^release_id=([a-f0-9]{40})$/m',$marker,$matches)!==1 || $matches[1][0]!==$expected) throw new RuntimeException();
    $cron=hmMainRecoveryFreshCron();
    if (!$cron) throw new RuntimeException();
    hmMainRecoveryStage('CRON_TARGET');
    if (!is_executable($cron[0]) || realpath($cron[0])!==realpath(PHP_BINARY)
        || realpath($cron[1])!==realpath('artisan') || ($initialCron!==null && $cron!==$initialCron)) throw new RuntimeException();
    return $cron;
}

function hmMainRecoveryRead(PDO $pdo, string $table, string $heartbeatTable, string $key): array
{
    hmMainRecoveryStage('READ_LEASE');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $now=(int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        if (abs($now-time())>5) throw new RuntimeException();
        $query=$pdo->prepare('SELECT `key`, `owner`, `expiration` FROM `'.$table.'` WHERE `key` = ?');
        $query->execute([$key]); $lease=$query->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($lease && $lease['key']!==$key) throw new RuntimeException();
        $query=$pdo->prepare('SELECT `last_seen_at` FROM `'.$heartbeatTable.'` WHERE `key` = ?');
        $query->execute(['scheduler']); $heartbeat=$query->fetchColumn();
        $parsed=is_string($heartbeat) ? strtotime($heartbeat.' UTC') : false;
        return ['now'=>$now,'lease'=>$lease,'heartbeat'=>$parsed===false ? null : $parsed];
    } finally { $pdo->rollBack(); }
}

function hmMainRecoveryDelete(PDO $pdo, string $table, array $expected, int $now): int
{
    hmMainRecoveryStage('COMPARE_AND_SWAP');
    // A single compare-and-swap only. Never force-release or delete another row.
    $query=$pdo->prepare('DELETE FROM `'.$table.'` WHERE `key` = ? AND HEX(`key`) = HEX(?) AND HEX(`owner`) = HEX(?) AND `expiration` = ? AND `expiration` > ? AND `expiration` < ?');
    $query->execute([$expected['key'],$expected['key'],$expected['owner'],$expected['expiration'],$now+900,$now+64800]);
    return $query->rowCount();
}

// Test-only function entry; production environment variables cannot enable it.
if (defined('HORUS_MAIN_RECOVERY_TEST_ONLY') && HORUS_MAIN_RECOVERY_TEST_ONLY===true) return;

$mode=(string)(getenv('HM_MAIN_RECOVERY_MODE') ?: 'preview');
$output=['schema_version'=>1,'target'=>'MAIN_SYNC_SCHEDULER_MUTEX','mode'=>in_array($mode,['preview','apply'],true) ? $mode : 'preview','status'=>'UNAVAILABLE','changed_rows'=>0];
$pdo=null;
hmMainRecoveryStage('INPUTS');
try {
    if (!in_array($mode,['preview','apply'],true)) throw new RuntimeException();
    // Trusted workflow supplies exactly the separately verified deployed release.
    $expected=(string)getenv('HM_EXPECTED_RELEASE_SHA');
    if (!preg_match('/^[a-f0-9]{40}$/D',$expected)) throw new RuntimeException();
    $link=(string)getenv('HM_RECOVERY_APP_LINK');
    if (!preg_match('~^/[A-Za-z0-9/_.-]+$~D',$link)) throw new RuntimeException();
    $root=realpath($link);
    if (!is_string($root)) throw new RuntimeException();
    $cron=hmMainRecoveryEnvironment($link,$root,$expected);
    hmMainRecoveryStage('BOOTSTRAP');
    require 'vendor/autoload.php';
    $app=require 'bootstrap/app.php';
    $db=null; $originalCache=[]; $originalScheduleStore=null;
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,function($app) use (&$originalCache,&$originalScheduleStore): void {
        hmMainRecoveryStage('BOOTSTRAP_CONFIG');
        $config=$app->make('config');
        $config->set(['logging.default'=>'null','logging.deprecations.channel'=>'null','app.debug'=>false]);
        $originalCache=$config->get('cache');
        $originalScheduleStore=$config->get('cache.schedule_store',Illuminate\Support\Env::get('SCHEDULE_CACHE_DRIVER',static fn()=>Illuminate\Support\Env::get('SCHEDULE_CACHE_STORE'))) ?? $originalCache['default'];
        if (!$app->make('config_loaded_from_cache')) throw new RuntimeException();
        hmMainRecoveryStage('BOOTSTRAP_MANIFEST');
        $packagesPath=$app->getCachedPackagesPath(); $servicesPath=$app->getCachedServicesPath();
        if (!is_file($packagesPath) || !is_readable($packagesPath) || !is_file($servicesPath) || !is_readable($servicesPath)) throw new RuntimeException();
        $packages=require $packagesPath; $services=require $servicesPath;
        if (!is_array($packages) || !is_array($services) || !is_array($services['providers'] ?? null)) throw new RuntimeException();
        $manifest=$app->make(Illuminate\Foundation\PackageManifest::class); $manifest->manifest=$packages;
        $providers=(new Illuminate\Support\Collection($config->get('app.providers')))->partition(static fn($provider)=>str_starts_with($provider,'Illuminate\\'));
        $providers->splice(1,0,[$manifest->providers()]);
        if ($services['providers']!=$providers->collapse()->toArray()) throw new RuntimeException();
    });
    $app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class,function() use (&$db): void {
        hmMainRecoveryStage('DATABASE');
        config(['cache.default'=>'array']);
        $db=Illuminate\Support\Facades\DB::connection();
        if ($db->getDriverName()!=='mysql') throw new RuntimeException();
        $db->statement('SET TRANSACTION READ ONLY'); $db->beginTransaction();
    });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (!$db) throw new RuntimeException();
    hmMainRecoveryStage('SCHEDULE');
    $events=array_values(array_filter(app(Illuminate\Console\Scheduling\Schedule::class)->events(),static fn($event)=>preg_match('/(?:^|[\s\x27\x22])reporting:sync-site-gam(?:[\s\x27\x22]|$)/',(string)($event->command ?? ''))===1));
    if (count($events)!==1) throw new RuntimeException();
    $event=$events[0];
    if (!$event->withoutOverlapping || !in_array($event->expiresAt,[1440,10],true) || $event->expression!=='*/5 * * * *' || $event->runInBackground || $event->onOneServer) throw new RuntimeException();
    hmMainRecoveryStage('CACHE_CONFIGURATION');
    $storeName=$event->mutex->store ?? $originalScheduleStore;
    $store=$originalCache['stores'][$storeName] ?? [];
    if (($store['driver'] ?? '')!=='database') throw new RuntimeException();
    $cacheDb=Illuminate\Support\Facades\DB::connection($store['lock_connection'] ?? $store['connection'] ?? null);
    if ($cacheDb!==$db) throw new RuntimeException();
    $table=$db->getTablePrefix().($store['lock_table'] ?? 'cache_locks');
    $heartbeatTable=$db->getTablePrefix().'system_heartbeats';
    foreach ([$table,$heartbeatTable] as $identifier) if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$identifier)) throw new RuntimeException();
    $key=(string)($store['prefix'] ?? $originalCache['prefix']).$event->mutexName();
    hmMainRecoveryStage('DATABASE');
    $pdo=$db->getPdo();
    if ((bool)$pdo->getAttribute(PDO::ATTR_PERSISTENT)) throw new RuntimeException();
    $db->rollBack();
    // Use this exact PDO session from here onward, with no framework reconnect.
    $hostIdentity=hmMainRecoveryHostIdentity();
    $first=hmMainRecoveryRead($pdo,$table,$heartbeatTable,$key);
    hmMainRecoveryStage('LEASE_ELIGIBILITY');
    if (!$first['lease'] || (int)$first['lease']['expiration']<=$first['now']) {
        $output['status']='NO_ACTION';
    } elseif (!hmMainRecoveryLeaseEligible($first['lease'],$first['now'])) {
        $output['status']='BLOCKED';
    } else {
        $first['process']=hmMainRecoveryProcessAbsent() ? 'ABSENT' : 'PRESENT';
        $start=hrtime(true);
        sleep(65);
        hmMainRecoveryEnvironment($link,$root,$expected,$cron);
        if (!hash_equals($hostIdentity,hmMainRecoveryHostIdentity())) throw new RuntimeException();
        $second=hmMainRecoveryRead($pdo,$table,$heartbeatTable,$key);
        $second['process']=hmMainRecoveryProcessAbsent() ? 'ABSENT' : 'PRESENT';
        $elapsed=(hrtime(true)-$start)/1e9;
        hmMainRecoveryStage('OBSERVATION_STABILITY');
        if (!hmMainRecoveryEligible($first,$second,$elapsed)) {
            $output['status']='BLOCKED';
        } elseif ($mode==='preview') {
            $output['status']='ELIGIBLE';
        } else {
            // Single scheduler host is confirmed for this repair. Recheck every
            // local identity/absence predicate immediately before exact CAS.
            hmMainRecoveryEnvironment($link,$root,$expected,$cron);
            if (!hash_equals($hostIdentity,hmMainRecoveryHostIdentity()) || !hmMainRecoveryProcessAbsent()) throw new RuntimeException();
            $final=hmMainRecoveryRead($pdo,$table,$heartbeatTable,$key);
            hmMainRecoveryStage('FINAL_RECHECK');
            if (!hmMainRecoverySameLease($second['lease'],$final['lease'])
                || !hmMainRecoveryLeaseEligible($final['lease'],$final['now'])
                || $final['now']<$second['now'] || $final['now']-$second['now']>15
                || !is_int($final['heartbeat']) || $final['heartbeat']<$second['heartbeat']
                || $final['heartbeat']>$final['now'] || $final['now']-$final['heartbeat']>300) throw new RuntimeException();
            $pdo->beginTransaction();
            $changed=hmMainRecoveryDelete($pdo,$table,$final['lease'],$final['now']);
            if ($changed!==1) { $pdo->rollBack(); $output['status']='BLOCKED'; }
            else { $pdo->commit(); $output['changed_rows']=1; $output['status']='RELEASED'; }

        }
    }
    $output['stage']=in_array($output['status'],['ELIGIBLE','NO_ACTION','RELEASED'],true) ? 'COMPLETE' : ($GLOBALS['hm_main_recovery_stage'] ?? 'INPUTS');
    echo json_encode($output,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    try { if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable) { /* No raw database error may escape. */ }
    $output['stage']=$GLOBALS['hm_main_recovery_stage'] ?? 'INPUTS';
    echo json_encode($output,JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
