<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_logs', function (Blueprint $table): void {
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')
                ->constrained('users')->restrictOnDelete();
        });
        DB::statement("UPDATE work_logs logs SET confirmed_by = COALESCE(
            (SELECT ledger.actor_id FROM time_ledger_entries ledger
                WHERE ledger.source_type = 'work_log' AND ledger.source_id = logs.id AND ledger.type = 'usage'
                ORDER BY ledger.id LIMIT 1), logs.worker_id)
            WHERE logs.status = 'confirmed'");
        DB::statement("ALTER TABLE work_logs ADD CONSTRAINT work_log_confirmer_check CHECK (
            (status = 'draft' AND confirmed_by IS NULL) OR (status = 'confirmed' AND confirmed_by IS NOT NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_logs DROP CHECK work_log_confirmer_check');
        Schema::table('work_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmed_by');
        });
    }
};
