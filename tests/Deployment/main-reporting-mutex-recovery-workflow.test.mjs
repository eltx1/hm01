import {readFileSync, mkdtempSync, mkdirSync, writeFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, basename} from 'node:path';
import {createRequire} from 'node:module';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
import assert from 'node:assert/strict';
const workflow=readFileSync('.github/workflows/recover-main-reporting-mutex.yml','utf8');
test('legacy main-lease recovery requires validated main environment with existing pinned SSH trust',()=>{
 assert.match(workflow,/github.event_name == 'push' && github.repository == 'eltx1\/hm01' && github.ref == 'refs\/heads\/main'/);
 assert.match(workflow,/needs: validate/);assert.match(workflow,/environment: production/);
 assert.match(workflow,/ref: \$\{\{ github.sha \}\}/);
 assert.match(workflow,/StrictHostKeyChecking=yes/);assert.match(workflow,/UpdateHostKeys=no/);
 assert.doesNotMatch(workflow,/ssh-keyscan|StrictHostKeyChecking=no|forceRelease|schedule:clear-cache|cache:clear|upload-artifact/);
});
test('explicit eligible preview precedes exact CAS apply and remote lifetime is bounded',()=>{
 assert.ok(workflow.indexOf('invoke preview')<workflow.indexOf('invoke apply'));
 assert.match(workflow,/case "\$status" in[\s\S]*NO_ACTION\) exit 0[\s\S]*ELIGIBLE\)/);
 assert.match(workflow,/preflight stopped safely/);
 assert.match(workflow,/RELEASED\|NO_ACTION\) exit 0/);
 const bound=workflow.match(/\/usr\/bin\/timeout --kill-after=(\d+) (\d+) \/usr\/bin\/php8\.4/);
 assert.ok(bound);assert.ok(Number(bound[1])+Number(bound[2])<300);
 assert.match(workflow,/cancel-in-progress: false/);assert.match(workflow,/Raw output withheld/);
 assert.doesNotMatch(workflow,/^  (?:schedule|workflow_dispatch|workflow_run):/m);
});
test('embedded refresh shell parses without execution',()=>{
 const run=workflow.match(/        run: \|\n([\s\S]*?)$/);
 assert.ok(run);
 // Select the final multi-line command block, excluding earlier setup steps.
 const start=workflow.lastIndexOf('        run: |\n');
 const body=workflow.slice(start+'        run: |\n'.length).replace(/^          /gm,'');
 const check=spawnSync('bash',['-n'],{input:body,encoding:'utf8'});
 assert.equal(check.status,0,check.stderr);
});

const factory=workflow.match(/            \/\/ BEGIN_PROOF_TOOLS\n([\s\S]*?)            \/\/ END_PROOF_TOOLS/);
assert.ok(factory);
const factorySource=factory[1].replace(/^            /gm,'');
const tools=(new Function(factorySource+'\nreturn proofTools();'))();
const sha='a'.repeat(40);
const repository={id:1315038901,full_name:'eltx1/hm01'};
const ids={verify:101,deploy:102,validation:103};
function run(kind,id=ids[kind]) {
 const expected=tools.kinds[kind];
 return {id,workflow_id:ids[kind]+1000,run_attempt:2,name:expected.name,path:expected.path,event:kind==='validation'?'push':'workflow_run',
  status:'completed',conclusion:'success',head_branch:'main',head_sha:sha,repository:{...repository},head_repository:{...repository},
  run_started_at:'2026-10-09T10:00:00Z',updated_at:'2026-10-09T10:10:00Z'};
}
function artifact(kind,name) {
 const current=run(kind);
 return {id:ids[kind]+2000,name,expired:false,size_in_bytes:1024,digest:'sha256:'+'b'.repeat(64),created_at:'2026-10-09T10:05:00Z',
  workflow_run:{id:current.id,head_sha:sha,head_branch:'main',repository_id:repository.id,head_repository_id:repository.id}};
}
function liveProof() {
 return {schema_version:'1',release_sha:sha,deploy_run_id:String(ids.deploy),manifest_hash:'b'.repeat(64),pages_deployment_url:'https://verified.example.pages.dev',
  lordai_config_path:'configs/public/config.json',lordai_config_sha256:'c'.repeat(64),lordai_loader_sha256:'d'.repeat(64),verify_run_id:String(ids.verify),verify_run_attempt:'2'};
}
function deployProof() {
 return {schema_version:'1',release_sha:sha,validation_run_id:String(ids.validation),artifact_sha256:'e'.repeat(64),deploy_run_id:String(ids.deploy),deploy_run_attempt:'2'};
}
const encode=object=>Object.entries(object).map(([key,value])=>`${key}=${value}`).join('\n')+'\n';
const clone=value=>JSON.parse(JSON.stringify(value));

