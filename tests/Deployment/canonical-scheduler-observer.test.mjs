import {readFileSync,mkdtempSync,writeFileSync,existsSync,rmSync} from 'node:fs';
import {spawn,spawnSync} from 'node:child_process';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import test from 'node:test';
import assert from 'node:assert/strict';
const path='ops/audit/observe-canonical-scheduler.php';const source=readFileSync(path,'utf8');
const php=spawnSync('php',['-v']).status===0;
function run(code,args=[],options={}) {
 return spawnSync('php',['-r',"define('HORUS_SCHEDULER_OBSERVER_TEST_ONLY',true);require "+JSON.stringify(resolve(path))+'; '+code,...args],{encoding:'utf8',...options});
}
test('observer is bounded read-only discovery with no scheduler launch and no unrelated argv persistence',()=>{
 assert.match(source,/180e9/);assert.match(source,/iteration<360/);assert.match(source,/details<1200/);assert.match(source,/captures<240/);
 assert.ok(source.includes('$second>=58 || $second<=2'));
 assert.match(source,/random_int\(80000,120000\)/);assert.match(source,/random_int\(900000,1100000\)/);
 assert.match(source,/fileowner\(\$dir\)!==\$uid/);assert.match(source,/\$tick-\$seen\[\$candidate\]>2/);
 assert.doesNotMatch(source,/Artisan::call|shell_exec|proc_open|popen|system\(|exec\(|'argv'\s*=>|echo |print_r|var_dump/);
});
test('PHP observer syntax is valid',{skip:!php},()=>{
 const result=spawnSync('php',['-l',path],{encoding:'utf8'});assert.equal(result.status,0,result.stderr);
});
test('starttime identity rejects PID reuse and parser rejects wrong commands',{skip:!php},()=>{
 const result=run(String.raw`
 $fields=array_fill(0,20,'0');$fields[0]='S';$fields[19]='123';$before='42 (php8.4) '.implode(' ',$fields);
 $fields[19]='124';$after='42 (php8.4) '.implode(' ',$fields);
 if(!hmSchedulerStableStart($before,$before,42)||hmSchedulerStableStart($before,$after,42)||hmSchedulerStableStart($before,$before,43))exit(2);
 if(hmSchedulerArtisan("php\0artisan\0schedule:run\0")!=='artisan')exit(3);
 if(hmSchedulerArtisan("php\0-r\0code\0artisan\0schedule:run\0")!==null||hmSchedulerArtisan("php\0artisan\0list\0")!==null)exit(4);
 foreach(["php\0other.php\0/pinned/artisan\0schedule:run\0","php\0-rCODE\0/pinned/artisan\0schedule:run\0","php\0artisan\0schedule:run\0other.php\0","php\0-d\0artisan\0schedule:run\0"] as $bad){if(hmSchedulerArtisan($bad)!==null)exit(6);}
 if(hmSchedulerArtisan("php\0-d\0memory_limit=128M\0/pinned/artisan\0schedule:run\0")!=='/pinned/artisan'||hmSchedulerArtisan("php\0-f\0artisan\0--\0schedule:run\0")!=='artisan')exit(7);
 if(!hmSchedulerUid("Uid:\t1000\t1000\t1000\t1000\n",1000)||hmSchedulerUid("Uid:\t1000\t0\t1000\t1000\n",1000))exit(5);
 echo 'PASS';`);
 assert.equal(result.status,0,result.stderr);assert.equal(result.stdout,'PASS');
});
test('isolated short-lived PHP witness binds actual executable script UID and namespace',{skip:!php,timeout:8000},async()=>{
 const directory=mkdtempSync(join(tmpdir(),'horus-scheduler-child-'));
 const script=join(directory,'artisan');const ready=join(directory,'ready');
 writeFileSync(script,"<?php file_put_contents(getenv('FIXTURE_READY_FILE'),'ready'); usleep(1500000);\n");
 let child;
 try {
  // This temporary script does not load Laravel or execute any real scheduler.
  child=spawn('php',[script,'schedule:run'],{cwd:tmpdir(),env:{...process.env,FIXTURE_READY_FILE:ready},stdio:'ignore'});
  for(let i=0;i<100&&!existsSync(ready);i++)await new Promise(resolve=>setTimeout(resolve,5));
  assert.ok(existsSync(ready),'Synthetic PHP child did not start');
  const result=run(String.raw`
 $pid=(int)$argv[1];$root=$argv[2];$uid=posix_geteuid();$php=realpath(PHP_BINARY);$ns=readlink('/proc/self/ns/pid');
 $good=hmSchedulerCapture($pid,$uid,$root,$php,$ns);
 if(!$good||$good['pid']!==$pid||$good['artisan']!==$root.'/artisan')exit(2);
 if(hmSchedulerCapture($pid,$uid+1,$root,$php,$ns)!==null)exit(3);
 if(hmSchedulerCapture($pid,$uid,$root,'/wrong-php',$ns)!==null)exit(4);
 if(hmSchedulerCapture($pid,$uid,$root.'/wrong-script',$php,$ns)!==null)exit(5);
 if(hmSchedulerCapture($pid,$uid,$root,$php,'pid:[0]')!==null)exit(6);
 echo 'PASS';`,[String(child.pid),directory]);
  assert.equal(result.status,0,result.stderr);assert.equal(result.stdout,'PASS');
  await new Promise(resolve=>child.exitCode!==null?resolve():child.once('exit',resolve));
  const gone=run("if(hmSchedulerCapture((int)$argv[1],posix_geteuid(),$argv[2],realpath(PHP_BINARY),readlink('/proc/self/ns/pid'))!==null)exit(2);echo 'PASS';",[String(child.pid),directory]);
  assert.equal(gone.status,0,gone.stderr);assert.equal(gone.stdout,'PASS');
 } finally {if(child&&child.exitCode===null)child.kill();rmSync(directory,{recursive:true,force:true});}
});

test('real bounded observer loop captures an isolated short-lived canonical PHP fixture',{skip:!php,timeout:12000},async()=>{
 const directory=mkdtempSync(join(tmpdir(),'horus-observer-loop-'));
 const script=join(directory,'artisan');const output=join(directory,'process.json');const sha='a'.repeat(40);
 writeFileSync(join(directory,'.horus-release'),'release_id='+sha+'\n');
 writeFileSync(script,'<?php usleep(2500000);\n');
 let observer,child;
 const waitExit=process=>new Promise(resolve=>process.exitCode!==null||process.signalCode!==null?resolve():process.once('exit',resolve));
 try {
  observer=spawn('php',[resolve(path),directory,sha,output],{stdio:'ignore'});
  for(let i=0;i<100&&!existsSync(output);i++)await new Promise(resolve=>setTimeout(resolve,10));
  assert.ok(existsSync(output),'Observer did not publish its initial private envelope');
  child=spawn('php',[script,'schedule:run'],{cwd:tmpdir(),stdio:'ignore'});
  let evidence=null;
  for(let i=0;i<80;i++) {
   if(existsSync(output)) {
    const candidate=JSON.parse(readFileSync(output,'utf8'));
    if(candidate.available===true){evidence=candidate;break;}
   }
   await new Promise(resolve=>setTimeout(resolve,50));
  }
  assert.ok(evidence,'Bounded observer did not capture the short-lived synthetic script');
  assert.equal(evidence.source,'CANONICAL_PROCESS_IDENTITY');assert.equal(evidence.release_sha,sha);
  assert.equal(evidence.collector_pid,observer.pid);assert.equal(evidence.witness.pid,child.pid);
  assert.equal(evidence.witness.artisan,script);assert.match(evidence.witness.start,/^[0-9]+$/);
  assert.equal(evidence.witness.namespace,evidence.namespace);assert.equal(evidence.witness.uid,evidence.uid);
  assert.ok(Number.isInteger(evidence.witness.observed_at));
 } finally {
  for(const process of [child,observer])if(process&&process.exitCode===null&&process.signalCode===null)process.kill('SIGKILL');
  await Promise.all([child,observer].filter(Boolean).map(waitExit));
  rmSync(directory,{recursive:true,force:true});
 }
});
