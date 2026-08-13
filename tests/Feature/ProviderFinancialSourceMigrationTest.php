<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProviderFinancialSourceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_21_migration_rolls_back_and_reapplies_safely_on_sqlite(): void
    {
        $migration = require database_path('migrations/2026_08_13_140000_add_monetization_financial_source_integrity.php');
        $this->assertTrue(Schema::hasTable('monetization_financial_bindings'));
        $this->assertTrue(Schema::hasColumn('daily_reports', 'settlement_eligible'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('monetization_financial_bindings'));
        $this->assertFalse(Schema::hasColumn('daily_reports', 'settlement_eligible'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('monetization_financial_bindings'));
        $this->assertTrue(Schema::hasColumn('report_import_jobs', 'settlement_ineligibility_reason'));
    }
}
