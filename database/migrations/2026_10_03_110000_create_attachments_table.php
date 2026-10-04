<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_request_comments', function (Blueprint $table): void {
            $table->unique(['work_request_id', 'id'], 'comment_attachment_identity');
        });
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('work_request_id');
            $table->unsignedBigInteger('work_request_comment_id')->nullable();
            $table->unsignedBigInteger('estimate_version_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['work_request_id', 'created_at']);
            $table->foreign(['company_id', 'work_request_id'], 'attachment_request_identity')
                ->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
            $table->foreign(['work_request_id', 'work_request_comment_id'], 'attachment_comment_identity')
                ->references(['work_request_id', 'id'])->on('work_request_comments')->restrictOnDelete();
            $table->foreign(['work_request_id', 'estimate_version_id'], 'attachment_estimate_identity')
                ->references(['work_request_id', 'id'])->on('estimate_versions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachment_single_target CHECK (work_request_comment_id IS NULL OR estimate_version_id IS NULL)');
        DB::unprepared("CREATE TRIGGER attachment_identity_update BEFORE UPDATE ON attachments FOR EACH ROW BEGIN
            IF OLD.company_id <> NEW.company_id OR OLD.work_request_id <> NEW.work_request_id
                OR NOT (OLD.work_request_comment_id <=> NEW.work_request_comment_id)
                OR NOT (OLD.estimate_version_id <=> NEW.estimate_version_id)
            THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment links are immutable'; END IF;
        END");
        foreach (['insert' => 'NEW', 'delete' => 'OLD'] as $event => $row) {
            DB::unprepared("CREATE TRIGGER attachment_estimate_{$event} BEFORE {$event} ON attachments FOR EACH ROW BEGIN
                DECLARE submitted TIMESTAMP DEFAULT NULL;
                IF {$row}.estimate_version_id IS NOT NULL THEN
                    SELECT submitted_at INTO submitted FROM estimate_versions WHERE id = {$row}.estimate_version_id FOR UPDATE;
                    IF submitted IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted estimate attachments are immutable'; END IF;
                END IF;
            END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::table('work_request_comments', function (Blueprint $table): void {
            $table->dropUnique('comment_attachment_identity');
        });
    }
};
