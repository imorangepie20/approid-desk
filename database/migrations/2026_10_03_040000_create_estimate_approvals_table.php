<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_approvals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('work_request_id');
            $table->unsignedBigInteger('estimate_version_id')->unique();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->string('approver_role', 32);
            $table->timestamp('approved_at');
            $table->uuid('idempotency_key')->unique();
            $table->text('approval_text');
            $table->text('ip_address');
            $table->text('user_agent');
            $table->timestamps();
            $table->foreign(['company_id', 'work_request_id'])->references(['company_id', 'id'])->on('work_requests')->restrictOnDelete();
            $table->foreign(['work_request_id', 'estimate_version_id'], 'approval_estimate_identity')
                ->references(['work_request_id', 'id'])->on('estimate_versions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE estimate_approvals ADD CONSTRAINT approval_role_check CHECK (approver_role = 'customer_admin')");
        DB::statement('ALTER TABLE estimate_approvals ADD CONSTRAINT approval_text_check CHECK (CHAR_LENGTH(TRIM(approval_text)) > 0)');
        DB::unprepared("CREATE TRIGGER approval_update_guard BEFORE UPDATE ON estimate_approvals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approval evidence is immutable'");
        DB::unprepared("CREATE TRIGGER approval_delete_guard BEFORE DELETE ON estimate_approvals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approval evidence cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_approvals');
    }
};
