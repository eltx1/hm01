import assert from 'node:assert/strict';

export function validateVerificationRun(run) {
    assert.equal(run.name, 'Verify production live');
    assert.equal(run.path, '.github/workflows/verify-production-live.yml');
    assert.equal(run.status, 'completed');
    assert.equal(run.conclusion, 'success');
    assert.equal(run.head_branch, 'main');
    assert.equal(run.head_repository?.full_name, 'eltx1/hm01');
    assert.equal(run.head_repository?.id, 1315038901);
    assert.ok(['workflow_run', 'workflow_dispatch'].includes(run.event));
    assert.ok(Number.isSafeInteger(run.id) && run.id > 0);
    assert.ok(Number.isSafeInteger(run.run_attempt) && run.run_attempt > 0);
    return {id: run.id, attempt: run.run_attempt};
}

export function verifiedRelease(proof, runId, attempt) {
    assert.equal(typeof proof, 'string');
    assert.ok(proof.length < 16384);
    const rows = proof.trim().split('\n').map(line => {
        const i = line.indexOf('=');
        assert.ok(i > 0);
        return [line.slice(0, i), line.slice(i + 1)];
    });
    const fields = Object.fromEntries(rows);
    assert.equal(rows.length, Object.keys(fields).length, 'Duplicate proof fields');
    assert.deepEqual(Object.keys(fields).sort(), ['schema_version','release_sha','deploy_run_id','manifest_hash','pages_deployment_url','lordai_config_path','lordai_config_sha256','lordai_loader_sha256','verify_run_id','verify_run_attempt'].sort());
    assert.equal(fields.schema_version, '1');
    assert.match(fields.release_sha, /^[a-f0-9]{40}$/);
    assert.match(fields.deploy_run_id, /^[1-9][0-9]*$/);
    assert.equal(fields.verify_run_id, String(runId));
    assert.equal(fields.verify_run_attempt, String(attempt));
    assert.match(fields.manifest_hash, /^[a-f0-9]{64}$/);
    return fields.release_sha;
}
