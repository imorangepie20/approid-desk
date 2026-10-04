<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('major_incident_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('work_request_id');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('summary', 255);
            $table->text('details');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['work_request_id', 'occurred_at', 'id'], 'major_incident_event_timeline');
            $table->foreign(['company_id', 'work_request_id'], 'major_incident_event_request_foreign')
                ->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE major_incident_events ADD COLUMN first_response_marker TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN event_type = 'first_response' THEN 1 ELSE NULL END) STORED");
        DB::statement('ALTER TABLE major_incident_events ADD CONSTRAINT major_incident_first_response_unique UNIQUE (work_request_id, first_response_marker)');
        DB::statement("ALTER TABLE major_incident_events ADD CONSTRAINT major_incident_event_type_check CHECK (event_type IN ('first_response','response_update','customer_consultation','recovery_confirmation'))");
        DB::statement("ALTER TABLE major_incident_events ADD CONSTRAINT major_incident_event_summary_check CHECK (NULLIF(TRIM(summary), '') IS NOT NULL)");
        DB::statement("ALTER TABLE major_incident_events ADD CONSTRAINT major_incident_event_details_check CHECK (NULLIF(TRIM(details), '') IS NOT NULL)");
        DB::unprepared("CREATE TRIGGER major_incident_events_insert_guard BEFORE INSERT ON major_incident_events FOR EACH ROW BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM work_requests
                WHERE id = NEW.work_request_id
                  AND company_id = NEW.company_id
                  AND is_urgent = 1
                  AND status NOT IN ('completed', 'cancelled')
                  AND NEW.occurred_at >= requested_at
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Major incident history requires an open incident and valid occurrence time';
            END IF;
            IF NOT EXISTS (
                SELECT 1 FROM users
                WHERE id = NEW.recorded_by
                  AND is_active = 1
                  AND role IN ('super_admin', 'operator')
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Major incident history requires an active system user';
            END IF;
        END");
        DB::unprepared("CREATE TRIGGER major_incident_events_update_guard BEFORE UPDATE ON major_incident_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Major incident history is immutable'");
        DB::unprepared("CREATE TRIGGER major_incident_events_delete_guard BEFORE DELETE ON major_incident_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Major incident history cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS major_incident_events_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS major_incident_events_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS major_incident_events_delete_guard');
        Schema::dropIfExists('major_incident_events');
    }
};
