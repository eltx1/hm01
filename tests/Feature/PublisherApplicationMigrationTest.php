<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PublisherApplicationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_publisher_application_migrations_roll_back_and_reapply_in_dependency_order(): void
    {
        $applicationMigration = require database_path('migrations/2026_08_14_020000_create_publisher_applications_tables.php');
        $legalEvidenceMigration = require database_path('migrations/2026_08_15_020000_create_publisher_application_legal_evidence.php');

        $this->assertTrue(Schema::hasTable('publisher_applications'));
        $this->assertTrue(Schema::hasTable('publisher_application_revisions'));
        $this->assertTrue(Schema::hasTable('publisher_application_events'));
        $this->assertTrue(Schema::hasTable('publisher_application_legal_acceptances'));
        $this->assertTrue(Schema::hasTable('publisher_application_marketing_consents'));

        // Laravel rolls migrations back newest-first. Task 29 evidence tables are
        // children of Task 27 applications, so remove them before the parent graph.
        $legalEvidenceMigration->down();
        $applicationMigration->down();

        $this->assertFalse(Schema::hasTable('publisher_application_legal_acceptances'));
        $this->assertFalse(Schema::hasTable('publisher_application_marketing_consents'));
        $this->assertFalse(Schema::hasTable('publisher_applications'));
        $this->assertFalse(Schema::hasTable('publisher_application_domain_claims'));
        $this->assertFalse(Schema::hasTable('publisher_application_revisions'));
        $this->assertFalse(Schema::hasTable('publisher_application_events'));

        $applicationMigration->up();
        $legalEvidenceMigration->up();

        $this->assertTrue(Schema::hasTable('publisher_applications'));
        $this->assertTrue(Schema::hasColumn('publisher_applications', 'current_revision'));
        $this->assertTrue(Schema::hasColumn('publisher_application_revisions', 'snapshot_hash'));
        $this->assertTrue(Schema::hasTable('publisher_application_legal_acceptances'));
        $this->assertTrue(Schema::hasColumn('publisher_application_legal_acceptances', 'evidence_hash'));
        $this->assertTrue(Schema::hasTable('publisher_application_marketing_consents'));
        $this->assertTrue(Schema::hasColumn('publisher_application_marketing_consents', 'opted_in'));
    }
}
