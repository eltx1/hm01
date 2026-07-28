<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Services\Identity\SessionInvalidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class SessionInvalidationTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_all_database_sessions_for_user_can_be_invalidated(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'session-one',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->assertSame(1, app(SessionInvalidator::class)->invalidate($user));
        $this->assertDatabaseMissing('sessions', ['id' => 'session-one']);
    }
}
