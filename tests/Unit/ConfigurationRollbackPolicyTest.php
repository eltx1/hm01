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
}
