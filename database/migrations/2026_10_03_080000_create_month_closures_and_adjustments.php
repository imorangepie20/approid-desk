<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_ledger_entries', function (Blueprint $table): void {
            $table->unique(['company_id', 'contract_month_id', 'id'], 'ledger_month_identity');
        });
        Schema::create('month_closures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_month_id')->unique();
            $table->json('totals');
            $table->unsignedBigInteger('last_entry_id');
            $table->unsignedInteger('entry_count');
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->foreign(['company_id', 'contract_month_id'])->references(['company_id', 'id'])->on('contract_months')->restrictOnDelete();
            $table->foreign(['company_id', 'contract_month_id', 'last_entry_id'], 'closure_last_entry')->references(['company_id', 'contract_month_id', 'id'])->on('time_ledger_entries')->restrictOnDelete();
        });
        Schema::create('month_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_month_id');
            $table->unsignedBigInteger('related_entry_id');
            $table->string('type', 32);
            $table->unsignedInteger('minutes');
            $table->text('reason');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->foreign(['company_id', 'contract_month_id'])->references(['company_id', 'id'])->on('contract_months')->restrictOnDelete();
            $table->foreign(['company_id', 'contract_month_id', 'related_entry_id'], 'adjustment_related_entry')->references(['company_id', 'contract_month_id', 'id'])->on('time_ledger_entries')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE month_adjustments ADD CONSTRAINT adjustment_values CHECK (type IN ('adjust_increase', 'adjust_decrease') AND minutes BETWEEN 1 AND 10000000 AND CHAR_LENGTH(TRIM(reason)) > 0)");
        foreach (['month_closures', 'month_adjustments'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Month audit records are immutable'");
            DB::unprepared("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Month audit records cannot be deleted'");
        }
        DB::unprepared("CREATE TRIGGER ledger_closed_month_guard BEFORE INSERT ON time_ledger_entries FOR EACH ROW
            BEGIN
                DECLARE month_status VARCHAR(16);
                SELECT status INTO month_status FROM contract_months WHERE id = NEW.contract_month_id FOR SHARE;
                IF month_status = 'closed' AND NEW.type NOT IN ('adjust_increase', 'adjust_decrease') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed months only accept approved adjustments';
                END IF;
                IF NEW.source_type = 'month_adjustment' OR NEW.type IN ('adjust_increase', 'adjust_decrease') THEN
                    IF NEW.source_type <> 'month_adjustment' OR NOT EXISTS (
                        SELECT 1 FROM month_adjustments a WHERE a.id = NEW.source_id AND a.company_id = NEW.company_id
                        AND a.contract_month_id = NEW.contract_month_id AND a.type = NEW.type AND a.minutes = NEW.minutes
                        AND a.approved_by = NEW.actor_id AND a.reason = NEW.reason
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Matching approved adjustment required';
                    END IF;
                END IF;
            END");
        foreach (['INSERT' => ['NEW'], 'UPDATE' => ['OLD', 'NEW'], 'DELETE' => ['OLD']] as $event => $references) {
            $checks = '';
            foreach ($references as $reference) {
                $checks .= "SET period_status = NULL;
                    SELECT status INTO period_status FROM contract_months
                    WHERE service_contract_id = (SELECT service_contract_id FROM work_requests WHERE id = {$reference}.work_request_id)
                    AND month = DATE_FORMAT({$reference}.worked_on, '%Y-%m-01') LIMIT 1 FOR SHARE;
                    IF period_status = 'closed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed period work logs cannot be changed'; END IF;";
            }
            $name = 'work_log_closed_'.strtolower($event);
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON work_logs FOR EACH ROW BEGIN
                DECLARE period_status VARCHAR(16) DEFAULT NULL;
                DECLARE CONTINUE HANDLER FOR NOT FOUND SET period_status = NULL;
                {$checks} END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_closed_month_guard');
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS work_log_closed_{$event}");
        }
        Schema::dropIfExists('month_adjustments');
        Schema::dropIfExists('month_closures');
        // InnoDB can replace the original implicit FK index with our wider index.
        // Restore its support before removing the wider index on rollback.
        if (! Schema::hasIndex('time_ledger_entries', ['company_id', 'contract_month_id'])) {
            Schema::table('time_ledger_entries', function (Blueprint $table): void {
                $table->index(['company_id', 'contract_month_id'], 'time_ledger_entries_company_id_contract_month_id_foreign');
            });
        }
        Schema::table('time_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('ledger_month_identity');
        });
    }
};
