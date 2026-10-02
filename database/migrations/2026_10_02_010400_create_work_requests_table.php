<?php

use App\Enums\IntakeChannel;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unique(['company_id', 'id']);
        });

        Schema::create('work_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('parent_request_id')->nullable();
            $table->string('title');
            $table->longText('requirements');
            $table->string('type', 32);
            $table->string('priority', 32)->default(WorkRequestPriority::Normal->value);
            $table->boolean('is_urgent')->default(false);
            $table->date('desired_due_date')->nullable();
            $table->string('intake_channel', 32)->default(IntakeChannel::Web->value);
            $table->string('source_reference', 2048)->nullable();
            $table->text('intake_summary')->nullable();
            $table->string('status', 32)->default(WorkRequestStatus::Received->value);
            $table->timestamp('requested_at');
            $table->timestamp('registered_at')->useCurrent();
            $table->text('late_entry_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status', 'is_urgent', 'priority']);
            $table->index(['project_id', 'status']);
            $table->index(['parent_request_id']);

            $table->foreign(['company_id', 'project_id'])
                ->references(['company_id', 'id'])
                ->on('projects')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'parent_request_id'])
                ->references(['company_id', 'id'])
                ->on('work_requests')
                ->restrictOnDelete();
        });

        DB::statement(sprintf(
            "ALTER TABLE work_requests ADD CONSTRAINT work_requests_type_check CHECK (type IN ('%s', '%s', '%s', '%s', '%s'))",
            WorkRequestType::Feature->value,
            WorkRequestType::BugFix->value,
            WorkRequestType::Maintenance->value,
            WorkRequestType::Consultation->value,
            WorkRequestType::Other->value,
        ));
        DB::statement(sprintf(
            "ALTER TABLE work_requests ADD CONSTRAINT work_requests_priority_check CHECK (priority IN ('%s', '%s', '%s'))",
            WorkRequestPriority::Low->value,
            WorkRequestPriority::Normal->value,
            WorkRequestPriority::High->value,
        ));
        DB::statement(sprintf(
            "ALTER TABLE work_requests ADD CONSTRAINT work_requests_intake_channel_check CHECK (intake_channel IN ('%s', '%s', '%s', '%s', '%s'))",
            IntakeChannel::Web->value,
            IntakeChannel::Phone->value,
            IntakeChannel::Email->value,
            IntakeChannel::Messenger->value,
            IntakeChannel::Other->value,
        ));
        DB::statement(sprintf(
            "ALTER TABLE work_requests ADD CONSTRAINT work_requests_status_check CHECK (status IN ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'))",
            WorkRequestStatus::Received->value,
            WorkRequestStatus::Estimating->value,
            WorkRequestStatus::AwaitingApproval->value,
            WorkRequestStatus::Queued->value,
            WorkRequestStatus::InProgress->value,
            WorkRequestStatus::AwaitingReview->value,
            WorkRequestStatus::Completed->value,
            WorkRequestStatus::OnHold->value,
            WorkRequestStatus::Cancelled->value,
        ));
        DB::statement(
            "ALTER TABLE work_requests ADD CONSTRAINT work_requests_manual_intake_details_check CHECK (intake_channel = 'web' OR (NULLIF(TRIM(source_reference), '') IS NOT NULL AND NULLIF(TRIM(intake_summary), '') IS NOT NULL))",
        );
        DB::statement(
            "ALTER TABLE work_requests ADD CONSTRAINT work_requests_request_time_check CHECK (requested_at <= registered_at AND (DATE(requested_at) = DATE(registered_at) OR NULLIF(TRIM(late_entry_reason), '') IS NOT NULL))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('work_requests');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'id']);
        });
    }
};
