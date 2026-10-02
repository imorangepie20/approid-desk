<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('role', 32)
                ->default(UserRole::SuperAdmin->value)
                ->after('company_id')
                ->index();
            $table->boolean('is_active')->default(true)->after('role')->index();
        });

        DB::statement('ALTER TABLE users ALTER COLUMN role DROP DEFAULT');
        DB::statement(sprintf(
            "ALTER TABLE users ADD CONSTRAINT users_role_company_check CHECK ((role IN ('%s', '%s') AND company_id IS NULL) OR (role IN ('%s', '%s') AND company_id IS NOT NULL))",
            UserRole::SuperAdmin->value,
            UserRole::Operator->value,
            UserRole::CustomerAdmin->value,
            UserRole::CustomerUser->value,
        ));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CHECK users_role_company_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
