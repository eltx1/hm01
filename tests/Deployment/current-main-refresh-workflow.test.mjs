import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
import assert from 'node:assert/strict';
const workflow=readFileSync('.github/workflows/refresh-current-main-reports.yml','utf8');
test('current-day recovery requires validated protected-main environment with existing pinned SSH trust',()=>{
 assert.match(workflow,/github.event_name == 'push' && github.repository == 'eltx1\/hm01' && github.ref == 'refs\/heads\/main'/);
 assert.match(workflow,/needs: validate/);assert.match(workflow,/environment: production/);
 assert.match(workflow,/ref: \$\{\{ github.sha \}\}/);
 assert.match(workflow,/StrictHostKeyChecking=yes/);assert.match(workflow,/UpdateHostKeys=no/);
 assert.doesNotMatch(workflow,/ssh-keyscan|StrictHostKeyChecking=no|forceRelease|schedule:clear-cache|cache:clear|upload-artifact/);
});
test('explicit eligible preview is required before apply and each remote process dies before importer lease expires',()=>{
 assert.ok(workflow.indexOf('invoke preview')<workflow.indexOf('invoke apply'));
 assert.match(workflow,/case "\$preview_status" in[\s\S]*COMPLETED\) exit 0[\s\S]*ELIGIBLE\)/);
 assert.match(workflow,/preflight stopped safely/);
 const bound=workflow.match(/\/usr\/bin\/timeout --kill-after=(\d+) (\d+) \/usr\/bin\/php8\.4/);
 assert.ok(bound);assert.ok(Number(bound[1])+Number(bound[2])<300);
 assert.match(workflow,/cancel-in-progress: false/);assert.match(workflow,/PENDING\) sleep 65/);
 assert.match(workflow,/CURRENT_MAIN_REFRESH=PENDING/);assert.match(workflow,/Raw output withheld/);
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
