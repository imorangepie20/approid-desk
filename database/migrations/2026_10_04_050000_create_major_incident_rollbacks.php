<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('major_incident_rollbacks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('work_request_id');
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->string('target', 255);
            $table->text('plan');
            $table->text('verification_plan');
            $table->timestamp('started_at');
            $table->string('outcome', 16)->nullable();
            $table->string('result_summary', 255)->nullable();
            $table->text('result_details')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['work_request_id', 'started_at', 'id'], 'major_incident_rollback_timeline');
            $table->foreign('completed_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'work_request_id'], 'major_incident_rollback_request_foreign')
                ->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE major_incident_rollbacks ADD COLUMN active_marker TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN outcome IS NULL THEN 1 ELSE NULL END) STORED');
        DB::statement('ALTER TABLE major_incident_rollbacks ADD CONSTRAINT major_incident_active_rollback_unique UNIQUE (work_request_id, active_marker)');
        DB::statement("ALTER TABLE major_incident_rollbacks ADD CONSTRAINT major_incident_rollback_outcome_check CHECK (outcome IS NULL OR outcome IN ('succeeded','failed','aborted'))");
        DB::statement("ALTER TABLE major_incident_rollbacks ADD CONSTRAINT major_incident_rollback_target_check CHECK (NULLIF(TRIM(target), '') IS NOT NULL)");
        DB::statement("ALTER TABLE major_incident_rollbacks ADD CONSTRAINT major_incident_rollback_plan_check CHECK (NULLIF(TRIM(plan), '') IS NOT NULL)");
        DB::statement("ALTER TABLE major_incident_rollbacks ADD CONSTRAINT major_incident_rollback_verification_check CHECK (NULLIF(TRIM(verification_plan), '') IS NOT NULL)");
        DB::statement("ALTER TABLE major_incident_rollbacks ADD CONSTRAINT major_incident_rollback_completion_check CHECK ((outcome IS NULL AND result_summary IS NULL AND result_details IS NULL AND completed_by IS NULL AND completed_at IS NULL) OR (outcome IS NOT NULL AND NULLIF(TRIM(result_summary), '') IS NOT NULL AND NULLIF(TRIM(result_details), '') IS NOT NULL AND completed_by IS NOT NULL AND completed_at IS NOT NULL AND completed_at >= started_at))");
        DB::unprepared("CREATE TRIGGER major_incident_rollbacks_insert_guard BEFORE INSERT ON major_incident_rollbacks FOR EACH ROW BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM work_requests
                WHERE id = NEW.work_request_id
                  AND company_id = NEW.company_id
                  AND is_urgent = 1
                  AND status NOT IN ('completed', 'cancelled')
                  AND NEW.started_at >= requested_at
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback requires an open incident and valid start time';
            END IF;
            IF NOT EXISTS (
                SELECT 1 FROM users
                WHERE id = NEW.started_by
                  AND is_active = 1
                  AND role IN ('super_admin', 'operator')
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback requires an active system user';
            END IF;
            IF NEW.outcome IS NOT NULL OR NEW.result_summary IS NOT NULL OR NEW.result_details IS NOT NULL OR NEW.completed_by IS NOT NULL OR NEW.completed_at IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback result must be recorded after start';
            END IF;
        END");
        DB::unprepared("CREATE TRIGGER major_incident_rollbacks_update_guard BEFORE UPDATE ON major_incident_rollbacks FOR EACH ROW BEGIN
            IF OLD.outcome IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed rollback is immutable';
            END IF;
            IF OLD.id <> NEW.id
                OR OLD.company_id <> NEW.company_id
                OR OLD.work_request_id <> NEW.work_request_id
                OR OLD.started_by <> NEW.started_by
                OR NOT (BINARY OLD.target <=> BINARY NEW.target)
                OR NOT (BINARY OLD.plan <=> BINARY NEW.plan)
                OR NOT (BINARY OLD.verification_plan <=> BINARY NEW.verification_plan)
                OR NOT (OLD.started_at <=> NEW.started_at)
                OR NOT (OLD.created_at <=> NEW.created_at)
                OR NEW.outcome IS NULL
                OR NEW.result_summary IS NULL
                OR NEW.result_details IS NULL
                OR NEW.completed_by IS NULL
                OR NEW.completed_at IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback may only transition once to a complete result';
            END IF;
            IF NOT EXISTS (
                SELECT 1 FROM users
                WHERE id = NEW.completed_by
                  AND is_active = 1
                  AND role IN ('super_admin', 'operator')
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback completion requires an active system user';
            END IF;
        END");
        DB::unprepared("CREATE TRIGGER major_incident_rollbacks_delete_guard BEFORE DELETE ON major_incident_rollbacks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback history cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS major_incident_rollbacks_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS major_incident_rollbacks_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS major_incident_rollbacks_delete_guard');
        Schema::dropIfExists('major_incident_rollbacks');
    }
};
