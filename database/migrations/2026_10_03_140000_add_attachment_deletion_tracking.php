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
            $table->string('deletion_status', 16)->default('active')->after('scanned_at');
            $table->uuid('deletion_attempt_id')->nullable()->after('deletion_status');
            $table->foreignId('deletion_requested_by')->nullable()->after('deletion_attempt_id')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('deletion_requested_at')->nullable()->after('deletion_requested_by');
            $table->timestamp('deleted_at')->nullable()->after('deletion_requested_at');
            $table->index(['deletion_status', 'id']);
        });
        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachment_deletion_state CHECK (
            (deletion_status = 'active' AND deletion_attempt_id IS NULL AND deletion_requested_by IS NULL
                AND deletion_requested_at IS NULL AND deleted_at IS NULL)
            OR (deletion_status IN ('pending', 'failed') AND deletion_attempt_id IS NOT NULL
                AND deletion_requested_by IS NOT NULL AND deletion_requested_at IS NOT NULL AND deleted_at IS NULL)
            OR (deletion_status = 'deleted' AND deletion_attempt_id IS NOT NULL
                AND deletion_requested_by IS NOT NULL AND deletion_requested_at IS NOT NULL AND deleted_at IS NOT NULL))");

        Schema::create('attachment_deletion_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('attachment_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('attempt_id');
            $table->string('event_type', 16);
            $table->text('reason')->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['attachment_id', 'id']);
            $table->unique(['attempt_id', 'event_type'], 'attachment_deletion_attempt_event');
            $table->foreign(['company_id', 'attachment_id'], 'attachment_deletion_identity')
                ->references(['company_id', 'id'])->on('attachments')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE attachment_deletion_events ADD CONSTRAINT attachment_deletion_event_values CHECK (
            (event_type = 'requested' AND NULLIF(TRIM(reason), '') IS NOT NULL AND failure_code IS NULL)
            OR (event_type = 'succeeded' AND reason IS NULL AND failure_code IS NULL)
            OR (event_type = 'failed' AND reason IS NULL
                AND failure_code IN ('storage_unavailable', 'storage_delete_failed', 'interrupted')))");
        DB::unprepared("CREATE TRIGGER attachment_deletion_event_update_guard BEFORE UPDATE ON attachment_deletion_events
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment deletion history is immutable'");
        DB::unprepared("CREATE TRIGGER attachment_deletion_event_delete_guard BEFORE DELETE ON attachment_deletion_events
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment deletion history cannot be deleted'");
        DB::unprepared("CREATE TRIGGER attachment_delete_guard BEFORE DELETE ON attachments
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment records cannot be deleted'");
        DB::unprepared("CREATE TRIGGER attachment_deletion_update_guard BEFORE UPDATE ON attachments FOR EACH ROW BEGIN
            DECLARE submitted TIMESTAMP DEFAULT NULL;
            IF OLD.deletion_status = 'deleted' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Deleted attachment state is immutable';
            ELSEIF OLD.deletion_status = NEW.deletion_status THEN
                IF OLD.deletion_status = 'pending' AND NOT (BINARY OLD.deletion_attempt_id <=> BINARY NEW.deletion_attempt_id) THEN
                    IF NEW.deletion_attempt_id IS NULL OR NEW.deletion_requested_by IS NULL
                        OR NEW.deletion_requested_at IS NULL OR NEW.deleted_at IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid attachment deletion retry';
                    END IF;
                ELSEIF NOT (BINARY OLD.deletion_attempt_id <=> BINARY NEW.deletion_attempt_id)
                    OR NOT (OLD.deletion_requested_by <=> NEW.deletion_requested_by)
                    OR NOT (OLD.deletion_requested_at <=> NEW.deletion_requested_at)
                    OR NOT (OLD.deleted_at <=> NEW.deleted_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment deletion evidence is immutable';
                END IF;
            ELSEIF OLD.deletion_status = 'active' AND NEW.deletion_status = 'pending' THEN
                SET submitted = NULL;
            ELSEIF OLD.deletion_status = 'pending' AND NEW.deletion_status IN ('deleted', 'failed') THEN
                IF NOT (BINARY OLD.deletion_attempt_id <=> BINARY NEW.deletion_attempt_id)
                    OR NOT (OLD.deletion_requested_by <=> NEW.deletion_requested_by)
                    OR NOT (OLD.deletion_requested_at <=> NEW.deletion_requested_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment deletion attempt changed';
                END IF;
            ELSEIF OLD.deletion_status = 'failed' AND NEW.deletion_status = 'pending' THEN
                IF BINARY OLD.deletion_attempt_id = BINARY NEW.deletion_attempt_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment deletion retry requires a new attempt';
                END IF;
            ELSE
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid attachment deletion transition';
            END IF;
            IF NEW.deletion_status = 'pending'
                AND (OLD.deletion_status <> 'pending'
                    OR NOT (BINARY OLD.deletion_attempt_id <=> BINARY NEW.deletion_attempt_id))
                AND NEW.estimate_version_id IS NOT NULL THEN
                SELECT submitted_at INTO submitted FROM estimate_versions WHERE id = NEW.estimate_version_id FOR UPDATE;
                IF submitted IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted estimate attachments are immutable';
                END IF;
            END IF;
        END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_deletion_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_deletion_event_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_deletion_event_update_guard');
        DB::statement('ALTER TABLE attachment_deletion_events DROP CHECK attachment_deletion_event_values');
        Schema::dropIfExists('attachment_deletion_events');
        DB::statement('ALTER TABLE attachments DROP CHECK attachment_deletion_state');
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropIndex(['deletion_status', 'id']);
            $table->dropConstrainedForeignId('deletion_requested_by');
            $table->dropColumn(['deletion_status', 'deletion_attempt_id', 'deletion_requested_at', 'deleted_at']);
        });
    }
};
