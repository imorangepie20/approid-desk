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
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type', 127)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('scan_status', 16)->default('pending');
            $table->timestamp('scanned_at')->nullable();
            $table->index(['scan_status', 'id']);
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachment_metadata_complete CHECK (
            (original_name IS NULL AND size_bytes IS NULL AND mime_type IS NULL AND uploaded_by IS NULL AND uploaded_at IS NULL)
            OR (original_name IS NOT NULL AND CHAR_LENGTH(TRIM(original_name)) > 0 AND size_bytes IS NOT NULL
                AND mime_type IS NOT NULL AND CHAR_LENGTH(TRIM(mime_type)) > 0 AND uploaded_by IS NOT NULL AND uploaded_at IS NOT NULL))');
        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachment_scan_state CHECK (
            (scan_status = 'pending' AND scanned_at IS NULL)
            OR (scan_status IN ('clean', 'infected', 'failed') AND scanned_at IS NOT NULL AND uploaded_by IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER attachment_scan_insert BEFORE INSERT ON attachments FOR EACH ROW BEGIN
            IF NEW.scan_status <> 'pending' OR NEW.scanned_at IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachments must start pending';
            END IF;
        END");
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
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_metadata_update');
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_scan_insert');
        DB::statement('ALTER TABLE attachments DROP CHECK attachment_scan_state');
        DB::statement('ALTER TABLE attachments DROP CHECK attachment_metadata_complete');
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropIndex(['scan_status', 'id']);
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropColumn(['original_name', 'size_bytes', 'mime_type', 'uploaded_at', 'scan_status', 'scanned_at']);
        });
    }
};
