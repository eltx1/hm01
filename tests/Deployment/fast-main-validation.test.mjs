import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import assert from 'node:assert/strict';

const workflow = readFileSync(new URL('../../.github/workflows/production-release.yml', import.meta.url), 'utf8');
const step = (name) => {
  const start = workflow.indexOf(`- name: ${name}\n`);
  assert.ok(start >= 0, `missing step ${name}`);
  const next = workflow.indexOf('\n      - ', start + 1);
  return workflow.slice(start, next === -1 ? undefined : next);
};

test('duplicate validation is skipped only for main pushes of an identical, same-repository tree', () => {
  const reuse = step('Reuse validation of the identical pull request tree');
  assert.match(reuse, /if: github\.event_name == 'push' && github\.ref == 'refs\/heads\/main' && vars\.HORUS_FULL_MAIN_VALIDATION != 'true'/);
  assert.match(reuse, /event: 'pull_request', status: 'success'/);
  assert.match(reuse, /run\.head_repository\?\.full_name !== full/);
  assert.match(reuse, /commit\.commit\.tree\.sha === tree/);
  assert.match(workflow, /permissions:\n  contents: read\n  # [^\n]*\n  actions: read\n/);
  assert.doesNotMatch(workflow, /contents: write|actions: write/);
});

test('heavy test steps are conditional while the release artifact is always built and validated', () => {
  for (const name of ['Render changed forms through real application routes', 'Build and inspect report and publisher-login previews',
    'Static, browser, and PHP validation', 'Review real Blade forms in desktop and mobile browsers']) {
    assert.match(step(name), /if: steps\.reuse\.outputs\.validated != 'true'/, name);
  }
  assert.match(step('Build production assets (identical tree already validated)'), /if: steps\.reuse\.outputs\.validated == 'true'[\s\S]*npm run build[\s\S]*horus-prebid\.sha256/);
  for (const name of ['Validate deployment foundation', 'Smoke-test production environment template', 'Install production PHP dependencies and validate ZIP']) {
    assert.doesNotMatch(step(name), /\n        if:/, name);
  }
  assert.match(workflow, /name: horus-media-platform-\$\{\{ github\.sha \}\}/);
  assert.match(workflow, /\.\/scripts\/validate-release\.sh/);
});
