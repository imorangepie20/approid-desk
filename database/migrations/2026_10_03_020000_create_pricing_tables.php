<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('work_type', 32);
            $table->string('difficulty', 32);
            $table->unsignedInteger('hourly_rate');
            $table->unsignedInteger('urgent_surcharge_bps')->default(0);
            $table->text('urgent_criteria');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->index(['work_type', 'difficulty', 'valid_from']);
        });
        DB::statement("ALTER TABLE pricing_rules ADD CONSTRAINT pricing_type_check CHECK (work_type IN ('feature', 'bug_fix', 'maintenance', 'consultation', 'other'))");
        DB::statement("ALTER TABLE pricing_rules ADD CONSTRAINT pricing_difficulty_check CHECK (difficulty IN ('low', 'normal', 'high'))");
        DB::statement('ALTER TABLE pricing_rules ADD CONSTRAINT pricing_amount_check CHECK (hourly_rate BETWEEN 1 AND 100000000 AND urgent_surcharge_bps <= 100000)');
        DB::statement('ALTER TABLE pricing_rules ADD CONSTRAINT pricing_criteria_check CHECK (CHAR_LENGTH(TRIM(urgent_criteria)) > 0)');
        DB::statement('ALTER TABLE pricing_rules ADD CONSTRAINT pricing_dates_check CHECK (valid_until IS NULL OR valid_until >= valid_from)');

        Schema::create('pricing_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('work_request_id');
            $table->foreignId('pricing_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $table->date('priced_on');
            $table->unsignedInteger('estimated_minutes');
            $table->boolean('is_urgent');
            $table->unsignedBigInteger('amount');
            $table->json('rate_snapshot');
            $table->text('rationale');
            $table->string('decision', 32);
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'work_request_id'])->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE pricing_assessments ADD CONSTRAINT assessment_minutes_check CHECK (estimated_minutes BETWEEN 1 AND 10000000)');
        DB::statement("ALTER TABLE pricing_assessments ADD CONSTRAINT assessment_decision_check CHECK (decision IN ('feasible', 'rejected', 'renegotiation'))");
        DB::statement("ALTER TABLE pricing_assessments ADD CONSTRAINT assessment_reason_check CHECK (decision = 'feasible' OR NULLIF(TRIM(decision_reason), '') IS NOT NULL)");
        DB::statement('ALTER TABLE pricing_assessments ADD CONSTRAINT assessment_rationale_check CHECK (CHAR_LENGTH(TRIM(rationale)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_assessments');
        Schema::dropIfExists('pricing_rules');
    }
};
