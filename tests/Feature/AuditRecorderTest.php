<?php

namespace Tests\Feature;

use App\Services\Audit\AuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_event_can_be_recorded(): void
    {
        app(AuditRecorder::class)->record(
            event: 'security.setting.changed',
            organizationId: '01J3Y3P1F5E8XW5RQGFN4A6Z9B',
            newValues: ['enabled' => true],
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security.setting.changed',
            'organization_id' => '01J3Y3P1F5E8XW5RQGFN4A6Z9B',
        ]);
    }
}
