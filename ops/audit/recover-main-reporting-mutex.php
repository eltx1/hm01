<?php
/** Targeted legacy main-lease recovery for the operator-confirmed single scheduler host. */
ini_set('display_errors', '0');
ini_set('log_errors', '0');

function hmMainRecoveryStage(string $stage): void { $GLOBALS['hm_main_recovery_stage']=$stage; }
function hmMainRecoveryReason(string $reason): void { $GLOBALS['hm_main_recovery_reason']=$reason; }

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

function hmMainRecoveryUniquePid(string $status,string $field,int $expected): bool
{
    return preg_match_all('/^'.preg_quote($field,'/').':.*$/m',$status,$matches)===1
        && preg_match('/^'.preg_quote($field,'/').':[ \t]+'.$expected.'[ \t]*$/D',$matches[0][0])===1;
}
function hmMainRecoveryHostEvidence(string|false $selfNamespace,string|false $initNamespace,string|false $initName,
    string|false $selfStatus,string|false $initStatus,string|false $bootId,?int $selfPid=null,bool $positiveWitness=false): bool
{
    if (!is_string($selfNamespace) || !preg_match('/^pid:\[[0-9]+\]$/D',$selfNamespace)
        || !is_string($initName) || !in_array(trim($initName),['systemd','init'],true)
        || !is_string($bootId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',trim($bootId))) return false;
    foreach ([$selfStatus,$initStatus] as $status) {
        if (!is_string($status) || !preg_match('/^NSpid:[ \t]+([0-9]+)[ \t]*$/m',$status)) return false;
    }
    if (!hmMainRecoveryUniquePid($initStatus,'NSpid',1)) return false;
    // A readable disagreement never enters the link-unavailable alternative.
    if ($initNamespace!==false) return $initNamespace===$selfNamespace;
    return $positiveWitness && is_int($selfPid) && $selfPid>0
        && hmMainRecoveryUniquePid($selfStatus,'Pid',$selfPid) && hmMainRecoveryUniquePid($selfStatus,'NSpid',$selfPid)
        && hmMainRecoveryUniquePid($initStatus,'Pid',1);
}
function hmMainRecoveryMountIdentity(string|false $mountinfo): ?string
{
    if (!hmMainRecoveryProcVisibility($mountinfo)) return null;
    foreach(explode("\n",$mountinfo) as $line) {
        $parts=explode(' - ',$line); $before=explode(' ',$parts[0]);
        if (($before[4] ?? '')==='/proc') return hash('sha256',$line);
    }
    return null;
}
function hmMainRecoveryHostSnapshot(): array
{
    return ['self_namespace'=>@readlink('/proc/self/ns/pid'),'init_namespace'=>@readlink('/proc/1/ns/pid'),
        'boot'=>@file_get_contents('/proc/sys/kernel/random/boot_id',false,null,0,128),
        'init_name'=>@file_get_contents('/proc/1/comm',false,null,0,128),
        'self_status'=>@file_get_contents('/proc/self/status',false,null,0,16385),
        'init_status'=>@file_get_contents('/proc/1/status',false,null,0,16385),
        'mount'=>hmMainRecoveryMountIdentity(@file_get_contents('/proc/self/mountinfo',false,null,0,1048577)),
        'container'=>is_file('/.dockerenv') || is_file('/run/.containerenv')];
}
function hmMainRecoveryHostAfterWait(array $before,array $after,int $selfPid): bool
{
    return $after['container']===false && $before['self_namespace']===$after['self_namespace']
        && is_string($before['boot']) && is_string($after['boot']) && trim($before['boot'])===trim($after['boot'])
        && is_string($before['mount']) && $before['mount']===$after['mount']
        && hmMainRecoveryHostEvidence($after['self_namespace'],$after['init_namespace'],$after['init_name'],
            $after['self_status'],$after['init_status'],$after['boot'],$selfPid,true)
        && hmMainRecoveryUniquePid($after['self_status'],'Pid',$selfPid)
        && hmMainRecoveryUniquePid($after['self_status'],'NSpid',$selfPid)
        && hmMainRecoveryUniquePid($after['init_status'],'Pid',1);
}
function hmMainRecoveryHostIdentity(): string
{
    hmMainRecoveryStage('HOST_NAMESPACE');
    $snapshot=hmMainRecoveryHostSnapshot();
    if ($snapshot['container']) throw new RuntimeException();
    hmMainRecoveryStage('PROC_VISIBILITY');
    if ($snapshot['mount']===null) throw new RuntimeException();
    hmMainRecoveryStage('HOST_NAMESPACE');
    $witness=false;
    if ($snapshot['init_namespace']===false) {
        hmMainRecoveryReason('PID1_LINK_UNAVAILABLE');
        if (!hmMainRecoveryHostEvidence($snapshot['self_namespace'],false,$snapshot['init_name'],$snapshot['self_status'],
            $snapshot['init_status'],$snapshot['boot'],getmypid(),true)) throw new RuntimeException();
        $witness=hmMainRecoveryNaturalTarget()!==null;
        hmMainRecoveryStage('HOST_NAMESPACE');
        if (!$witness) { hmMainRecoveryReason('PID1_LINK_WITNESS_UNAVAILABLE'); throw new RuntimeException(); }
        // A witness may take time to appear. Re-read every host predicate and
        // reject a newly readable mismatch or any namespace/boot/mount change.
        $after=hmMainRecoveryHostSnapshot();
        if (!hmMainRecoveryHostAfterWait($snapshot,$after,getmypid())) throw new RuntimeException();
        $snapshot=$after;
    }
    if (!hmMainRecoveryHostEvidence($snapshot['self_namespace'],$snapshot['init_namespace'],$snapshot['init_name'],
        $snapshot['self_status'],$snapshot['init_status'],$snapshot['boot'],getmypid(),$witness)) throw new RuntimeException();
    hmMainRecoveryReason('NONE');
    return hash('sha256',$snapshot['self_namespace'].'|'.trim($snapshot['boot']).'|'.$snapshot['mount']);
}

function hmMainRecoveryProcessStart(string|false $stat,int $pid): ?string
{
    if (!is_string($stat) || !str_starts_with($stat,$pid.' (') || ($end=strrpos($stat,') '))===false) return null;
    $fields=preg_split('/\s+/',trim(substr($stat,$end+2)));
    return isset($fields[19]) && preg_match('/^[0-9]+$/D',$fields[19]) ? $fields[19] : null;
}
function hmMainRecoveryOldScheduler(string|false $status,string|false $cmd,string|false $stat,int $pid,int $uid,int $boot,int $hz,int $acquired): bool
{
    if (!is_string($status) || !preg_match('/^Uid:[ \t]+([0-9]+)[ \t]+([0-9]+)/m',$status,$m) || (int)$m[1]!==$uid || (int)$m[2]!==$uid) return false;
    if (!is_string($cmd)) return false;
    $args=explode(chr(0),$cmd); $index=array_search('schedule:run',$args,true);
    if ($index===false || $index<2 || !preg_match('/^php(?:[0-9]+(?:\.[0-9]+)*)?$/D',basename($args[0]))
        || !in_array('artisan',array_map('basename',array_slice($args,1,$index-1)),true)) return false;
    $start=hmMainRecoveryProcessStart($stat,$pid);
    if ($start===null || $hz<1 || $boot<1) throw new RuntimeException();
    // Old-release parents are included: their cwd need not equal today's release.
    return $boot+((float)$start/$hz)<=$acquired+5;
}

function hmMainRecoveryProcessAbsent(int $legacyAcquiredAt): bool
{
    hmMainRecoveryHostIdentity();
    if (!hmMainRecoveryProcVisibility(@file_get_contents('/proc/self/mountinfo',false,null,0,1048577))) throw new RuntimeException();
    hmMainRecoveryStage('PROC_SCAN');
    $hzValue=(string)getenv('HM_RECOVERY_CLK_TCK');
    $kernel=@file_get_contents('/proc/stat',false,null,0,131073);
    if (!preg_match('/^[1-9][0-9]{0,5}$/D',$hzValue) || !is_string($kernel) || !preg_match('/^btime ([0-9]+)$/m',$kernel,$boot)) throw new RuntimeException();
    $paths=glob('/proc/[0-9]*/status');
    if (!is_array($paths) || !$paths || count($paths)>8192) throw new RuntimeException();
    foreach ($paths as $path) {
        $directory=dirname($path);
        $status=@file_get_contents($path,false,null,0,16385);
        $cmd=@file_get_contents($directory.'/cmdline',false,null,0,65537);
        clearstatcache(true,$directory);
        if (hmMainRecoveryProcess($status,$cmd,is_dir($directory))) return false;
        if (is_dir($directory) && hmMainRecoveryOldScheduler($status,$cmd,@file_get_contents($directory.'/stat',false,null,0,16385),
            (int)basename($directory),posix_geteuid(),(int)$boot[1],(int)$hzValue,$legacyAcquiredAt)) return false;
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
    if (($evidence['schema_version'] ?? null)!==1) { hmMainRecoveryReason('CRON_SCHEMA_INVALID'); return null; }
    if (($evidence['uid'] ?? null)!==$uid) { hmMainRecoveryReason('CRON_UID_MISMATCH'); return null; }
    if (!is_int($evidence['observed_at'] ?? null)) { hmMainRecoveryReason('CRON_TIME_INVALID'); return null; }
    if ($evidence['observed_at']>$now) { hmMainRecoveryReason('CRON_CAPTURE_FUTURE'); return null; }
    if ($now-$evidence['observed_at']>5) { hmMainRecoveryReason('CRON_CAPTURE_STALE'); return null; }
    if (($evidence['available'] ?? null)===false) { hmMainRecoveryReason('CRON_CAPTURE_UNAVAILABLE'); return null; }
    if (($evidence['available'] ?? null)!==true) { hmMainRecoveryReason('CRON_SCHEMA_INVALID'); return null; }
    if (!is_string($evidence['cron_base64'] ?? null) || strlen($evidence['cron_base64'])>87384) { hmMainRecoveryReason('CRON_ENCODING_INVALID'); return null; }
    $cron=base64_decode($evidence['cron_base64'],true);
    if ($cron===false) { hmMainRecoveryReason('CRON_ENCODING_INVALID'); return null; }
    $target=hmMainRecoveryCronTarget($cron);
    if ($target===null) hmMainRecoveryReason('CRON_TARGET_UNCLASSIFIED');
    return $target;
}
function hmMainRecoveryNaturalPayload(array $evidence,int $now,int $uid,string $php,string $artisan,string $namespace,string $release,int $observerPid): ?array
{
    $witness=$evidence['witness'] ?? null;
    if (($evidence['schema_version'] ?? null)!==1 || ($evidence['source'] ?? null)!=='CANONICAL_PROCESS_IDENTITY'
        || ($evidence['available'] ?? null)!==true || ($evidence['collector_pid'] ?? null)!==$observerPid
        || !is_string($evidence['collector_start'] ?? null) || !preg_match('/^[0-9]+$/D',$evidence['collector_start'])
        || ($evidence['uid'] ?? null)!==$uid || ($evidence['namespace'] ?? null)!==$namespace
        || ($evidence['release_sha'] ?? null)!==$release || !is_array($witness)
        || !is_int($witness['pid'] ?? null) || $witness['pid']<1 || !is_string($witness['start'] ?? null)
        || !preg_match('/^[0-9]+$/D',$witness['start']) || ($witness['uid'] ?? null)!==$uid
        || ($witness['php'] ?? null)!==$php || ($witness['artisan'] ?? null)!==$artisan || ($witness['namespace'] ?? null)!==$namespace
        || !is_int($witness['observed_at'] ?? null) || $witness['observed_at']>$now || $now-$witness['observed_at']>5) return null;
    return [$php,$artisan];
}
function hmMainRecoveryNaturalTarget(): ?array
{
    hmMainRecoveryStage('CRON_EVIDENCE');
    $file=(string)getenv('HM_RECOVERY_PROCESS_EVIDENCE'); $pidValue=(string)getenv('HM_RECOVERY_OBSERVER_PID');
    $deadlineValue=(string)getenv('HM_RECOVERY_OBSERVER_DEADLINE');
    if (!preg_match('~^/[A-Za-z0-9/_.-]+$~D',$file) || !preg_match('/^[1-9][0-9]*$/D',$pidValue)
        || !preg_match('/^[1-9][0-9]*$/D',$deadlineValue) || (int)$deadlineValue>time()+185) { hmMainRecoveryReason('PROCESS_EVIDENCE_INVALID'); return null; }
    $uid=posix_geteuid(); $pid=(int)$pidValue; $deadline=(int)$deadlineValue;
    $waitUntil=min(time()+90,$deadline); $namespace=@readlink('/proc/self/ns/pid');
    do {
        clearstatcache();
        if (is_file($file)) {
            if (is_link($file) || is_link(dirname($file)) || @fileowner($file)!==$uid || @fileowner(dirname($file))!==$uid
                || (@fileperms($file)&0077)!==0 || (@fileperms(dirname($file))&0077)!==0) { hmMainRecoveryReason('PROCESS_EVIDENCE_INVALID'); return null; }
            $bytes=@file_get_contents($file,false,null,0,16385);
            try { $evidence=is_string($bytes) && strlen($bytes)<=16384 ? json_decode($bytes,true,512,JSON_THROW_ON_ERROR) : null; }
            catch (Throwable) { $evidence=null; }
            if (!is_array($evidence)) { hmMainRecoveryReason('PROCESS_EVIDENCE_INVALID'); return null; }
            if (($evidence['available'] ?? null)===true && is_string($namespace)) {
                $target=hmMainRecoveryNaturalPayload($evidence,time(),$uid,(string)realpath(PHP_BINARY),(string)realpath('artisan'),$namespace,(string)getenv('HM_EXPECTED_RELEASE_SHA'),$pid);
                if ($target!==null) {
                    $start=hmMainRecoveryProcessStart(@file_get_contents('/proc/'.$pid.'/stat',false,null,0,16385),$pid);
                    $known=$GLOBALS['hm_main_recovery_observer_start'] ?? null;
                    $live=$start!==null && hash_equals($evidence['collector_start'],$start) && @readlink('/proc/'.$pid.'/ns/pid')===$namespace;
                    $finished=$start===null && time()>=$deadline && is_string($known) && hash_equals($known,$evidence['collector_start']);
                    if (!$live && !$finished) { hmMainRecoveryReason('PROCESS_EVIDENCE_INVALID'); return null; }
                    if ($known!==null && $known!==$evidence['collector_start']) { hmMainRecoveryReason('PROCESS_EVIDENCE_INVALID'); return null; }
                    $GLOBALS['hm_main_recovery_observer_start']=$evidence['collector_start'];
                    hmMainRecoveryReason('NONE'); return $target;
                }
            }
        }
        if (time()>=$waitUntil) break;
        sleep(1);
    } while(true);
    hmMainRecoveryReason('PROCESS_WITNESS_UNAVAILABLE'); return null;
}

function hmMainRecoveryFreshCron(): ?array
{
    hmMainRecoveryStage('CRON_EVIDENCE'); hmMainRecoveryReason('NONE');
    // Fresh same-account SSH-shell evidence; no disabled PHP execution function
    // or privilege escalation is used. The private collector refreshes every second.
    $file=(string)getenv('HM_RECOVERY_CRON_EVIDENCE');
    if (!preg_match('~^/[A-Za-z0-9/_.-]+$~D',$file)) { hmMainRecoveryReason('CRON_PATH_INVALID'); return null; }
    clearstatcache(true,$file); clearstatcache(true,dirname($file));
    if (!is_file($file)) { hmMainRecoveryReason('CRON_FILE_MISSING'); return null; }
    if (is_link($file) || is_link(dirname($file))) { hmMainRecoveryReason('CRON_FILE_LINK'); return null; }
    if (!is_dir(dirname($file))) { hmMainRecoveryReason('CRON_DIRECTORY_INVALID'); return null; }
    if (@fileowner($file)!==posix_geteuid() || @fileowner(dirname($file))!==posix_geteuid()) { hmMainRecoveryReason('CRON_FILE_OWNER'); return null; }
    if ((@fileperms($file)&0077)!==0 || (@fileperms(dirname($file))&0077)!==0) { hmMainRecoveryReason('CRON_FILE_MODE'); return null; }
    $bytes=@file_get_contents($file,false,null,0,131073);
    if (!is_string($bytes)) { hmMainRecoveryReason('CRON_FILE_UNREADABLE'); return null; }
    if (strlen($bytes)>131072) { hmMainRecoveryReason('CRON_FILE_OVERSIZE'); return null; }
    try { $evidence=json_decode($bytes,true,512,JSON_THROW_ON_ERROR); }
    catch (Throwable) { hmMainRecoveryReason('CRON_JSON_INVALID'); return null; }
    if (!is_array($evidence)) { hmMainRecoveryReason('CRON_JSON_INVALID'); return null; }
    $target=hmMainRecoveryCronEvidencePayload($evidence,time(),posix_geteuid());
    // Only a genuinely unavailable capture may use positive observed identity.
    // Invalid permissions, stale data or unclassified/conflicting targets stay fatal.
    if ($target===null && ($GLOBALS['hm_main_recovery_reason'] ?? '')==='CRON_CAPTURE_UNAVAILABLE') return hmMainRecoveryNaturalTarget();
    return $target;
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
hmMainRecoveryStage('INPUTS'); hmMainRecoveryReason('NONE');
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
        $first['process']=hmMainRecoveryProcessAbsent((int)$first['lease']['expiration']-86400) ? 'ABSENT' : 'PRESENT';
        $start=hrtime(true);
        sleep(65);
        hmMainRecoveryEnvironment($link,$root,$expected,$cron);
        if (!hash_equals($hostIdentity,hmMainRecoveryHostIdentity())) throw new RuntimeException();
        $second=hmMainRecoveryRead($pdo,$table,$heartbeatTable,$key);
        $second['process']=hmMainRecoveryProcessAbsent((int)$first['lease']['expiration']-86400) ? 'ABSENT' : 'PRESENT';
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
            if (!hash_equals($hostIdentity,hmMainRecoveryHostIdentity()) || !hmMainRecoveryProcessAbsent((int)$first['lease']['expiration']-86400)) throw new RuntimeException();
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
    $output['reason']=$GLOBALS['hm_main_recovery_reason'] ?? 'NONE';
    echo json_encode($output,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    try { if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable) { /* No raw database error may escape. */ }
    $output['stage']=$GLOBALS['hm_main_recovery_stage'] ?? 'INPUTS';
    $output['reason']=$GLOBALS['hm_main_recovery_reason'] ?? 'NONE';
    echo json_encode($output,JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
