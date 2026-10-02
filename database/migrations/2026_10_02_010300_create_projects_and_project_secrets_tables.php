<?php

use App\Enums\ProjectStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default(ProjectStatus::Active->value);
            $table->string('site_url', 2048)->nullable();
            $table->text('technical_notes')->nullable();
            $table->boolean('is_existing_site')->default(false);
            $table->boolean('source_code_secured')->default(false);
            $table->boolean('database_dump_secured')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        DB::statement(sprintf(
            "ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('%s', '%s', '%s'))",
            ProjectStatus::Active->value,
            ProjectStatus::OnHold->value,
            ProjectStatus::Archived->value,
        ));

        Schema::create('project_secrets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->longText('secret_data');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_secrets');
        Schema::dropIfExists('projects');
    }
};
