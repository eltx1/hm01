<?php
/** Bounded positive target-identity observer. Never launches or infers recurrence of a scheduler. */
ini_set('display_errors','0'); ini_set('log_errors','0');
function hmSchedulerStart(string|false $stat,int $pid): ?string
{
    if (!is_string($stat) || !str_starts_with($stat,$pid.' (') || ($end=strrpos($stat,') '))===false) return null;
    $fields=preg_split('/\s+/',trim(substr($stat,$end+2)));
    return isset($fields[19]) && preg_match('/^[0-9]+$/D',$fields[19]) ? $fields[19] : null;
}
function hmSchedulerStableStart(string|false $before,string|false $after,int $pid): bool
{
    $start=hmSchedulerStart($before,$pid);
    return $start!==null && $start===hmSchedulerStart($after,$pid);
}
function hmSchedulerUid(string|false $status,int $uid): bool
{
    return is_string($status) && preg_match('/^Uid:[ \t]+([0-9]+)[ \t]+([0-9]+)[ \t]+([0-9]+)[ \t]+([0-9]+)[ \t]*$/m',$status,$m)
        && count(array_filter(array_slice($m,1),static fn($value)=>(int)$value!==$uid))===0;
}
function hmSchedulerArtisan(string|false $cmd): ?string
{
    if (!is_string($cmd) || $cmd==='' || strlen($cmd)>65536 || !str_ends_with($cmd,chr(0))) return null;
    $args=explode(chr(0),$cmd); array_pop($args);
    if (count($args)<3 || count($args)>32) return null;
    $index=1; $script=null;
    while(isset($args[$index])) {
        $value=$args[$index];
        if ($value==='-n') { $index++; continue; }
        if ($value==='-d' || $value==='-c') {
            if (!isset($args[$index+1]) || $args[$index+1]==='' || str_starts_with($args[$index+1],'-')) return null;
            $index+=2; continue;
        }
        if ($value==='-f' || $value==='--file') {
            if (!isset($args[$index+1]) || $args[$index+1]==='' || str_starts_with($args[$index+1],'-')) return null;
            $script=$args[$index+1]; $index+=2; break;
        }
        // Reject eval/process-code variants, combined options and unknown prefixes.
        if ($value==='' || str_starts_with($value,'-')) return null;
        $script=$value; $index++; break;
    }
    if (!is_string($script) || basename($script)!=='artisan') return null;
    if (($args[$index] ?? null)==='--') $index++;
    if (($args[$index] ?? null)!=='schedule:run') return null;
    foreach(array_slice($args,$index+1) as $argument) {
        if (!in_array($argument,['-v','-vv','-vvv','--verbose','--no-interaction','--quiet','-q','--no-ansi','--ansi','--env=production'],true)) return null;
    }
    return $script;
}

