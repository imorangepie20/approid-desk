<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->json('delivery_data')->nullable()->after('channel');
            $table->string('status', 32)->default('pending')->after('delivery_data');
            $table->unsignedSmallInteger('attempt_count')->default(0)->after('status');
            $table->timestamp('last_attempted_at')->nullable()->after('attempt_count');
            $table->timestamp('failed_at')->nullable()->after('last_attempted_at');
            $table->string('failure_code', 64)->nullable()->after('failed_at');
            $table->index(['status', 'failed_at'], 'notification_delivery_status_failed_index');
        });

        DB::table('notification_deliveries')->whereNotNull('sent_at')->update(['status' => 'sent']);
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_delivery_status CHECK (status IN ('pending', 'sent', 'failed'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_delivery_failure_code CHECK (failure_code IS NULL OR failure_code IN ('timeout', 'attempts_exhausted', 'mail_transport', 'database', 'unexpected'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_delivery_state CHECK ((status = 'sent' AND sent_at IS NOT NULL) OR (status IN ('pending', 'failed') AND sent_at IS NULL))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_delivery_failed_state CHECK (status <> 'failed' OR (failed_at IS NOT NULL AND failure_code IS NOT NULL))");

        Schema::create('notification_delivery_retries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_delivery_id');
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at');
            $table->timestamps();
            $table->index(['notification_delivery_id', 'requested_at'], 'notification_retry_delivery_requested_index');
            $table->foreign('notification_delivery_id', 'notification_retry_delivery_foreign')
                ->references('id')->on('notification_deliveries')->restrictOnDelete();
            $table->foreign('requested_by', 'notification_retry_requester_foreign')
                ->references('id')->on('users')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER notification_delivery_retries_update_guard BEFORE UPDATE ON notification_delivery_retries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification delivery retries are immutable'");
        DB::unprepared("CREATE TRIGGER notification_delivery_retries_delete_guard BEFORE DELETE ON notification_delivery_retries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification delivery retries cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS notification_delivery_retries_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS notification_delivery_retries_delete_guard');
        Schema::dropIfExists('notification_delivery_retries');

        DB::statement('ALTER TABLE notification_deliveries DROP CHECK notification_delivery_failed_state');
        DB::statement('ALTER TABLE notification_deliveries DROP CHECK notification_delivery_state');
        DB::statement('ALTER TABLE notification_deliveries DROP CHECK notification_delivery_failure_code');
        DB::statement('ALTER TABLE notification_deliveries DROP CHECK notification_delivery_status');
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('notification_delivery_status_failed_index');
            $table->dropColumn([
                'delivery_data',
                'status',
                'attempt_count',
                'last_attempted_at',
                'failed_at',
                'failure_code',
            ]);
        });
    }
};
