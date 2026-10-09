import {readFileSync, mkdtempSync, writeFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';
import {test} from 'node:test';
import assert from 'node:assert/strict';
const source=readFileSync(new URL('../../.github/workflows/diagnose-current-reporting.yml',import.meta.url),'utf8');
const php=spawnSync('php',['-v'],{encoding:'utf8'}).status===0;
function processProbe() {
 const code=source.match(/          <\?php\n([\s\S]*?)\n          PHP\n/);
 assert.ok(code);
 return '<?php\n'+code[1].replace(/^          /gm,'');
}
function projectionValidator() {
 const code=source.match(/          node - "\$work\/process.txt" <<'JS'\n([\s\S]*?)\n          JS/);
 assert.ok(code);
 return code[1].replace(/^          /gm,'');
}
test('current diagnostic keeps production on protected main and exact deployed release',()=>{
 assert.match(source,/branches: \[main\]/);
 assert.match(source,/if: github.event_name == 'push' && github.repository == 'eltx1\/hm01' && github.ref == 'refs\/heads\/main'/);
 assert.match(source,/needs: validate/);
 assert.match(source,/environment: production/);
 assert.match(source,/ref: 5c3861f8ffdb97cec42c35476894e22b845beb3b/);
 assert.match(source,/grep -Fxq 'release_id=\$EXPECTED_SHA' .horus-release/);
 assert.doesNotMatch(source,/StrictHostKeyChecking=no|ssh-keyscan|schedule:clear-cache|cache:clear/);
});
test('diagnostic only returns allowlisted scheduler and scoped process classes',()=>{
 assert.match(source,/node ops\/audit\/validate-scheduler-incident.mjs/);
 assert.match(source,/PROCESS_CHECK_SCOPE=VISIBLE_PROC_NAMESPACE/);
 assert.match(source,/Raw output withheld/);
 assert.match(source,/unset SSH_KEY KNOWN_HOSTS/);
 assert.match(source,/count\(\$paths\)>8192/);
 assert.match(source,/16385/);
 assert.match(source,/65537/);
 assert.doesNotMatch(source,/posix_geteuid|Uid:|echo.*\$SSH_KEY|cat.*result.json|DB::|artisan.*reporting:sync/);
 assert.doesNotMatch(processProbe(),/echo[^;]*(?:\$status|\$cmd|\$args|\$path|\$directory)|getMessage\(/);
});
test('projection validation rejects missing, extra, duplicate or injected lines',()=>{
 const directory=mkdtempSync(join(tmpdir(),'horus-process-projection-'));
 try {
  const path=join(directory,'projection.txt');
  const valid='PROCESS_CHECK_SCOPE=VISIBLE_PROC_NAMESPACE\nMAIN_SYNC_PROCESS=ABSENT\nVIDEO_SYNC_PROCESS=PRESENT\n';
  for (const [output,accepted] of [[valid,true],[valid.replace('MAIN_SYNC_PROCESS=ABSENT\n',''),false],
   [valid+'PRIVATE=secret\n',false],[valid+'MAIN_SYNC_PROCESS=PRESENT\n',false],
   [valid.replace('ABSENT','secret'),false],[valid.replace('VISIBLE_PROC_NAMESPACE','ALL_HOSTS'),false]]) {
   writeFileSync(path,output);
   const result=spawnSync(process.execPath,['-',path],{input:projectionValidator(),encoding:'utf8'});
   assert.equal(result.status,accepted?0:1,result.stderr);
   assert.equal(result.stdout,accepted?valid:'');
   assert.doesNotMatch(result.stderr,/secret|PRIVATE/);
  }
 } finally { rmSync(directory,{recursive:true,force:true}); }
});
test('process probe is syntactically valid and fails closed outside exact release',{skip:!php},()=>{
 const script=processProbe();
 const lint=spawnSync('php',['-l'],{input:script,encoding:'utf8'});
 assert.equal(lint.status,0,lint.stderr);
 const result=spawnSync('php',[],{input:script,encoding:'utf8'});
 assert.equal(result.status,1);
 assert.equal(result.stdout,'PROCESS_CHECK_UNAVAILABLE\n');
 assert.equal(result.stderr,'');
});
test('process classifier detects both commands across users and leading global options',{skip:!php},()=>{
 const program=processProbe().replace('if (defined(\'HORUS_REPORT_PROCESS_PROBE_TEST_ONLY\')',
  "define('HORUS_REPORT_PROCESS_PROBE_TEST_ONLY',true); if (defined('HORUS_REPORT_PROCESS_PROBE_TEST_ONLY')");
 const cases=[
  ['State:\tS (sleeping)\nUid:\t1000\t1000\t1000\t1000\n','php\0/app/artisan\0reporting:sync-site-gam\0',true,['MAIN_SYNC_PROCESS']],
  ['State:\tS (sleeping)\nUid:\t0\t1000\t0\t0\n','php\0artisan\0--env=production\0reporting:sync-site-gam\0',true,['MAIN_SYNC_PROCESS']],
  ['State:\tS (sleeping)\nUid:\t9876\t9876\t9876\t9876\n','php\0artisan\0reporting:sync-site-gam-video\0',true,['VIDEO_SYNC_PROCESS']],
  ['State:\tS (sleeping)\n','php\0other.php\0reporting:sync-site-gam\0',true,[]],
  ['State:\tS (sleeping)\n','php\0artisan\0reporting:sync-site-gam-video-extra\0',true,[]],
  ['State:\tS (sleeping)\n','php\0artisan\0list\0',true,[]],
  ['State:\tS (sleeping)\n',"sh\0-c\0'/usr/bin/php8.4' 'artisan' --env=production reporting:sync-site-gam > /dev/null 2>&1\0",true,['MAIN_SYNC_PROCESS']],
  ['State:\tZ (zombie)\n',false,true,[]],
  ['State:\tS (sleeping)\nKthread:\t1\n','',true,[]],
  [false,false,false,[]],
 ];
 const errors=[
  [false,'php\0artisan\0reporting:sync-site-gam\0',true],
  ['garbled status','php\0artisan\0list\0',true],
  ['State:\tS (sleeping)\n',false,true],
  ['State:\tS (sleeping)\n','',true],
  ['State:\tS (sleeping)\n','php\0artisan\0list',true],
  ['State:\tS (sleeping)\n','x'.repeat(65537),true],
  ['State:\tS (sleeping)\n'+'x'.repeat(16385),'php\0artisan\0list\0',true],
 ];
 const directory=mkdtempSync(join(tmpdir(),'horus-process-classifier-'));
 try {
  const path=join(directory,'probe.php'); writeFileSync(path,program);
  const runner="require $argv[1]; $data=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); foreach($data['cases'] as $case){$expected=array_pop($case); if(hmClassifyReportProcess(...$case)!==$expected) exit(2);} foreach($data['errors'] as $case){try{hmClassifyReportProcess(...$case);exit(3);}catch(RuntimeException){}} echo 'PASS';";
  const result=spawnSync('php',['-r',runner,path],{input:JSON.stringify({cases,errors}),encoding:'utf8'});
  assert.equal(result.status,0,result.stderr); assert.equal(result.stdout,'PASS'); assert.equal(result.stderr,'');
 } finally { rmSync(directory,{recursive:true,force:true}); }
});
