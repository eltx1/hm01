<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PublisherApplicationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_27_migration_rolls_back_and_reapplies_safely_on_sqlite(): void
    {
        $migration = require database_path('migrations/2026_08_14_020000_create_publisher_applications_tables.php');
        $this->assertTrue(Schema::hasTable('publisher_applications'));
        $this->assertTrue(Schema::hasTable('publisher_application_revisions'));
        $this->assertTrue(Schema::hasTable('publisher_application_events'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('publisher_applications'));
        $this->assertFalse(Schema::hasTable('publisher_application_domain_claims'));
        $this->assertFalse(Schema::hasTable('publisher_application_revisions'));
        $this->assertFalse(Schema::hasTable('publisher_application_events'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('publisher_applications'));
        $this->assertTrue(Schema::hasColumn('publisher_applications', 'current_revision'));
        $this->assertTrue(Schema::hasColumn('publisher_application_revisions', 'snapshot_hash'));
    }
}
