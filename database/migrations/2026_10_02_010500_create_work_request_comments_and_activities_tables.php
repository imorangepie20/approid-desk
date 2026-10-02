<?php

use App\Enums\WorkRequestActivityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_requests', function (Blueprint $table): void {
            $table->foreignId('assigned_to')
                ->nullable()
                ->after('submitted_by')
                ->constrained('users')
                ->restrictOnDelete();
        });

        Schema::create('work_request_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('work_request_id');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->longText('body');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->index(['work_request_id', 'created_at']);
            $table->foreign(['company_id', 'work_request_id'])
                ->references(['company_id', 'id'])
                ->on('work_requests')
                ->restrictOnDelete();
        });

        Schema::create('work_request_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('work_request_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('comment_id')->nullable();
            $table->string('type', 32);
            $table->string('summary', 500);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['work_request_id', 'occurred_at']);
            $table->index(['company_id', 'type', 'occurred_at']);
            $table->foreign(['company_id', 'work_request_id'])
                ->references(['company_id', 'id'])
                ->on('work_requests')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'comment_id'])
                ->references(['company_id', 'id'])
                ->on('work_request_comments')
                ->restrictOnDelete();
        });

        DB::statement(sprintf(
            "ALTER TABLE work_request_activities ADD CONSTRAINT work_request_activities_type_check CHECK (type IN ('%s', '%s', '%s', '%s', '%s'))",
            WorkRequestActivityType::RequestCreated->value,
            WorkRequestActivityType::RequestUpdated->value,
            WorkRequestActivityType::AssigneeChanged->value,
            WorkRequestActivityType::CommentCreated->value,
            WorkRequestActivityType::StatusChanged->value,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('work_request_activities');
        Schema::dropIfExists('work_request_comments');

        Schema::table('work_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
