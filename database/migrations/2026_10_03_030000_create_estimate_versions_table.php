<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_assessments', function (Blueprint $table): void {
            $table->unique(['company_id', 'work_request_id', 'id'], 'assessment_request_identity');
        });
        Schema::create('estimate_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('work_request_id');
            $table->unsignedBigInteger('pricing_assessment_id');
            $table->unsignedInteger('version');
            $table->text('included_scope');
            $table->text('excluded_scope');
            $table->unsignedInteger('estimated_minutes');
            $table->date('scheduled_on');
            $table->date('usage_month');
            $table->unsignedBigInteger('amount');
            $table->json('rate_snapshot');
            $table->text('pricing_rationale');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['work_request_id', 'version']);
            $table->unique(['work_request_id', 'id']);
            $table->foreign(['company_id', 'work_request_id'])->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'work_request_id', 'pricing_assessment_id'], 'estimate_assessment_identity')
                ->references(['company_id', 'work_request_id', 'id'])->on('pricing_assessments')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE estimate_versions ADD CONSTRAINT estimate_content_check CHECK (CHAR_LENGTH(TRIM(included_scope)) > 0 AND CHAR_LENGTH(TRIM(excluded_scope)) > 0 AND CHAR_LENGTH(TRIM(pricing_rationale)) > 0)');
        DB::statement('ALTER TABLE estimate_versions ADD CONSTRAINT estimate_numbers_check CHECK (version > 0 AND estimated_minutes BETWEEN 1 AND 10000000 AND amount > 0 AND DAYOFMONTH(usage_month) = 1)');
        // Protect submitted content even when a bulk query bypasses Eloquent events.
        DB::unprepared("CREATE TRIGGER estimate_submitted_update BEFORE UPDATE ON estimate_versions FOR EACH ROW BEGIN IF OLD.submitted_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted estimates are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER estimate_submitted_delete BEFORE DELETE ON estimate_versions FOR EACH ROW BEGIN IF OLD.submitted_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted estimates cannot be deleted'; END IF; END");
        Schema::table('work_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('approved_estimate_version_id')->nullable();
            $table->foreign(['id', 'approved_estimate_version_id'], 'request_approved_estimate_identity')
                ->references(['work_request_id', 'id'])->on('estimate_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_requests', function (Blueprint $table): void {
            $table->dropForeign('request_approved_estimate_identity');
            $table->dropColumn('approved_estimate_version_id');
        });
        Schema::dropIfExists('estimate_versions');
        // InnoDB may replace the original FK index with the wider unique
        // index. Restore a supporting index before removing that unique.
        if (! Schema::hasIndex('pricing_assessments', 'assessment_request_fk_restore')) {
            Schema::table('pricing_assessments', function (Blueprint $table): void {
                $table->index(['company_id', 'work_request_id'], 'assessment_request_fk_restore');
            });
        }
        Schema::table('pricing_assessments', function (Blueprint $table): void {
            $table->dropUnique('assessment_request_identity');
        });
    }
};
