<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('event_key', 64);
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('work_request_id');
            $table->string('notification_type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->string('channel', 32);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['event_key', 'notifiable_type', 'notifiable_id', 'channel'],
                'notification_delivery_event_recipient_channel_unique',
            );
            $table->index(['company_id', 'sent_at'], 'notification_delivery_company_sent_index');
            $table->foreign(['company_id', 'work_request_id'])
                ->references(['company_id', 'id'])
                ->on('work_requests')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_delivery_channel CHECK (channel IN ('database', 'mail'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
