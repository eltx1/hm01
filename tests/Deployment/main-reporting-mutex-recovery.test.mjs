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
 assert.match(script,/WHERE `key` = \? AND `owner` = \? AND `expiration` = \? AND `expiration` > \? AND `expiration` < \?/);
 assert.match(script,/\[\$expected\['key'\],\$expected\['owner'\],\$expected\['expiration'\],\$now\+900,\$now\+64800\]/);
 assert.match(script,/sleep\(65\)/);
 assert.match(script,/\$mode==='preview'[\s\S]*else \{[\s\S]*hmMainRecoveryProcessAbsent\(\)[\s\S]*hmMainRecoveryDelete\(/);
 assert.match(script,/\$changed!==1/);
 assert.equal((script.match(/hmMainRecoveryDelete\(/g)||[]).length,2,'Only one explicitly gated apply call is allowed');
 assert.doesNotMatch(script,/HM_.*(?:ATTEST|VERIFIED_SINGLE|SINGLE_HOST_PROOF)/);
 assert.doesNotMatch(script,/(?:INSERT|UPDATE|TRUNCATE)\s+(?:INTO|TABLE|`)|getMessage\(|print_r\(|var_dump\(/i);
});
test('public projection reconstructs allowlisted classes and discards private fields',()=>{
 const raw={schema_version:1,target:'MAIN_SYNC_SCHEDULER_MUTEX',mode:'preview',status:'ELIGIBLE',changed_rows:0};
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
 fixture(`
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
 $pdo->exec('CREATE TABLE cache_locks (key TEXT PRIMARY KEY, owner TEXT, expiration INTEGER)');
 $insert=$pdo->prepare('INSERT INTO cache_locks VALUES (?, ?, ?)');$now=1800000000;
 $main=['key'=>'fixture-main','owner'=>'fixture-owner','expiration'=>$now+3600];
 $insert->execute(array_values($main));$insert->execute(['fixture-video','video-owner',$now+3600]);
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
