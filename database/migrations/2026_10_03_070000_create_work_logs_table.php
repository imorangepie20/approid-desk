<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('work_request_id');
            $table->foreignId('worker_id')->constrained('users')->restrictOnDelete();
            $table->date('worked_on');
            $table->text('description');
            $table->unsignedInteger('minutes');
            $table->boolean('is_billable');
            $table->text('non_billable_reason')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'worked_on']);
            $table->index(['work_request_id', 'status']);
            $table->foreign(['company_id', 'work_request_id'])->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE work_logs ADD CONSTRAINT work_log_values CHECK (minutes BETWEEN 1 AND 1440 AND revision > 0 AND CHAR_LENGTH(TRIM(description)) > 0 AND is_billable IN (0, 1) AND ((is_billable = 1 AND non_billable_reason IS NULL) OR (is_billable = 0 AND non_billable_reason IS NOT NULL AND CHAR_LENGTH(TRIM(non_billable_reason)) > 0)) AND ((status = 'draft' AND confirmed_at IS NULL) OR (status = 'confirmed' AND confirmed_at IS NOT NULL)))");
        DB::unprepared("CREATE TRIGGER work_log_update_guard BEFORE UPDATE ON work_logs FOR EACH ROW BEGIN IF OLD.status = 'confirmed' OR OLD.company_id <> NEW.company_id OR OLD.work_request_id <> NEW.work_request_id OR OLD.worker_id <> NEW.worker_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Confirmed work logs and work log identity are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER work_log_delete_guard BEFORE DELETE ON work_logs FOR EACH ROW BEGIN IF OLD.status = 'confirmed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Confirmed work logs cannot be deleted'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('work_logs');
    }
};
