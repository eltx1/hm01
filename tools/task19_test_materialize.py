from pathlib import Path
p = Path('tests/Browser/hm-loader-prebid-standalone.test.js')
s = p.read_text()
marker = "test('Task 19 standalone Prebid and two Direct JS placements operate independently'"
if marker not in s:
    s += r'''

test('Task 19 standalone Prebid and two Direct JS placements operate independently', async () => {
    const directA = {
        ...standaloneConfig().placements[0], code: 'direct_a', renderer: 'DIRECT_JS',
        prebidStandaloneEnabled: false, directJsEnabled: true, nativeEnabled: true,
    };
    const directB = {
        ...standaloneConfig().placements[0], code: 'direct_b', renderer: 'DIRECT_JS',
        prebidStandaloneEnabled: false, directJsEnabled: true, nativeEnabled: true,
    };
    const selected = standaloneConfig({
        nativeDemandEnabled: true,
        nativeDemand: {
            enabled: true, fallbackOrder: ['PROVIDER_A', 'PROVIDER_B'], placements: {
                direct_a: { enabled: true, candidates: [{ network: 'PROVIDER_A', priority: 10, gamManaged: false, tag: { scriptUrl: 'https://a.example.test/a.js', containerId: 'direct-a', renderTimeoutMs: 1, assumeLoadedIsSuccess: true } }], house: null },
                direct_b: { enabled: true, candidates: [{ network: 'PROVIDER_B', priority: 10, gamManaged: false, tag: { scriptUrl: 'https://b.example.test/b.js', containerId: 'direct-b', renderTimeoutMs: 1, assumeLoadedIsSuccess: true } }], house: null },
            },
        },
        placements: [standaloneConfig().placements[0], directA, directB],
    });
    const { sandbox, metrics } = harness(selected, { elements: [element('slot_a'), element('direct_a'), element('direct_b')] });
    await sandbox.HorusMediaLoader.boot();
    await settle();

    assert.equal(metrics.requests.length, 1, 'standalone Prebid starts its own auction');
    assert.equal(metrics.renders.length, 1, 'standalone Prebid renders independently');
    assert.equal(metrics.nativeLoads, 2, 'both Direct JS placements start without waiting for a global auction');
    assert.equal(metrics.gptLoads, 0, 'GAM/GPT is never introduced by parallel no-GAM serving');
});
'''
p.write_text(s)
