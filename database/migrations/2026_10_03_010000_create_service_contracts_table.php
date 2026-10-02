<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('document_path')->nullable();
            $table->timestamp('signature_confirmed_at')->nullable();
            $table->foreignId('signature_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE service_contracts ADD CONSTRAINT contracts_type_check CHECK (type IN ('development', 'maintenance'))");
        DB::statement("ALTER TABLE service_contracts ADD CONSTRAINT contracts_status_check CHECK (status IN ('draft', 'active', 'expired', 'cancelled'))");
        DB::statement('ALTER TABLE service_contracts ADD CONSTRAINT contracts_dates_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement("ALTER TABLE service_contracts ADD CONSTRAINT contracts_signature_check CHECK ((signature_confirmed_at IS NULL AND signature_confirmed_by IS NULL) OR (signature_confirmed_at IS NOT NULL AND signature_confirmed_by IS NOT NULL AND NULLIF(TRIM(document_path), '') IS NOT NULL))");

        Schema::table('work_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('service_contract_id')->nullable();
            $table->foreign(['company_id', 'service_contract_id'])
                ->references(['company_id', 'id'])->on('service_contracts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_requests', function (Blueprint $table): void {
            $table->dropForeign(['company_id', 'service_contract_id']);
            $table->dropColumn('service_contract_id');
        });
        Schema::dropIfExists('service_contracts');
    }
};
