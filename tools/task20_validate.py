from pathlib import Path

# Permanent production-release migration rollback/reapply validation.
p = Path('.github/workflows/production-release.yml')
s = p.read_text()
needle = '          DB_CONNECTION=sqlite DB_DATABASE="$GITHUB_WORKSPACE/database/database.sqlite" php artisan migrate:fresh --seed --force\n'
replacement = needle + '          DB_CONNECTION=sqlite DB_DATABASE="$GITHUB_WORKSPACE/database/database.sqlite" php artisan migrate:rollback --step=1 --force\n          DB_CONNECTION=sqlite DB_DATABASE="$GITHUB_WORKSPACE/database/database.sqlite" php artisan migrate --force\n'
if 'migrate:rollback --step=1 --force' not in s:
    if needle not in s: raise SystemExit('production release migration anchor missing')
    s = s.replace(needle, replacement, 1)
p.write_text(s)

# Browser adversarial regression: a failed/late Direct candidate must lose its
# container before the next candidate renders, preventing visual double render.
p = Path('tests/Browser/hm-loader-direct-demand.test.js')
s = p.read_text()
marker = "test('Task 20 removes failed Direct candidate container before provider fallback'"
if marker not in s:
    s += r'''

test('Task 20 removes failed Direct candidate container before provider fallback', async () => {
    const first = recipe({ url: 'https://ads.example.com/fail-cleanup.js', dedupeKey: 'fail-cleanup' });
    first.container = { ...first.container, id: 'failed-zone' };
    first.containerId = 'failed-zone';
    const second = recipe({ url: 'https://two.example.com/success-cleanup.js', dedupeKey: 'success-cleanup' });
    second.container = { ...second.container, id: 'winner-zone' };
    second.containerId = 'winner-zone';
    const selected = config({ header: { enabled: true, candidates: [candidate('ONE', first, 10), candidate('TWO', second, 20)], house: null } });
    const { sandbox, elements } = harness(selected, { failingUrls: ['https://ads.example.com/fail-cleanup.js'] });
    await sandbox.HorusMediaLoader.boot();
    await settle();

    const providerContainers = elements[0].children.filter((child) => child.getAttribute?.('data-hm-direct-network'));
    assert.equal(providerContainers.length, 1, 'only the winning provider container remains');
    assert.equal(providerContainers[0].getAttribute('data-hm-direct-network'), 'TWO');
    assert.equal(elements[0].getAttribute('data-hm-direct'), 'TWO');
});
'''
p.write_text(s)
