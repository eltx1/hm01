<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Models\AuditLog;
use App\Services\Audit\AuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_sensitive_event_can_be_recorded(): void
    {
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        app(AuditRecorder::class)->record(
            event: 'security.setting.changed',
            organizationId: $organization->id,
            newValues: ['enabled' => true, 'token' => 'must-not-be-stored'],
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security.setting.changed',
            'organization_id' => $organization->id,
        ]);
        $this->assertSame('[REDACTED]', AuditLog::firstOrFail()->new_values['token']);
    }
}
