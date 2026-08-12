from pathlib import Path

index = Path('resources/views/admin/demand/index.blade.php')
text = index.read_text()
text = text.replace("route('admin.supply-chain.ads-txt.index')", "route('admin.compliance.ads-txt.index')")
old = '''                <label>Approved script origins<textarea class="hm-input" rows="3" name="script_origins[]">{{ implode("\\n", $network->script_origins ?? []) }}</textarea></label>'''
new = '''                <div><label>Approved script origins</label>@foreach($network->script_origins ?? [] as $origin)<input class="hm-input" type="url" name="script_origins[]" value="{{ $origin }}">@endforeach<input class="hm-input" type="url" name="script_origins[]" placeholder="https://provider.example"></div>'''
if old in text:
    text = text.replace(old, new, 1)
index.write_text(text)

test = Path('tests/Feature/DirectDemandAdminControlCenterTest.php')
text = test.read_text()
if 'use App\\Models\\DemandAdsTxtRecord;' not in text:
    text = text.replace('use App\\Models\\DemandAccount;\n', 'use App\\Models\\DemandAccount;\nuse App\\Models\\DemandAdsTxtRecord;\n')
text = text.replace('$this->actingAs($this->admin)', '$this->adminSession()')
text = text.replace("        $this->assertDatabaseHas('ads_txt_records', ['site_id' => $this->site->id, 'account_id' => '100']);", "        $this->assertTrue(DemandAdsTxtRecord::withoutGlobalScopes()\n            ->where('site_id', $this->site->id)\n            ->where('demand_account_id', $this->account->id)\n            ->where('publisher_account_id', '100')\n            ->where('status', 'ACTIVE')\n            ->exists());")
text = text.replace("['scope_type' => 'GLOBAL', 'control_key' => 'DIRECT_JS', 'is_disabled' => true]", "['scope_type' => 'PLATFORM', 'control_key' => 'DIRECT_JS', 'is_disabled' => true]")
old_isolation = '''        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('ISOLATED_IFRAME', $encoded);
        $this->assertStringContainsString('allow-scripts', $encoded);
        $this->assertStringNotContainsString('allow-same-origin', $encoded);
'''
new_isolation = '''        $customCandidate = collect(data_get($payload, 'directDemand.placements.header_banner.candidates', []))
            ->firstWhere('network', 'CUSTOM_THIRD_PARTY_TAG');
        $this->assertSame('ISOLATED_IFRAME', data_get($customCandidate, 'tag.executionMode'));
        $this->assertSame(['allow-scripts'], data_get($customCandidate, 'tag.isolation.sandbox'));
        $this->assertStringNotContainsString('allow-same-origin', json_encode(data_get($customCandidate, 'tag.isolation'), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('allow-top-navigation', json_encode(data_get($customCandidate, 'tag.isolation'), JSON_THROW_ON_ERROR));
'''
if old_isolation in text:
    text = text.replace(old_isolation, new_isolation, 1)
marker = '    private function approvedMgid($site, $placement): array\n'
helper = '''    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }

'''
if helper not in text:
    if marker not in text:
        raise SystemExit('adminSession insertion marker missing')
    text = text.replace(marker, helper + marker, 1)
test.write_text(text)

Path('scripts/task18_targeted_fix.py').unlink(missing_ok=True)
Path('.github/workflows/task18-targeted-tests.yml').unlink(missing_ok=True)
