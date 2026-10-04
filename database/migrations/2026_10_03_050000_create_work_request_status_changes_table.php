<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_request_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('work_request_id');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('estimate_version_id')->nullable();
            $table->boolean('is_free_rework')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['work_request_id', 'id']);
            $table->foreign(['company_id', 'work_request_id'])->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
            $table->foreign(['work_request_id', 'estimate_version_id'], 'status_change_estimate_identity')->references(['work_request_id', 'id'])->on('estimate_versions')->restrictOnDelete();
        });
        foreach (['from_status', 'to_status'] as $column) {
            DB::statement("ALTER TABLE work_request_status_changes ADD CONSTRAINT status_change_{$column}_check CHECK ({$column} IN ('received','estimating','awaiting_approval','queued','in_progress','awaiting_review','completed','on_hold','cancelled'))");
        }
        DB::statement("ALTER TABLE work_request_status_changes ADD CONSTRAINT status_change_reason_check CHECK (NOT (to_status IN ('on_hold','cancelled') OR from_status IN ('on_hold','completed') OR (from_status = 'awaiting_approval' AND to_status = 'estimating') OR (from_status = 'awaiting_review' AND to_status = 'in_progress') OR (from_status = 'in_progress' AND to_status = 'queued')) OR NULLIF(TRIM(reason), '') IS NOT NULL)");
        DB::statement("ALTER TABLE work_request_status_changes ADD CONSTRAINT status_change_rework_check CHECK (from_status <> to_status AND (from_status <> 'completed' OR (to_status = 'in_progress' AND is_free_rework = 1)))");
        DB::unprepared("CREATE TRIGGER status_change_update_guard BEFORE UPDATE ON work_request_status_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status history is immutable'");
        DB::unprepared("CREATE TRIGGER status_change_delete_guard BEFORE DELETE ON work_request_status_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status history cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('work_request_status_changes');
    }
};
