<?php

namespace Tests\Feature\Models;

use App\Actions\CreateAttachmentLink;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttachmentMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_attachment_migration_can_be_rolled_back_and_reapplied(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        $migration = require database_path('migrations/2026_10_03_110000_create_attachments_table.php');
        $metadata = require database_path('migrations/2026_10_03_120000_add_attachment_metadata.php');
        $storage = require database_path('migrations/2026_10_03_130000_add_attachment_storage.php');
        $deletion = require database_path('migrations/2026_10_03_140000_add_attachment_deletion_tracking.php');
        $scanning = require database_path('migrations/2026_10_03_150000_add_attachment_malware_scanning.php');
        $this->assertTrue(Schema::hasTable('attachments'));
        $scanning->down();
        $deletion->down();
        $storage->down();
        $metadata->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('attachments'));
        $this->assertFalse(Schema::hasIndex('work_request_comments', 'comment_attachment_identity'));
        $this->assertTrue(Schema::hasTable('work_request_comments'));
        $this->assertTrue(Schema::hasTable('estimate_versions'));
        $migration->up();
        $metadata->up();
        $storage->up();
        $deletion->up();
        $scanning->up();
        $this->assertTrue(Schema::hasTable('attachments'));
        $this->assertTrue(Schema::hasIndex('work_request_comments', 'comment_attachment_identity'));
    }

    public function test_metadata_migration_preserves_legacy_links_without_inventing_file_information(): void
    {
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($request->submitter, $request);
        $migration = require database_path('migrations/2026_10_03_120000_add_attachment_metadata.php');
        $storage = require database_path('migrations/2026_10_03_130000_add_attachment_storage.php');
        $deletion = require database_path('migrations/2026_10_03_140000_add_attachment_deletion_tracking.php');
        $scanning = require database_path('migrations/2026_10_03_150000_add_attachment_malware_scanning.php');
        $scanning->down();
        $deletion->down();
        $storage->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('attachments', 'uploaded_by'));
        $this->assertDatabaseHas('attachments', ['id' => $link->id, 'work_request_id' => $request->id]);
        $migration->up();
        $storage->up();
        $deletion->up();
        $scanning->up();
        $this->assertDatabaseHas('attachments', ['id' => $link->id, 'original_name' => null,
            'size_bytes' => null, 'mime_type' => null, 'uploaded_by' => null, 'uploaded_at' => null,
            'scan_status' => 'pending', 'scanned_at' => null, 'storage_disk' => null, 'storage_path' => null]);
    }

    public function test_storage_migration_preserves_existing_metadata_without_inventing_a_file(): void
    {
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($request->submitter, $request);
        $storage = require database_path('migrations/2026_10_03_130000_add_attachment_storage.php');
        $deletion = require database_path('migrations/2026_10_03_140000_add_attachment_deletion_tracking.php');
        $scanning = require database_path('migrations/2026_10_03_150000_add_attachment_malware_scanning.php');
        $scanning->down();
        $deletion->down();
        $storage->down();
        $link->forceFill(['original_name' => 'historical.txt', 'size_bytes' => 10, 'mime_type' => 'text/plain',
            'uploaded_by' => $request->submitted_by, 'uploaded_at' => now()])->save();
        $storage->up();
        $deletion->up();
        $scanning->up();
        $this->assertDatabaseHas('attachments', ['id' => $link->id, 'original_name' => 'historical.txt',
            'storage_disk' => null, 'storage_path' => null]);
    }

    public function test_deletion_migration_preserves_existing_files_as_active_without_inventing_history(): void
    {
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($request->submitter, $request);
        $deletion = require database_path('migrations/2026_10_03_140000_add_attachment_deletion_tracking.php');
        $scanning = require database_path('migrations/2026_10_03_150000_add_attachment_malware_scanning.php');
        $scanning->down();
        $deletion->down();
        $this->assertFalse(Schema::hasColumn('attachments', 'deletion_status'));
        $this->assertFalse(Schema::hasTable('attachment_deletion_events'));
        $this->assertDatabaseHas('attachments', ['id' => $link->id]);
        $deletion->up();
        $scanning->up();
        $this->assertDatabaseHas('attachments', ['id' => $link->id, 'deletion_status' => 'active',
            'deletion_attempt_id' => null, 'deletion_requested_by' => null,
            'deletion_requested_at' => null, 'deleted_at' => null]);
        $this->assertDatabaseCount('attachment_deletion_events', 0);
    }

    public function test_scanning_migration_preserves_pending_files_without_inventing_history(): void
    {
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($request->submitter, $request);
        $scanning = require database_path('migrations/2026_10_03_150000_add_attachment_malware_scanning.php');
        $scanning->down();
        $this->assertFalse(Schema::hasColumn('attachments', 'scan_attempt_id'));
        $this->assertFalse(Schema::hasTable('attachment_scan_events'));
        $scanning->up();
        $this->assertDatabaseHas('attachments', ['id' => $link->id, 'scan_status' => 'pending',
            'scan_attempt_id' => null, 'scan_requested_at' => null, 'scanned_at' => null]);
        $this->assertDatabaseCount('attachment_scan_events', 0);
    }
}
