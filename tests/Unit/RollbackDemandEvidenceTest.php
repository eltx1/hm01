<?php

namespace Tests\Unit;

use App\Services\Inventory\RollbackDemandEvidence;
use PHPUnit\Framework\TestCase;

class RollbackDemandEvidenceTest extends TestCase
{
    public function test_comparison_is_placement_scoped_exact_and_redacted(): void
    {
        $tag = ['network' => 'PRIVATE_NETWORK', 'mode' => 'DIRECT_JS', 'gamManaged' => false, 'tag' => ['url' => 'PRIVATE_URL', 'attributes' => ['b' => 2, 'a' => 1]]];
        $historical = ['placements' => ['inline' => ['candidates' => [$tag]], 'removed' => ['candidates' => [$tag]]]];
        $same = $tag;
        $same['priority'] = 999;
        $same['tag'] = ['attributes' => ['a' => 1, 'b' => 2], 'url' => 'PRIVATE_URL'];
        $current = ['placements' => ['inline' => ['candidates' => [$same]]]];
        $service = new RollbackDemandEvidence;
        $this->assertSame(['matched' => 1, 'unverified' => 1, 'gam_managed_not_checked' => 0], $service->compare($historical, $current));
        $current['placements']['inline']['candidates'][0]['tag']['url'] = 'CHANGED';
        $result = $service->compare($historical, $current);
        $this->assertSame(2, $result['unverified']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        $historical['placements']['inline']['candidates'][0]['gamManaged'] = true;
        $this->assertSame(1, $service->compare($historical, [])['gam_managed_not_checked']);
    }
}
