<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WeeklyProgressReportMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_weekly_report_migration_can_be_rolled_back_and_reapplied(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        $migration = require database_path('migrations/2026_10_04_030000_create_weekly_progress_reports.php');

        $this->assertTrue(Schema::hasTable('weekly_progress_reports'));
        $this->assertTrue(Schema::hasColumn('notification_deliveries', 'weekly_progress_report_id'));

        $migration->down();

        $this->assertFalse(Schema::hasTable('weekly_progress_reports'));
        $this->assertFalse(Schema::hasColumn('notification_deliveries', 'weekly_progress_report_id'));
        $this->assertTrue(Schema::hasColumn('notification_deliveries', 'work_request_id'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('weekly_progress_reports'));
        $this->assertTrue(Schema::hasColumn('notification_deliveries', 'weekly_progress_report_id'));
    }
}