function hmSchedulerNextInspection(bool $baseline,bool $matched,float $tick): float
{
    return $tick+($matched ? 1.0 : ($baseline ? 181.0 : 0.25));
}
function hmSchedulerSameFile(string $left,string $right): bool
{
    $a=@stat($left); $b=@stat($right);
    return is_array($a) && is_array($b) && $a['dev']===$b['dev'] && $a['ino']===$b['ino'];
}
function hmSchedulerCapture(int $pid,int $uid,string $root,string $php,string $namespace): ?array
{
    $dir='/proc/'.$pid;
    $before=@file_get_contents($dir.'/stat',false,null,0,16385); $start=hmSchedulerStart($before,$pid);
    $cmd=@file_get_contents($dir.'/cmdline',false,null,0,65537); $artisan=hmSchedulerArtisan($cmd);
    if ($start===null || $artisan===null || !hmSchedulerUid(@file_get_contents($dir.'/status',false,null,0,16385),$uid)) return null;
    $cwd=@readlink($dir.'/cwd'); $exe=@readlink($dir.'/exe'); $ns=@readlink($dir.'/ns/pid');
    if (!is_string($cwd) || !is_string($exe) || $ns!==$namespace || realpath($exe)!==$php
        || !hmSchedulerSameFile($dir.'/exe',$php)) return null;
    $script=str_starts_with($artisan,'/') ? $dir.'/root'.$artisan : $dir.'/cwd/'.$artisan;
    $resolved=realpath(str_starts_with($artisan,'/') ? $artisan : $cwd.'/'.$artisan);
    if ($resolved!==$root.'/artisan' || !hmSchedulerSameFile($script,$root.'/artisan')) return null;
    clearstatcache();
    if (!hmSchedulerStableStart($before,@file_get_contents($dir.'/stat',false,null,0,16385),$pid)
        || @file_get_contents($dir.'/cmdline',false,null,0,65537)!==$cmd || @readlink($dir.'/exe')!==$exe
        || @readlink($dir.'/cwd')!==$cwd || @readlink($dir.'/ns/pid')!==$namespace
        || !hmSchedulerUid(@file_get_contents($dir.'/status',false,null,0,16385),$uid)) return null;
    return ['pid'=>$pid,'start'=>$start,'uid'=>$uid,'php'=>$php,'artisan'=>$root.'/artisan','cwd'=>$cwd,'namespace'=>$namespace,'observed_at'=>time()];
}
function hmSchedulerWrite(string $file,array $payload): void
{
    $temporary=$file.'.next'; $bytes=json_encode($payload,JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary,$bytes)!==strlen($bytes) || !chmod($temporary,0600) || !rename($temporary,$file)) throw new RuntimeException();
}
if (defined('HORUS_SCHEDULER_OBSERVER_TEST_ONLY') && HORUS_SCHEDULER_OBSERVER_TEST_ONLY===true) return;
try {
    [$program,$link,$expected,$file]=$argv;
    if (!preg_match('/^[a-f0-9]{40}$/D',$expected) || !preg_match('~^/[A-Za-z0-9/_.-]+$~D',$link)
        || !preg_match('~^/[A-Za-z0-9/_.-]+$~D',$file) || !function_exists('posix_geteuid')) throw new RuntimeException();
    $uid=posix_geteuid(); $root=realpath($link); $php=realpath(PHP_BINARY); $namespace=@readlink('/proc/self/ns/pid');
    $pid=getmypid(); $collectorStart=hmSchedulerStart(@file_get_contents('/proc/self/stat'),$pid);
    if (!is_string($root) || !is_string($php) || !is_string($namespace) || $collectorStart===null
        || @fileowner(dirname($file))!==$uid || (@fileperms(dirname($file))&0077)!==0) throw new RuntimeException();
    $envelope=['schema_version'=>1,'source'=>'CANONICAL_PROCESS_IDENTITY','collector_pid'=>$pid,'collector_start'=>$collectorStart,
        'uid'=>$uid,'namespace'=>$namespace,'release_sha'=>$expected,'available'=>false];
    hmSchedulerWrite($file,$envelope);
    $start=hrtime(true); $seen=[]; $known=[]; $nextDetail=[]; $baseline=[]; $identities=[]; $details=0; $captures=0; $lastWritten=0;
    for($iteration=0;$iteration<360 && (hrtime(true)-$start)<180e9 && $details<1200 && $captures<240;$iteration++) {
        clearstatcache();
        $marker=@file_get_contents($root.'/.horus-release');
        if (realpath($link)!==$root || @readlink('/proc/self/ns/pid')!==$namespace || !is_string($marker)
            || preg_match_all('/^release_id=([a-f0-9]{40})$/m',$marker,$matches)!==1 || $matches[1][0]!==$expected) throw new RuntimeException();
        $paths=glob('/proc/[0-9]*',GLOB_ONLYDIR);
        if (!is_array($paths) || count($paths)>8192) throw new RuntimeException();
        $present=[]; $tick=microtime(true);
        foreach($paths as $dir) {
            $candidate=(int)basename($dir); $present[$candidate]=true;
            if ($candidate===$pid) continue;
            if (@fileowner($dir)!==$uid) { unset($seen[$candidate],$known[$candidate],$nextDetail[$candidate],$baseline[$candidate],$identities[$candidate]); continue; }
            $identity=@fileinode($dir);
            if (!isset($seen[$candidate]) || ($identities[$candidate] ?? null)!==$identity) {
                $seen[$candidate]=$tick; $identities[$candidate]=$identity; $nextDetail[$candidate]=0; $baseline[$candidate]=$iteration===0; unset($known[$candidate]);
            }
            if (!isset($known[$candidate]) && $tick-$seen[$candidate]>2) continue;
            if (($nextDetail[$candidate] ?? 0)>$tick) continue;
            if (++$details>1200) break;
            if (hmSchedulerArtisan(@file_get_contents($dir.'/cmdline',false,null,0,65537))===null) {
                $nextDetail[$candidate]=hmSchedulerNextInspection($baseline[$candidate],false,$tick); continue;
            }
            $nextDetail[$candidate]=hmSchedulerNextInspection(false,true,$tick);
            if (++$captures>240) break;
            $witness=hmSchedulerCapture($candidate,$uid,$root,$php,$namespace);
            if ($witness!==null) {
                $known[$candidate]=true;
                if ($witness['observed_at']>$lastWritten) {
                    hmSchedulerWrite($file,array_replace($envelope,['available'=>true,'witness'=>$witness]));
                    $lastWritten=$witness['observed_at'];
                }
            }
        }
        $seen=array_intersect_key($seen,$present); $known=array_intersect_key($known,$present);
        $nextDetail=array_intersect_key($nextDetail,$present); $baseline=array_intersect_key($baseline,$present); $identities=array_intersect_key($identities,$present);
        $second=time()%60; usleep(($second>=58 || $second<=2) ? random_int(80000,120000) : random_int(900000,1100000));
    }
} catch (Throwable) {
    if (isset($file,$envelope) && is_string($file)) { try { hmSchedulerWrite($file,array_replace($envelope,['available'=>false])); } catch (Throwable) {} }
    exit(1);
}
