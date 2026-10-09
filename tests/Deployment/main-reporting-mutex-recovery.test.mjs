import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {safeMainReportingRecovery} from '../../ops/audit/validate-main-reporting-recovery.mjs';
const path='ops/audit/recover-main-reporting-mutex.php';
const script=readFileSync(path,'utf8');
const php=spawnSync('php',['-v']).status===0;
function fixture(program,input={}) {
 const code="define('HORUS_MAIN_RECOVERY_TEST_ONLY',true); require '"+path+"'; "+program;
 const result=spawnSync('php',['-r',code],{input:JSON.stringify(input),encoding:'utf8'});
 assert.equal(result.status,0,result.stderr); assert.equal(result.stdout,'PASS'); assert.equal(result.stderr,'');
}
test('recovery defaults preview and requires one explicit exact release without fallback aliases',()=>{
 assert.match(script,/getenv\('HM_MAIN_RECOVERY_MODE'\) \?: 'preview'/);
 assert.match(script,/getenv\('HM_EXPECTED_RELEASE_SHA'\)/);
 assert.match(script,/\[a-f0-9\]\{40\}/);
 assert.match(script,/\$matches\[1\]\[0\]!==\$expected/);
 assert.doesNotMatch(script,/HM_ALLOWED_PREVIOUS_SHA|5c3861f8|554be0bf|3cd93b84/);
 assert.equal((script.match(/hmMainRecoveryEnvironment\(\$link,\$root,\$expected/g)||[]).length,3);
});
test('cached bootstrap remains read-only and local before narrowly selected same-DB mutex recovery',()=>{
 for (const text of ['SET TRANSACTION READ ONLY','beforeBootstrapping','afterBootstrapping',"'logging.default'=>'null'", "config(['cache.default'=>'array'])", 'getCachedPackagesPath','getCachedServicesPath',"$cacheDb!==$db",'$pdo=$db->getPdo()']) assert.ok(script.includes(text),text);
 assert.ok(script.indexOf('beforeBootstrapping')<script.indexOf('->bootstrap()'));
 assert.match(script,/in_array\(\$event->expiresAt,\[1440,10\],true\)/);
 assert.match(script,/\$event->expression!=='.\/5 \* \* \* \*'/);
 assert.match(script,/\$event->runInBackground \|\| \$event->onOneServer/);
 assert.doesNotMatch(script,/forceRelease|->forget\(|Artisan::call|Cache::|schedule:clear-cache|cache:clear|GamAdUnitReportClient|table\(['"](?:sites|daily_reports|report_import_jobs|report_dimensions)/);
});
test('only write is exact main key-owner-expiration CAS with active-old-lease predicates',()=>{
 assert.equal((script.match(/DELETE FROM/g)||[]).length,1);
 assert.match(script,/WHERE `key` = \? AND HEX\(`key`\) = HEX\(\?\) AND HEX\(`owner`\) = HEX\(\?\) AND `expiration` = \? AND `expiration` > \? AND `expiration` < \?/);
 assert.match(script,/\[\$expected\['key'\],\$expected\['key'\],\$expected\['owner'\],\$expected\['expiration'\],\$now\+900,\$now\+64800\]/);
 assert.match(script,/sleep\(65\)/);
 assert.match(script,/\$mode==='preview'[\s\S]*else \{[\s\S]*hmMainRecoveryProcessAbsent\([\s\S]*hmMainRecoveryDelete\(/);
 assert.match(script,/\$changed!==1/);
 assert.equal((script.match(/hmMainRecoveryDelete\(/g)||[]).length,2,'Only one explicitly gated apply call is allowed');
 assert.doesNotMatch(script,/HM_.*(?:ATTEST|VERIFIED_SINGLE|SINGLE_HOST_PROOF)/);
 assert.doesNotMatch(script,/(?:INSERT|UPDATE|TRUNCATE)\s+(?:INTO|TABLE|`)|getMessage\(|print_r\(|var_dump\(/i);
});
test('public projection reconstructs allowlisted classes and discards private fields',()=>{
 const raw={schema_version:1,target:'MAIN_SYNC_SCHEDULER_MUTEX',mode:'preview',status:'ELIGIBLE',changed_rows:0,stage:'COMPLETE',reason:'NONE'};
 assert.deepEqual(safeMainReportingRecovery({...raw,owner:'secret',key:'secret',expiration:123,financial_data:'secret'}),raw);
 for (const invalid of [{...raw,status:'RELEASED'}, {...raw,changed_rows:1}, {...raw,status:'secret'}, {...raw,mode:'secret'}, {...raw,target:'VIDEO_SYNC'}, {...raw,mode:'apply'}]) assert.throws(()=>safeMainReportingRecovery(invalid));
 assert.deepEqual(safeMainReportingRecovery({...raw,mode:'apply',status:'RELEASED',changed_rows:1}),{...raw,mode:'apply',status:'RELEASED',changed_rows:1});
});
test('PHP syntax and unset-release failure are closed and sanitized',{skip:!php},()=>{
 const lint=spawnSync('php',['-l',path],{encoding:'utf8'}); assert.equal(lint.status,0,lint.stderr);
 const result=spawnSync('php',[path],{env:{...process.env,HM_EXPECTED_RELEASE_SHA:'',HM_MAIN_RECOVERY_MODE:'preview'},encoding:'utf8'});
 assert.equal(result.status,1); assert.equal(result.stderr,'');
 assert.equal(safeMainReportingRecovery(JSON.parse(result.stdout)).status,'UNAVAILABLE');
});
test('old lease guards reject absent expired recent changed and clock-invalid observations',{skip:!php},()=>{
 fixture(`
 $now=1800000000; $lease=['key'=>'fixture-main','owner'=>'fixture-owner','expiration'=>$now+3600];
 if(!hmMainRecoveryLeaseEligible($lease,$now))exit(2);
 foreach([null,[],array_replace($lease,['owner'=>'']),array_replace($lease,['expiration'=>$now]),array_replace($lease,['expiration'=>$now+600]),array_replace($lease,['expiration'=>$now+900]),array_replace($lease,['expiration'=>$now+64800]),array_replace($lease,['expiration'=>$now+86400]),array_replace($lease,['expiration'=>'invalid'])] as $bad){if(hmMainRecoveryLeaseEligible($bad,$now))exit(3);}
 $first=['now'=>$now,'lease'=>$lease,'heartbeat'=>$now-20,'process'=>'ABSENT'];
 $second=['now'=>$now+65,'lease'=>$lease,'heartbeat'=>$now+40,'process'=>'ABSENT'];
 if(!hmMainRecoveryEligible($first,$second,65))exit(4);
 foreach([['owner'=>'replacement'],['expiration'=>$now+3601],['key'=>'fixture-video']] as $change){$bad=$second;$bad['lease']=array_replace($lease,$change);if(hmMainRecoveryEligible($first,$bad,65))exit(5);}
 foreach([array_replace($second,['lease'=>null]),array_replace($second,['heartbeat'=>$first['heartbeat']]),array_replace($second,['heartbeat'=>$now+66]),array_replace($second,['process'=>'PRESENT']),array_replace($second,['process'=>'UNAVAILABLE']),array_replace($second,['now'=>$now-1])] as $bad){if(hmMainRecoveryEligible($first,$bad,65))exit(6);}
 foreach([0,64,181,3600] as $elapsed){if(hmMainRecoveryEligible($first,$second,$elapsed))exit(7);}
 $first['process']='PRESENT';if(hmMainRecoveryEligible($first,$second,65))exit(8);
 echo 'PASS';`);
});
test('process evidence handles wrapper argv and fails closed on unknown live processes',{skip:!php},()=>{
 fixture(String.raw`
 $live="State:\tS (sleeping)\n";
 foreach(["php\0/app/artisan\0reporting:sync-site-gam\0","php\0artisan\0--env=production\0reporting:sync-site-gam\0","sh\0-c\0php artisan reporting:sync-site-gam > /dev/null\0"] as $cmd){if(!hmMainRecoveryProcess($live,$cmd,true))exit(2);}
 foreach(["php\0artisan\0reporting:sync-site-gam-video\0","php\0artisan\0list\0"] as $cmd){if(hmMainRecoveryProcess($live,$cmd,true))exit(3);}
 foreach([[false,false,true],['invalid','php'.chr(0),true],[$live,false,true],[$live,'',true],[$live,'truncated',true]] as $bad){try{hmMainRecoveryProcess(...$bad);exit(4);}catch(RuntimeException){}}
 if(hmMainRecoveryProcess(false,false,false)||hmMainRecoveryProcess("State:\tZ (zombie)\n",false,true)||hmMainRecoveryProcess($live."Kthread:\t1\n",'',true))exit(5);
 echo 'PASS';`);
});
test('visibility and cron guards reject hidepid duplicate cron and altered target syntax',{skip:!php},()=>{
 fixture(`
 $mount='24 1 0:4 / /proc rw,nosuid,nodev,noexec,relatime - proc proc rw';
 if(!hmMainRecoveryProcVisibility($mount)||!hmMainRecoveryProcVisibility($mount.',hidepid=0'))exit(2);
 foreach([false,'',$mount.',hidepid=2',$mount.',hidepid=invisible',$mount."\n".$mount,str_replace(' - proc ',' - tmpfs ',$mount)] as $bad){if(hmMainRecoveryProcVisibility($bad))exit(3);}
 $cron='* * * * * /usr/bin/php8.4 /home/fixture/current/artisan schedule:run >> /home/fixture/scheduler.log 2>&1';
 if(hmMainRecoveryCronTarget($cron)!==['/usr/bin/php8.4','/home/fixture/current/artisan'])exit(4);
 $cd='* * * * * cd /home/fixture/current && /usr/bin/php8.4 artisan schedule:run >> /home/fixture/scheduler.log 2>&1';
 if(hmMainRecoveryCronTarget($cd)!==['/usr/bin/php8.4','/home/fixture/current/artisan'])exit(6);
 foreach([null,false,'',$cron."\n".$cron,str_replace('* * * * *','*/5 * * * *',$cron),$cron.'; echo unexpected'] as $bad){if(hmMainRecoveryCronTarget($bad)!==null)exit(5);}
 echo 'PASS';`);
});
test('CAS targets only observed main row and preserves replacement and video leases',{skip:!php},()=>{
 fixture(`
 if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){echo 'PASS';return;}
 $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $pdo->exec('CREATE TABLE cache_locks (key TEXT COLLATE NOCASE PRIMARY KEY, owner TEXT COLLATE NOCASE, expiration INTEGER)');
 $insert=$pdo->prepare('INSERT INTO cache_locks VALUES (?, ?, ?)');$now=1800000000;
 $main=['key'=>'fixture-main','owner'=>'fixture-owner','expiration'=>$now+3600];
 $insert->execute(array_values($main));$insert->execute(['fixture-video','video-owner',$now+3600]);
 if(hmMainRecoveryDelete($pdo,'cache_locks',array_replace($main,['owner'=>'FIXTURE-OWNER']),$now)!==0)exit(8);
 if(hmMainRecoveryDelete($pdo,'cache_locks',array_replace($main,['key'=>'FIXTURE-MAIN']),$now)!==0)exit(9);
 $replacement=array_replace($main,['owner'=>'different-owner']);
 if(hmMainRecoveryDelete($pdo,'cache_locks',$replacement,$now)!==0)exit(2);
 if(hmMainRecoveryDelete($pdo,'cache_locks',array_replace($main,['expiration'=>$now+3601]),$now)!==0)exit(3);
 if(hmMainRecoveryDelete($pdo,'cache_locks',$main,$now)!==1)exit(4);
 if(hmMainRecoveryDelete($pdo,'cache_locks',$main,$now)!==0)exit(5);
 if($pdo->query('SELECT key FROM cache_locks')->fetchColumn()!=='fixture-video')exit(6);
 $insert->execute(['fixture-main','new-owner',$now+86400]);
 if(hmMainRecoveryDelete($pdo,'cache_locks',$main,$now)!==0)exit(7);
 echo 'PASS';`);
});

test('full host evidence and boot identity are required, not just visible namespace absence',()=>{
 for(const text of ['/proc/self/ns/pid','/proc/1/ns/pid','/proc/1/comm','/proc/sys/kernel/random/boot_id','/.dockerenv','/run/.containerenv','hmMainRecoveryHostIdentity()']) assert.ok(script.includes(text),text);
 assert.ok((script.match(/hash_equals\(\$hostIdentity,hmMainRecoveryHostIdentity\(\)\)/g)||[]).length>=2);
});
test('host fixture rejects unreadable namespaces containers nested pid lists and wrong init',{skip:!php},()=>{
 fixture(`
 $ns='pid:[4026531836]';$boot='12345678-1234-1234-1234-123456789abc';$self="NSpid:\t123\n";$init="NSpid:\t1\n";
 if(!hmMainRecoveryHostEvidence($ns,$ns,'systemd',$self,$init,$boot))exit(2);
 foreach([[false,$ns,'systemd',$self,$init,$boot],[$ns,'pid:[2]','systemd',$self,$init,$boot],[$ns,$ns,'container-init',$self,$init,$boot],[$ns,$ns,'systemd',"NSpid:\t900 1\n",$init,$boot],[$ns,$ns,'systemd',$self,"NSpid:\t2\n",$boot],[$ns,$ns,'systemd',$self,$init,'bad']] as $bad){if(hmMainRecoveryHostEvidence(...$bad))exit(3);}
 echo 'PASS';`);
});

test('cron proof is fresh private same-account shell evidence without PHP command execution',()=>{
 assert.match(script,/HM_RECOVERY_CRON_EVIDENCE/);
 assert.match(script,/hmMainRecoveryFreshCron\(\)/);
 assert.doesNotMatch(script,/shell_exec|proc_open|popen|sudo/);
 for(const text of ['fileowner($file)','fileowner(dirname($file))','fileperms($file)','fileperms(dirname($file))']) assert.ok(script.includes(text),text);
});
test('cron evidence rejects stale unavailable wrong-account malformed and future captures',{skip:!php},()=>{
 fixture(`
 $cron='* * * * * /usr/bin/php8.4 /home/fixture/current/artisan schedule:run';
 $good=['schema_version'=>1,'available'=>true,'uid'=>1000,'observed_at'=>100,'cron_base64'=>base64_encode($cron)];
 if(hmMainRecoveryCronEvidencePayload($good,105,1000)!==['/usr/bin/php8.4','/home/fixture/current/artisan'])exit(2);
 foreach([['observed_at'=>99],['observed_at'=>106],['uid'=>0],['available'=>false],['schema_version'=>2],['cron_base64'=>'invalid!']] as $change){if(hmMainRecoveryCronEvidencePayload(array_replace($good,$change),105,1000)!==null)exit(3);}
 echo 'PASS';`);
});

test('failure stages are closed enums and unknown or injected stage text is never exposed',()=>{
 const raw={schema_version:1,target:'MAIN_SYNC_SCHEDULER_MUTEX',mode:'preview',status:'UNAVAILABLE',changed_rows:0,stage:'HOST_NAMESPACE',reason:'NONE'};
 assert.deepEqual(safeMainReportingRecovery({...raw,path:'private',error:'private'}),raw);
 for(const stage of [undefined,'/private/path','HOST_NAMESPACE\nsecret','UNKNOWN']) assert.throws(()=>safeMainReportingRecovery({...raw,stage}));
 for(const stage of ['ENVIRONMENT_IDENTITY','RELEASE_MARKER','CRON_EVIDENCE','CRON_TARGET','BOOTSTRAP','DATABASE','SCHEDULE','HOST_NAMESPACE','PROC_SCAN','READ_LEASE']) assert.ok(script.includes("hmMainRecoveryStage('"+stage+"')"),stage);
});

test('cron evidence detail is a closed reason without private values',()=>{
 const raw={schema_version:1,target:'MAIN_SYNC_SCHEDULER_MUTEX',mode:'preview',status:'UNAVAILABLE',changed_rows:0,stage:'CRON_EVIDENCE',reason:'CRON_CAPTURE_UNAVAILABLE'};
 assert.deepEqual(safeMainReportingRecovery({...raw,file:'/private',uid:123}),raw);
 for(const reason of [undefined,'/private','CRON_CAPTURE_UNAVAILABLE\nsecret']) assert.throws(()=>safeMainReportingRecovery({...raw,reason}));
});
test('unavailable static cron capture produces its exact safe reason',{skip:!php},()=>{
 fixture(`
 hmMainRecoveryReason('NONE');
 if(hmMainRecoveryCronEvidencePayload(['schema_version'=>1,'available'=>false,'uid'=>1000,'observed_at'=>100],100,1000)!==null)exit(2);
 if($GLOBALS['hm_main_recovery_reason']!=='CRON_CAPTURE_UNAVAILABLE')exit(3);
 hmMainRecoveryCronEvidencePayload(['schema_version'=>1,'available'=>false,'uid'=>1000,'observed_at'=>90],100,1000);
 if($GLOBALS['hm_main_recovery_reason']!=='CRON_CAPTURE_STALE')exit(4);
 foreach([['available'=>'false'],['available'=>null],['uid'=>0]] as $change){hmMainRecoveryCronEvidencePayload(array_replace(['schema_version'=>1,'available'=>false,'uid'=>1000,'observed_at'=>100],$change),100,1000);if($GLOBALS['hm_main_recovery_reason']==='CRON_CAPTURE_UNAVAILABLE')exit(5);}
 echo 'PASS';`);
});

test('positive process proof cannot override bad static evidence or weaken legacy lease guards',()=>{
 assert.match(script,/reason'\] \?\? ''\)==='CRON_CAPTURE_UNAVAILABLE'\) return hmMainRecoveryNaturalTarget/);
 assert.match(script,/min\(time\(\)\+90,\$deadline\)/);
 assert.match(script,/hmMainRecoveryOldScheduler\(/);
 assert.match(script,/\$legacyAcquiredAt/);
});
test('positive canonical witness denies wrong identity or stale observation and old previous-release parents block',{skip:!php},()=>{
 fixture(String.raw`
 $w=['pid'=>55,'start'=>'123','uid'=>1000,'php'=>'/php','artisan'=>'/app/artisan','cwd'=>'/home','namespace'=>'pid:[1]','observed_at'=>100];
 $e=['schema_version'=>1,'source'=>'CANONICAL_PROCESS_IDENTITY','available'=>true,'collector_pid'=>50,'collector_start'=>'111','uid'=>1000,'namespace'=>'pid:[1]','release_sha'=>str_repeat('a',40),'witness'=>$w];
 if(hmMainRecoveryNaturalPayload($e,105,1000,'/php','/app/artisan','pid:[1]',str_repeat('a',40),50)!==['/php','/app/artisan'])exit(2);
 foreach([['uid'=>0],['php'=>'/other'],['artisan'=>'/other/artisan'],['namespace'=>'pid:[2]'],['observed_at'=>99]] as $change){$bad=$e;$bad['witness']=array_replace($w,$change);if(hmMainRecoveryNaturalPayload($bad,105,1000,'/php','/app/artisan','pid:[1]',str_repeat('a',40),50)!==null)exit(3);}
 $fields=array_fill(0,20,'0');$fields[0]='T';$fields[19]='10000';$stat='44 (php8.4) '.implode(' ',$fields);
 $status="State:\tT (stopped)\nUid:\t1000\t1000\t1000\t1000\n";
 $cmd="/usr/bin/php8.4\0/home/old-release/artisan\0schedule:run\0";
 if(!hmMainRecoveryOldScheduler($status,$cmd,$stat,44,1000,1000,100,1200))exit(4);
 if(hmMainRecoveryOldScheduler($status,$cmd,$stat,44,1000,1000,100,1000))exit(5);
 if(hmMainRecoveryOldScheduler($status,$cmd,$stat,44,1001,1000,100,1200))exit(6);
 echo 'PASS';`);
});

test('PID1-link unavailable alternative requires exact Pid/NSpid plus positive witness; readable mismatch remains fatal',{skip:!php},()=>{
 fixture(String.raw`
 $ns='pid:[1]';$boot='12345678-1234-1234-1234-123456789abc';$self="Pid:\t123\nNSpid:\t123\n";$init="Pid:\t1\nNSpid:\t1\n";
 if(!hmMainRecoveryHostEvidence($ns,false,'systemd',$self,$init,$boot,123,true))exit(2);
 if(hmMainRecoveryHostEvidence($ns,false,'systemd',$self,$init,$boot,123,false))exit(3);
 if(hmMainRecoveryHostEvidence($ns,'pid:[2]','systemd',$self,$init,$boot,123,true))exit(4);
 foreach([[$self."Pid:\t123\n",$init],[$self."NSpid:\t123\n",$init],["Pid:\t122\nNSpid:\t123\n",$init],[$self,"Pid:\t2\nNSpid:\t1\n"],[$self,"Pid:\t1\nNSpid:\t1 2\n"]] as $bad){if(hmMainRecoveryHostEvidence($ns,false,'systemd',$bad[0],$bad[1],$boot,123,true))exit(5);}
 $mount='24 1 0:4 / /proc rw,nosuid,nodev,noexec,relatime - proc proc rw';
 if(hmMainRecoveryMountIdentity($mount)===null||hmMainRecoveryMountIdentity($mount)===hmMainRecoveryMountIdentity(str_replace('24 1','25 1',$mount)))exit(6);
 if(hmMainRecoveryMountIdentity($mount.',hidepid=2')!==null)exit(7);
 echo 'PASS';`);
});
test('host fallback is conditional on unavailable link and still requires canonical evidence and stable proc mount',()=>{
 assert.match(script,/if \(\$snapshot\['init_namespace'\]===false\)/);
 assert.match(script,/hmMainRecoveryNaturalTarget\(\)!==null/);
 assert.match(script,/if \(\$initNamespace!==false\) return \$initNamespace===\$selfNamespace/);
 assert.match(script,/\$snapshot\['mount'\]/);
 assert.doesNotMatch(script,/permission.denied/i);
});

test('fallback rechecks all host evidence after a witness wait and rejects newly readable mismatch',{skip:!php},()=>{
 fixture(String.raw`
 $before=['self_namespace'=>'pid:[1]','init_namespace'=>false,'boot'=>'12345678-1234-1234-1234-123456789abc','init_name'=>'systemd','self_status'=>"Pid:\t123\nNSpid:\t123\n",'init_status'=>"Pid:\t1\nNSpid:\t1\n",'mount'=>hash('sha256','mount'),'container'=>false];
 if(!hmMainRecoveryHostAfterWait($before,$before,123))exit(2);
 if(!hmMainRecoveryHostAfterWait($before,array_replace($before,['init_namespace'=>'pid:[1]']),123))exit(3);
 foreach([['init_namespace'=>'pid:[2]'],['self_namespace'=>'pid:[2]'],['boot'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'],['mount'=>hash('sha256','other')],['self_status'=>"Pid:\t124\nNSpid:\t123\n"],['init_status'=>"Pid:\t2\nNSpid:\t1\n"],['container'=>true]] as $change){if(hmMainRecoveryHostAfterWait($before,array_replace($before,$change),123))exit(4);}
 echo 'PASS';`);
});
