<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('month_transition_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_month_id');
            $table->unsignedBigInteger('work_request_id');
            $table->unsignedBigInteger('reserve_entry_id');
            $table->unsignedInteger('remaining_minutes');
            $table->date('detected_for');
            $table->unsignedInteger('recipient_count');
            $table->timestamp('notified_at');
            $table->timestamps();
            $table->unique(['work_request_id', 'contract_month_id', 'reserve_entry_id'], 'month_transition_notice_unique');
            $table->foreign(['company_id', 'contract_month_id'])->references(['company_id', 'id'])->on('contract_months')->restrictOnDelete();
            $table->foreign(['company_id', 'work_request_id'])->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'contract_month_id', 'reserve_entry_id'], 'transition_notice_reserve')
                ->references(['company_id', 'contract_month_id', 'id'])->on('time_ledger_entries')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE month_transition_notices ADD CONSTRAINT month_transition_notice_values CHECK (remaining_minutes BETWEEN 1 AND 10000000 AND recipient_count > 0)');
        DB::unprepared("CREATE TRIGGER month_transition_notices_insert_guard BEFORE INSERT ON month_transition_notices FOR EACH ROW BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM time_ledger_entries ledger
                WHERE ledger.id = NEW.reserve_entry_id AND ledger.company_id = NEW.company_id
                AND ledger.contract_month_id = NEW.contract_month_id
                AND ledger.work_request_id = NEW.work_request_id AND ledger.type = 'reserve'
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Month transition notice requires its matching reservation';
            END IF;
        END");
        DB::unprepared("CREATE TRIGGER month_transition_notices_update_guard BEFORE UPDATE ON month_transition_notices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Month transition notices are immutable'");
        DB::unprepared("CREATE TRIGGER month_transition_notices_delete_guard BEFORE DELETE ON month_transition_notices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Month transition notices cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS month_transition_notices_insert_guard');
        Schema::dropIfExists('month_transition_notices');
        Schema::dropIfExists('notifications');
    }
};