test('release pin comes only from a verified immutable artifact chain, never branch HEAD or a fixed old SHA',()=>{
 assert.match(workflow,/actions: read/);
 assert.equal((workflow.match(/uses: actions\/download-artifact@v8/g)||[]).length,2);
 assert.equal((workflow.match(/digest-mismatch: error/g)||[]).length,2);
 assert.equal((workflow.match(/artifact-ids: \$\{\{ steps\.(?:live_source|deploy_source)\.outputs\.artifact_id \}\}/g)||[]).length,2);
 assert.match(workflow,/workflow_id:workflows.verify,branch:'main',status:'success',per_page:1/);
 assert.match(workflow,/EXPECTED_RELEASE_SHA: \$\{\{ steps.release_proof.outputs.release_sha \}\}/);
 assert.match(workflow,/HM_EXPECTED_RELEASE_SHA="\$expected"/);
 assert.doesNotMatch(workflow,/EXPECTED_RELEASE_SHA: [a-f0-9]{40}|getRef\(|getBranch\(|console\.log\(|core\.setFailed\([^'\n]/);
});
test('trust helper accepts only fixed repository main workflows and denies wrong event source and attempt',()=>{
 const context={eventName:'push',ref:'refs/heads/main',repo:{owner:'eltx1',repo:'hm01'},payload:{repository:{...repository}}};
 tools.contextOk(context);
 for(const changed of [{...context,eventName:'pull_request'},{...context,ref:'refs/heads/other'},{...context,payload:{repository:{...repository,id:99}}},
  {...context,repo:{owner:'fork',repo:'hm01'}}]) assert.throws(()=>tools.contextOk(changed));
 for(const kind of Object.keys(ids)) {
  const current=run(kind);const workflowData={id:current.workflow_id,name:current.name,path:current.path,state:'active'};
  tools.workflowOk(workflowData,kind);tools.runOk(current,current.id,kind,current.workflow_id,sha);
  for(const change of [{event:'pull_request'},{head_branch:'feature'},{conclusion:'failure'},{status:'in_progress'},
   {head_sha:'bad'},{head_sha:'f'.repeat(40)},{path:'.github/workflows/untrusted.yml'},{workflow_id:999},{run_attempt:0},
   {repository:{id:99,full_name:'eltx1/hm01'}},{head_repository:{id:repository.id,full_name:'fork/hm01'}}]) {
   assert.throws(()=>tools.runOk({...current,...change},current.id,kind,current.workflow_id,sha));
  }
  assert.throws(()=>tools.workflowOk({...workflowData,state:'disabled_manually'},kind));
 }
 assert.throws(()=>tools.runOk({...run('validation'),event:'workflow_run'},ids.validation,'validation',1103,sha));
});
test('proof parser and link validators reject wrong schema malformed SHA duplicate keys and source identities',()=>{
 const live=tools.parseProof(encode(liveProof()));tools.liveOk(live,run('verify'));
 const deploy=tools.parseProof(encode(deployProof()));tools.deployOk(deploy,run('deploy'),sha);
 for(const bad of [encode({...liveProof(),schema_version:'2'}),encode({...liveProof(),release_sha:'A'.repeat(40)}),
  encode({...liveProof(),release_sha:'a'.repeat(39)}),encode(liveProof())+'release_sha='+sha+'\n','x'.repeat(16385),encode(liveProof()).replace('schema_version=1','schema_version=1\r')]) assert.throws(()=>tools.parseProof(bad));
 for(const change of [{verify_run_id:'999'},{verify_run_attempt:'1'},{deploy_run_id:'invalid'},
  {manifest_hash:'bad'},{unexpected:'field'}]) assert.throws(()=>tools.liveOk({...liveProof(),...change},run('verify')));
 for(const change of [{deploy_run_id:'999'},{deploy_run_attempt:'1'},{validation_run_id:'invalid'},{artifact_sha256:'bad'},{release_sha:'f'.repeat(40)}]) assert.throws(()=>tools.deployOk({...deployProof(),...change},run('deploy'),sha));
});
test('artifact provenance must belong to current successful attempt and exact repo workflow run',()=>{
 const name='horus-production-live-proof';const good=artifact('verify',name);tools.artifactOk(good,run('verify'),name);
 for(const change of [{expired:true},{digest:'bad'},{size_in_bytes:65537},{name:'other'},
  {created_at:'2026-10-09T09:59:59Z'},{created_at:'2026-10-09T10:11:00Z'},
  {workflow_run:{...good.workflow_run,id:999}},{workflow_run:{...good.workflow_run,repository_id:99}},
  {workflow_run:{...good.workflow_run,head_sha:'f'.repeat(40)}}]) assert.throws(()=>tools.artifactOk({...good,...change},run('verify'),name));
});

const scripts=[...workflow.matchAll(/          script: \|\n((?:(?:            .*|)\n)*)/g)].map(match=>match[1].replace(/^            /gm,''));
const AsyncFunction=Object.getPrototypeOf(async function(){}).constructor;
test('all embedded proof scripts parse as async modules',()=>{
 assert.equal(scripts.length,3);
 for(const script of scripts) assert.doesNotThrow(()=>new AsyncFunction('github','context','core','require','process',script));
});

async function mockedProofChain(tamper=false,differentProducerHeads=false) {
 const directory=mkdtempSync(join(tmpdir(),'horus-current-proof-'));
 const context={eventName:'push',ref:'refs/heads/main',repo:{owner:'eltx1',repo:'hm01'},payload:{repository:{...repository}}};
 const runs=Object.fromEntries(Object.keys(ids).map(kind=>[ids[kind],run(kind)]));
 const artifacts={101:artifact('verify','horus-production-live-proof'),102:artifact('deploy','horus-production-deploy-proof')};
 if(differentProducerHeads){runs[101].head_sha='c'.repeat(40);runs[102].head_sha='d'.repeat(40);artifacts[101].workflow_run.head_sha=runs[101].head_sha;artifacts[102].workflow_run.head_sha=runs[102].head_sha;}
 const outputs=[];const failures=[];let finalPhase=false;
 const actions={
  getWorkflow:async ({workflow_id})=>{const kind=Object.keys(ids).find(kind=>basename(tools.kinds[kind].path)===workflow_id);return {data:{id:ids[kind]+1000,name:tools.kinds[kind].name,path:tools.kinds[kind].path,state:'active'}};},
  listWorkflowRuns:async parameters=>{assert.equal(parameters.per_page,1);assert.equal(parameters.status,'success');assert.equal(parameters.branch,'main');return {data:{workflow_runs:[{id:101}]}};},
  getWorkflowRun:async ({run_id})=>({data:{...clone(runs[run_id]),...(tamper && finalPhase && run_id===101?{run_attempt:3}:{})}}),
  getWorkflowRunAttempt:async ({run_id,attempt_number})=>{assert.equal(attempt_number,2);return {data:clone(runs[run_id])};},
  listWorkflowRunArtifacts:()=>{},
  getArtifact:async ({artifact_id})=>({data:clone(Object.values(artifacts).find(item=>item.id===artifact_id))}),
 };
 const github={rest:{actions},paginate:async (method,{run_id})=>{assert.equal(method,actions.listWorkflowRunArtifacts);return [clone(artifacts[run_id])];}};
 const require=createRequire(import.meta.url);
 try {
  const execute=async(index)=>{
   const stageOutputs={};outputs.push(stageOutputs);
   await new AsyncFunction('github','context','core','require','process',scripts[index])(github,context,{setOutput:(key,value)=>stageOutputs[key]=value,setFailed:message=>failures.push(message)},require,{env:{RUNNER_TEMP:directory}});
  };
  await execute(0);assert.equal(failures.length,0);
  assert.deepEqual(outputs[0],{run_id:'101',artifact_id:'2101'});
  const live=join(directory,'main-lease-trust','live');mkdirSync(live);writeFileSync(join(live,'live-proof.env'),encode(liveProof()));
  await execute(1);assert.equal(failures.length,0);
  assert.deepEqual(outputs[1],{run_id:'102',artifact_id:'2102'});
  const deploy=join(directory,'main-lease-trust','deploy');mkdirSync(deploy);writeFileSync(join(deploy,'deploy-proof.env'),encode(deployProof()));
  finalPhase=true;await execute(2);
  return {outputs,failures};
 } finally {rmSync(directory,{recursive:true,force:true});}
}
test('complete trusted artifact chain exports only the verified release SHA',async()=>{
 const result=await mockedProofChain();assert.deepEqual(result.failures,[]);assert.deepEqual(result.outputs[2],{release_sha:sha});
});
test('run attempt changing after artifact download prevents any release pin',async()=>{
 const result=await mockedProofChain(true);assert.deepEqual(result.failures,['MAIN_LEASE_TRUST_PROOF_INVALID']);assert.deepEqual(result.outputs[2],{});
});

test('same-account private cron collector is fresh fail-closed and remote shell parses',()=>{
 const blocks=[...workflow.matchAll(/cat (?:>|>>) "\$work\/remote-run.sh" <<'REMOTE'\n([\s\S]*?)\n          REMOTE/g)].map(match=>match[1].replace(/^          /gm,''));assert.equal(blocks.length,2);
 const observer=readFileSync('ops/audit/observe-canonical-scheduler.php','utf8');
 const remote=blocks[0]+'\n'+observer+'\nOBSERVER_PHP\n'+blocks[1];
 const php=readFileSync('ops/audit/recover-main-reporting-mutex.php','utf8');
 const check=spawnSync('bash',['-n'],{input:remote+'\n'+php+'\nRECOVERY_PHP\n',encoding:'utf8'});assert.equal(check.status,0,check.stderr);
 assert.match(remote,/crontab -l 2>\/dev\/null/);assert.match(remote,/while sleep 1; do collect_cron; done/);
 assert.match(remote,/HM_RECOVERY_CRON_EVIDENCE="\$evidence_dir\/cron.json"/);
 assert.match(remote,/id -u/);assert.match(remote,/chmod 600/);assert.doesNotMatch(remote,/sudo|crontab -(?:r|u|e)/);
 const collector=remote.match(/collect_cron\(\) \{\n([\s\S]*?)\n\}\ncollect_cron/);assert.ok(collector);
 const directory=mkdtempSync(join(tmpdir(),'horus-cron-evidence-'));
 try {
  const bin=join(directory,'bin');mkdirSync(bin);
  const crontab=join(bin,'crontab');
  const cron='* * * * * /usr/bin/php8.4 /home/fixture/current/artisan schedule:run';
  writeFileSync(crontab,'#!/bin/sh\nprintf \'%s\\n\' \''+cron+'\'\n',{mode:0o700});
  const run=()=>spawnSync('bash',['-c','set -euo pipefail; umask 077; evidence_dir="$1"; collect_cron() {\n'+collector[1]+'\n}; collect_cron','fixture',directory],{env:{...process.env,PATH:bin+':'+process.env.PATH},encoding:'utf8'});
  let result=run();assert.equal(result.status,0,result.stderr);assert.equal(result.stdout,'');
  const evidence=JSON.parse(readFileSync(join(directory,'cron.json'),'utf8'));
  assert.equal(evidence.available,true);assert.equal(Buffer.from(evidence.cron_base64,'base64').toString(),cron);
  writeFileSync(crontab,'#!/bin/sh\nexit 1\n',{mode:0o700});result=run();assert.equal(result.status,0,result.stderr);
  const unavailable=JSON.parse(readFileSync(join(directory,'cron.json'),'utf8'));assert.equal(unavailable.schema_version,1);assert.equal(unavailable.available,false);assert.equal(unavailable.uid,evidence.uid);assert.ok(Number.isInteger(unavailable.observed_at));
 } finally {rmSync(directory,{recursive:true,force:true});}
});

test('trusted orchestration heads may differ while successful push validation pins the deployed release',async()=>{
 const result=await mockedProofChain(false,true);assert.deepEqual(result.failures,[]);assert.deepEqual(result.outputs[2],{release_sha:sha});
});

test('nonzero SSH path prints only validated closed JSON and never raw diagnostics',()=>{
 assert.match(workflow,/< "\$work\/remote-run.sh" > "\$work\/result.json" 2> "\$work\/error.txt"; then[\s\S]*node ops\/audit\/validate-main-reporting-recovery.mjs "\$work\/result.json" \|\| true[\s\S]*Raw output withheld/);
 assert.doesNotMatch(workflow,/cat[^\n]*(?:result.json|error.txt)|echo[^\n]*\$\{?error/);
});
