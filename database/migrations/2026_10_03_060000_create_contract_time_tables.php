<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_months', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('service_contract_id');
            $table->date('month');
            $table->unsignedInteger('provided_minutes');
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['service_contract_id', 'month']);
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'service_contract_id'])->references(['company_id', 'id'])->on('service_contracts')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE contract_months ADD CONSTRAINT contract_month_values CHECK (DAYOFMONTH(month) = 1 AND provided_minutes BETWEEN 1 AND 10000000 AND ((status = 'open' AND closed_at IS NULL AND closed_by IS NULL) OR (status = 'closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL)))");
        DB::unprepared("CREATE TRIGGER contract_month_identity_guard BEFORE UPDATE ON contract_months FOR EACH ROW BEGIN IF OLD.company_id <> NEW.company_id OR OLD.service_contract_id <> NEW.service_contract_id OR OLD.month <> NEW.month OR OLD.provided_minutes <> NEW.provided_minutes OR OLD.status = 'closed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Contract month identity and closed months are immutable'; END IF; END");

        Schema::create('time_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_month_id');
            $table->unsignedBigInteger('work_request_id')->nullable();
            $table->string('type', 32);
            $table->unsignedInteger('minutes');
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'type'], 'ledger_source_type_unique');
            $table->foreign(['company_id', 'contract_month_id'])->references(['company_id', 'id'])->on('contract_months')->restrictOnDelete();
            $table->foreign(['company_id', 'work_request_id'])->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE time_ledger_entries ADD CONSTRAINT ledger_values CHECK (minutes BETWEEN 1 AND 10000000 AND source_id > 0 AND CHAR_LENGTH(TRIM(source_type)) > 0 AND CHAR_LENGTH(TRIM(reason)) > 0 AND type IN ('provided', 'reserve', 'release', 'usage', 'cancel_usage', 'adjust_increase', 'adjust_decrease') AND (type NOT IN ('reserve', 'release', 'usage', 'cancel_usage') OR work_request_id IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER ledger_update_guard BEFORE UPDATE ON time_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time ledger is immutable'");
        DB::unprepared("CREATE TRIGGER ledger_delete_guard BEFORE DELETE ON time_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Time ledger cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('time_ledger_entries');
        Schema::dropIfExists('contract_months');
    }
};
