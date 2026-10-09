import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {test} from 'node:test';
import assert from 'node:assert/strict';
const source=readFileSync(new URL('../../.github/workflows/diagnose-current-reporting.yml',import.meta.url),'utf8');
test('current diagnostic keeps production on protected main and exact deployed release',()=>{
 assert.match(source,/branches: \[main\]/);
 assert.match(source,/if: github.event_name == 'push' && github.repository == 'eltx1\/hm01' && github.ref == 'refs\/heads\/main'/);
 assert.match(source,/needs: validate/);
 assert.match(source,/environment: production/);
 assert.match(source,/ref: 5c3861f8ffdb97cec42c35476894e22b845beb3b/);
 assert.match(source,/grep -Fxq 'release_id=\$EXPECTED_SHA' .horus-release/);
 assert.doesNotMatch(source,/StrictHostKeyChecking=no|ssh-keyscan|schedule:clear-cache|cache:clear/);
});
test('diagnostic only returns allowlisted scheduler and process classes',()=>{
 assert.match(source,/node ops\/audit\/validate-scheduler-incident.mjs/);
 assert.match(source,/grep -Ex '\(MAIN_SYNC_PROCESS\|VIDEO_SYNC_PROCESS\)=\(PRESENT\|ABSENT\)'/);
 assert.match(source,/Raw output withheld/);
 assert.match(source,/unset SSH_KEY KNOWN_HOSTS/);
 assert.doesNotMatch(source,/echo.*\$SSH_KEY|cat.*result.json|DB::|artisan.*reporting:sync/);
});
const php=spawnSync('php',['-v'],{encoding:'utf8'}).status===0;
test('process probe is syntactically valid and fails closed outside exact release',{skip:!php},()=>{
 const code=source.match(/          <\?php\n([\s\S]*?)\n          PHP\n/);
 assert.ok(code);
 const script='<?php\n'+code[1].replace(/^          /gm,'');
 const lint=spawnSync('php',['-l'],{input:script,encoding:'utf8'});
 assert.equal(lint.status,0,lint.stderr);
 const result=spawnSync('php',[],{input:script,encoding:'utf8'});
 assert.equal(result.status,1);
 assert.equal(result.stdout,'PROCESS_CHECK_UNAVAILABLE\n');
 assert.equal(result.stderr,'');
});
