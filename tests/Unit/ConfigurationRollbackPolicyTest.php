<?php

namespace Tests\Unit;

use App\Services\Inventory\ConfigurationRollbackPolicy;
use PHPUnit\Framework\TestCase;

class ConfigurationRollbackPolicyTest extends TestCase
{
    public function test_old_ad_settings_cannot_restore_old_admission_domains_or_disable_a_current_pause(): void
    {
        $old = ['placements' => [['code' => 'inline', 'status' => 'active']], 'trafficGate' => ['enabled' => false],
            'allowedHostnames' => ['removed.test'], 'clickGuard' => ['enabled' => false], 'status' => 'active',
            'immediatePause' => false, 'controls' => ['adServingDisabled' => false], 'recaptchaObservation' => ['enabled' => false]];
        $current = ['trafficGate' => ['enabled' => true, 'policy' => 'required'], 'allowedHostnames' => ['current.test'],
            'clickGuard' => ['enabled' => true], 'status' => 'paused', 'immediatePause' => true,
            'controls' => ['adServingDisabled' => true], 'recaptchaObservation' => ['enabled' => true]];
        $result = (new ConfigurationRollbackPolicy)->preserveCurrentProtection($old, $current);
        $this->assertSame($old['placements'], $result['placements']);
        foreach ($current as $key => $value) $this->assertSame($value, $result[$key]);
    }

    public function test_missing_current_field_does_not_retain_a_historical_security_value(): void
    {
        $this->assertSame(['placements' => []], (new ConfigurationRollbackPolicy)->preserveCurrentProtection(
            ['placements' => [], 'trafficGate' => ['enabled' => false], 'siteKey' => 'stale'], []));
    }

    public function test_rollback_retains_current_optional_observation_policy_and_other_historical_diagnostics(): void
    {
        $old = ['observability' => ['localDiagnostics' => true, 'performance' => ['enabled' => true, 'collectorOrigin' => 'https://old.test']]];
        $policy = new ConfigurationRollbackPolicy;
        $result = $policy->preserveCurrentProtection($old, ['performanceObservation' => ['enabled' => false]]);
        $this->assertSame(['localDiagnostics' => true, 'performance' => ['enabled' => false]], $result['observability']);
        $this->assertSame(['localDiagnostics' => true], $policy->preserveCurrentProtection($old, [])['observability']);
    }
    public function test_current_supply_inventory_and_renderer_controls_replace_historical_authorization(): void
    {
        $policy = new ConfigurationRollbackPolicy;
        $old = ['obsoleteProviderScript' => 'PRIVATE_OLD_SCRIPT', 'gamNetworkCode' => 'old', 'prebid' => ['private_old_bidder' => true], 'directDemand' => ['old_recipe' => true],
            'placements' => [['code' => 'a', 'enabled' => true, 'status' => 'active', 'adUnitPath' => '/old/path', 'targeting' => ['kept' => ['1']]],
                ['code' => 'deleted', 'enabled' => true]]];
        $current = ['gamNetworkCode' => 'new', 'prebid' => ['enabled' => false], 'directDemand' => ['enabled' => false, 'placements' => []],
            'placements' => [['code' => 'a', 'renderer' => 'GAM', 'rendererConflict' => false, 'adUnitPath' => '/new/path', 'gamEnabled' => true]]];
        $result = $policy->preserveCurrentSupply($old, $current);
        $this->assertArrayNotHasKey('obsoleteProviderScript', $result);
        $this->assertSame('new', $result['gamNetworkCode']);
        $this->assertSame($current['prebid'], $result['prebid']);
        $this->assertSame($current['directDemand'], $result['directDemand']);
        $this->assertCount(1, $result['placements']);
        $this->assertSame('/new/path', $result['placements'][0]['adUnitPath']);
        $this->assertSame(['kept' => ['1']], $result['placements'][0]['targeting']);
        $this->assertTrue($result['placements'][0]['enabled']);
        $this->assertFalse($policy->preserveCurrentSupply($old, $current, ['a'])['placements'][0]['enabled']);
    }

}
