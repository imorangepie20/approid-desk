<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_progress_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('open_request_count');
            $table->unsignedInteger('new_request_count');
            $table->unsignedInteger('changed_request_count');
            $table->unsignedInteger('completed_request_count');
            $table->json('status_counts');
            $table->unsignedInteger('recipient_count');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['company_id', 'period_start'], 'weekly_report_company_period_unique');
            $table->unique(['company_id', 'id'], 'weekly_report_company_identity_unique');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE weekly_progress_reports ADD CONSTRAINT weekly_report_period CHECK (period_end = DATE_ADD(period_start, INTERVAL 6 DAY) AND DAYOFWEEK(period_start) = 2)');
        DB::statement('ALTER TABLE weekly_progress_reports ADD CONSTRAINT weekly_report_values CHECK (open_request_count <= 10000000 AND new_request_count <= 10000000 AND changed_request_count <= 10000000 AND completed_request_count <= 10000000 AND recipient_count BETWEEN 1 AND 10000000)');
        DB::unprepared("CREATE TRIGGER weekly_progress_reports_update_guard BEFORE UPDATE ON weekly_progress_reports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Weekly progress reports are immutable'");
        DB::unprepared("CREATE TRIGGER weekly_progress_reports_delete_guard BEFORE DELETE ON weekly_progress_reports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Weekly progress reports cannot be deleted'");

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropForeign('notification_deliveries_company_id_work_request_id_foreign');
            $table->unsignedBigInteger('work_request_id')->nullable()->change();
            $table->unsignedBigInteger('weekly_progress_report_id')->nullable()->after('work_request_id');
            $table->foreign(['company_id', 'work_request_id'], 'notification_delivery_request_foreign')
                ->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'weekly_progress_report_id'], 'notification_delivery_weekly_report_foreign')
                ->references(['company_id', 'id'])->on('weekly_progress_reports')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_delivery_scope CHECK ((notification_type = 'weekly_progress_report' AND work_request_id IS NULL AND weekly_progress_report_id IS NOT NULL) OR (notification_type <> 'weekly_progress_report' AND work_request_id IS NOT NULL AND weekly_progress_report_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_deliveries DROP CHECK notification_delivery_scope');
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropForeign('notification_delivery_weekly_report_foreign');
            $table->dropForeign('notification_delivery_request_foreign');
        });

        $weeklyDeliveryIds = DB::table('notification_deliveries')
            ->whereNotNull('weekly_progress_report_id')->pluck('id');
        if ($weeklyDeliveryIds->isNotEmpty()) {
            DB::unprepared('DROP TRIGGER IF EXISTS notification_delivery_retries_delete_guard');
            DB::table('notification_delivery_retries')->whereIn('notification_delivery_id', $weeklyDeliveryIds)->delete();
            DB::unprepared("CREATE TRIGGER notification_delivery_retries_delete_guard BEFORE DELETE ON notification_delivery_retries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification delivery retries cannot be deleted'");
            DB::table('notifications')->whereIn('id', $weeklyDeliveryIds)->delete();
            DB::table('notification_deliveries')->whereIn('id', $weeklyDeliveryIds)->delete();
        }

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn('weekly_progress_report_id');
            $table->unsignedBigInteger('work_request_id')->nullable(false)->change();
            $table->foreign(['company_id', 'work_request_id'])
                ->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
        });

        DB::unprepared('DROP TRIGGER IF EXISTS weekly_progress_reports_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS weekly_progress_reports_delete_guard');
        Schema::dropIfExists('weekly_progress_reports');
    }
};
