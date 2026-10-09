import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {safeCurrentMainRefresh} from '../../ops/audit/validate-current-main-refresh.mjs';
const path='ops/audit/refresh-current-main-reports.php';
const source=readFileSync(path,'utf8');
const php=spawnSync('php',['-v']).status===0;
function fixture(code) {
 const result=spawnSync('php',['-r',"define('HORUS_TODAY_REFRESH_TEST_ONLY',true); require '"+path+"'; "+code],{encoding:'utf8'});
 assert.equal(result.status,0,result.stderr);assert.equal(result.stdout,'PASS');assert.equal(result.stderr,'');
}
test('only current main estimated importer is invoked and shared cache is restored first',()=>{
 assert.equal((source.match(/->runConnection\(/g)||[]).length,1);
 assert.match(source,/App\\Enums\\ReportGranularity::Daily,App\\Enums\\ReportFinality::Estimated/);
 assert.match(source,/Carbon\\CarbonImmutable::now\(\$connection->timezone\)->toDateString\(\)/);
 assert.match(source,/\$connection->connection_type!=='SITE_GAM_AD_UNIT'/);
 assert.match(source,/\$connection->currency!=='USD'/);
 assert.match(source,/->assertCurrent\(\$binding,\$scope\)/);
 assert.doesNotMatch(source,/->ensure\(|->sync\(|SiteGamReportSynchronizer|SiteGamUnfilled|SiteGamVideo|sync_due|_site_lock|forceRelease|Cache::clear|Finalized/);
 assert.ok(source.indexOf("config(['cache.default'=>$default])")<source.indexOf('->runConnection('));
 assert.match(source,/instanceof Illuminate\\Cache\\DatabaseStore/);
 assert.match(source,/->status->value!=='OPEN'/);
});
test('guarded cached bootstrap is readonly and release/day/scope identities are frozen',()=>{
 for(const text of ['SET TRANSACTION READ ONLY',"config(['cache.default'=>'array'])",'getCachedPackagesPath','getCachedServicesPath',"'logging.default'=>'null'",'HM_EXPECTED_RELEASE_SHA','HM_CURRENT_MAIN_OPERATION','HM_CURRENT_MAIN_APP_LINK','assertCurrent']) assert.ok(source.includes(text),text);
 assert.match(source,/\[a-f0-9\]\{40\}/);assert.match(source,/\[a-f0-9\]\{64\}/);
 assert.match(source,/\$matches\[1\]\[0\]!==\$expected/);
 assert.ok((source.match(/hmTodayRelease\(\$link,\$root,\$expected\)/g)||[]).length>=4);
 assert.match(source,/hmTodayBinding\(\$binding->fresh\(\)\)/);
 assert.doesNotMatch(source,/HM_ALLOWED_PREVIOUS|last_finalized_import_at.*update|statements\(|payments\(/);
});
test('operation state is private token-scoped serialized and atomically replaced',()=>{
 for(const text of ["storage_path('app/private/operations')",'LOCK_EX|LOCK_NB','umask(0077)','0600','tempnam(','rename($temporary,$file)','next_attempt_at']) assert.ok(source.includes(text),text);
 assert.match(source,/\$state\['entries'\]\[\$index\]\['stage'\]\+\+/);
 assert.match(source,/max\(\$now\+60,\$retryAt \?\? 0\)/);
 assert.match(source,/hmTodayDone\(\$state\)/);
});
test('each external attempt has internal audit records without a fabricated actor or money',()=>{
 assert.match(source,/reporting.current_main_refresh.started/); assert.match(source,/reporting.current_main_refresh.finished/);
 assert.match(source,/finally \{[\s\S]*reporting.current_main_refresh.finished/);
 assert.match(source,/'operation_id'=>\$token,'stage'=>\$entry\['stage'\],'source_date'=>\$entry\['day'\]/);
 assert.doesNotMatch(source,/actor:|User::|gross_revenue_minor|publisher_earnings_minor/);
});
test('public result drops all identity counters timestamps financial values and raw errors',()=>{
 const valid={schema_version:1,operation:'CURRENT_MAIN_ESTIMATES',status:'PENDING'};
 assert.deepEqual(safeCurrentMainRefresh({...valid,id:'secret',count:3,rows:10,amount:999,cursor:1,error:'secret'}),valid);
 for(const bad of [{...valid,status:'secret'},{...valid,operation:'VIDEO'},{...valid,schema_version:2}]) assert.throws(()=>safeCurrentMainRefresh(bad));
 assert.doesNotMatch(source,/getMessage\(|var_dump\(|print_r\(|echo[^;]*(?:\$plan|\$state|\$job|\$connection|\$token)/);
});
test('PHP syntax and absent required inputs fail closed with sanitized output',{skip:!php},()=>{
 const lint=spawnSync('php',['-l',path],{encoding:'utf8'});assert.equal(lint.status,0,lint.stderr);
 const result=spawnSync('php',[path],{env:{...process.env,HM_CURRENT_MAIN_MODE:'',HM_EXPECTED_RELEASE_SHA:'',HM_CURRENT_MAIN_OPERATION:''},encoding:'utf8'});
 assert.equal(result.status,1);assert.equal(result.stderr,'');assert.equal(safeCurrentMainRefresh(JSON.parse(result.stdout)).status,'UNAVAILABLE');
});
test('default preview, frozen day/scope and resumable two-pass cursor obey retry floor',{skip:!php},()=>{
 fixture(`
 if(hmTodayMode(false)!=='preview'||hmTodayMode('')!=='preview'||hmTodayMode('apply')!=='apply')exit(2);
 try{hmTodayMode('unsafe');exit(3);}catch(RuntimeException){}
 $a=['binding'=>'a','connection'=>'ca','day'=>'2026-10-09','scope'=>'scope-a','identity'=>'identity-a'];
 $b=['binding'=>'b','connection'=>'cb','day'=>'2026-10-09','scope'=>'scope-b','identity'=>'identity-b'];
 $state=hmTodayState([$a,$b],null,100);
 $proof1=['identity'=>str_repeat('a',64),'completed_at'=>50,'row_count'=>1,'fact_present'=>true];
 $proof2=['identity'=>str_repeat('b',64),'completed_at'=>160,'row_count'=>1,'fact_present'=>true];if(hmTodayNext($state,100)!==0||hmTodayDone($state))exit(4);
 $state=hmTodayAdvance($state,0,'PENDING',100);if($state['entries'][0]['stage']!==0||$state['entries'][0]['next_attempt_at']!==160||hmTodayNext($state,100)!==1)exit(5);
 $state=hmTodayAdvance($state,1,'COMPLETED',100,null,$proof1);if($state['entries'][1]['stage']!==1||hmTodayNext($state,159)!==null)exit(6);
 $state=hmTodayAdvance($state,0,'COMPLETED',160,null,$proof1);$state=hmTodayAdvance($state,1,'COMPLETED',160,null,$proof2);
 if(hmTodayDone($state)||hmTodayNext($state,219)!==null||hmTodayNext($state,220)!==0)exit(7);
 $state=hmTodayAdvance($state,0,'COMPLETED',220,null,$proof2);if(!hmTodayDone($state)||hmTodayNext($state,1000)!==null)exit(8);
 if(hmTodayState([$a,$b],$state)!==$state)exit(9);
 foreach(['day','scope','identity','connection','binding'] as $field){$changed=$a;$changed[$field]='changed';try{hmTodayState([$changed,$b],$state);exit(10);}catch(RuntimeException){}}
 try{hmTodayAdvance($state,0,'COMPLETED',300);exit(11);}catch(RuntimeException){}
 $new=hmTodayState([$a],null,100);$new=hmTodayAdvance($new,0,'FAILED',100,500);if($new['entries'][0]['stage']!==0||hmTodayNext($new,499)!==null)exit(12);
 echo 'PASS';`);
});

test('completion requires distinct import provenance and one existing scoped estimate',()=>{
 for(const text of ['operation_started_at','first_completed_identity',"hash('sha256',$job->external_report_id)","'row_count'=>(int)$job->row_count",'->exists()',"->where('report_source_connection_id',$entry['connection'])","->whereDate('report_date',$entry['day'])","->where('site_id',$binding->site_id)","->where('external_dimensions->gam_report_scope',$entry['scope'])"]) assert.ok(source.includes(text),text);
 assert.doesNotMatch(source,/->(?:get|first|value)\([^;]*(?:gross_revenue|publisher_earnings)/);
});
test('same snapshot, old second completion, zero rows and missing facts cannot complete',{skip:!php},()=>{
 fixture(`
 $first=['stage'=>0,'first_completed_identity'=>null];
 $old=['identity'=>str_repeat('a',64),'completed_at'=>50,'row_count'=>1,'fact_present'=>true];
 if(!hmTodayCompletionAllowed($first,$old,100))exit(2);
 $second=['stage'=>1,'first_completed_identity'=>$old['identity']];
 $fresh=['identity'=>str_repeat('b',64),'completed_at'=>100,'row_count'=>1,'fact_present'=>true];
 if(!hmTodayCompletionAllowed($second,$fresh,100))exit(3);
 foreach([array_replace($fresh,['identity'=>$old['identity']]),array_replace($fresh,['completed_at'=>99]),array_replace($fresh,['row_count'=>0]),array_replace($fresh,['row_count'=>2]),array_replace($fresh,['fact_present'=>false]),array_replace($fresh,['completed_at'=>null])] as $bad){if(hmTodayCompletionAllowed($second,$bad,100))exit(4);}
 foreach([array_replace($old,['row_count'=>0]),array_replace($old,['fact_present'=>false])] as $bad){if(hmTodayCompletionAllowed($first,$bad,100))exit(5);}
 echo 'PASS';`);
});
