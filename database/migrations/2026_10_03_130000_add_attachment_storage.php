<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('storage_disk', 64)->nullable()->after('mime_type');
            $table->string('storage_path')->nullable()->after('storage_disk');
            $table->unique(['storage_disk', 'storage_path'], 'attachment_storage_identity');
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachment_storage_complete CHECK (
            (storage_disk IS NULL AND storage_path IS NULL)
            OR (storage_disk IS NOT NULL AND CHAR_LENGTH(TRIM(storage_disk)) > 0
                AND storage_path IS NOT NULL AND CHAR_LENGTH(TRIM(storage_path)) > 0 AND uploaded_by IS NOT NULL))');
        DB::unprepared('DROP TRIGGER attachment_scan_insert');
        DB::unprepared("CREATE TRIGGER attachment_scan_insert BEFORE INSERT ON attachments FOR EACH ROW BEGIN
            IF NEW.scan_status <> 'pending' OR NEW.scanned_at IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachments must start pending';
            END IF;
            IF NEW.uploaded_by IS NOT NULL AND (NEW.storage_disk IS NULL OR NEW.storage_path IS NULL) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New attachment metadata requires stored content';
            END IF;
        END");
        DB::unprepared('DROP TRIGGER attachment_metadata_update');
        DB::unprepared("CREATE TRIGGER attachment_metadata_update BEFORE UPDATE ON attachments FOR EACH ROW BEGIN
            DECLARE submitted TIMESTAMP DEFAULT NULL;
            IF OLD.uploaded_by IS NOT NULL AND (
                NOT (BINARY OLD.original_name <=> BINARY NEW.original_name) OR NOT (OLD.size_bytes <=> NEW.size_bytes)
                OR NOT (BINARY OLD.mime_type <=> BINARY NEW.mime_type)
                OR NOT (BINARY OLD.storage_disk <=> BINARY NEW.storage_disk)
                OR NOT (BINARY OLD.storage_path <=> BINARY NEW.storage_path)
                OR NOT (OLD.uploaded_by <=> NEW.uploaded_by) OR NOT (OLD.uploaded_at <=> NEW.uploaded_at)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment file metadata is immutable';
            END IF;
            IF OLD.uploaded_by IS NULL AND NEW.uploaded_by IS NOT NULL AND NEW.estimate_version_id IS NOT NULL THEN
                SELECT submitted_at INTO submitted FROM estimate_versions WHERE id = NEW.estimate_version_id FOR UPDATE;
                IF submitted IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted estimate attachments are immutable';
                END IF;
            END IF;
            IF OLD.uploaded_by IS NULL AND NEW.uploaded_by IS NOT NULL
                AND (NEW.storage_disk IS NULL OR NEW.storage_path IS NULL) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New attachment metadata requires stored content';
            END IF;
        END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER attachment_scan_insert');
        DB::unprepared("CREATE TRIGGER attachment_scan_insert BEFORE INSERT ON attachments FOR EACH ROW BEGIN
            IF NEW.scan_status <> 'pending' OR NEW.scanned_at IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachments must start pending';
            END IF;
        END");
        DB::unprepared('DROP TRIGGER attachment_metadata_update');
        DB::unprepared("CREATE TRIGGER attachment_metadata_update BEFORE UPDATE ON attachments FOR EACH ROW BEGIN
            DECLARE submitted TIMESTAMP DEFAULT NULL;
            IF OLD.uploaded_by IS NOT NULL AND (
                NOT (BINARY OLD.original_name <=> BINARY NEW.original_name) OR NOT (OLD.size_bytes <=> NEW.size_bytes)
                OR NOT (BINARY OLD.mime_type <=> BINARY NEW.mime_type) OR NOT (OLD.uploaded_by <=> NEW.uploaded_by)
                OR NOT (OLD.uploaded_at <=> NEW.uploaded_at)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment file metadata is immutable';
            END IF;
            IF OLD.uploaded_by IS NULL AND NEW.uploaded_by IS NOT NULL AND NEW.estimate_version_id IS NOT NULL THEN
                SELECT submitted_at INTO submitted FROM estimate_versions WHERE id = NEW.estimate_version_id FOR UPDATE;
                IF submitted IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted estimate attachments are immutable';
                END IF;
            END IF;
        END");
        DB::statement('ALTER TABLE attachments DROP CHECK attachment_storage_complete');
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropUnique('attachment_storage_identity');
            $table->dropColumn(['storage_disk', 'storage_path']);
        });
    }
};
