<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateInitialAdmin extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    protected $signature = 'desk:create-initial-admin';

    protected $description = 'Create the first Approid Desk super administrator';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('A user already exists. Initial administrator creation is disabled.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Administrator name'));
        $email = Str::lower(trim((string) $this->ask('Administrator email')));
        $password = (string) $this->secret('Password');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($name, $email, $password): void {
            if (User::query()->lockForUpdate()->exists()) {
                throw new \RuntimeException('A user was created while initial setup was in progress.');
            }

            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => $password,
                'role' => UserRole::SuperAdmin,
                'company_id' => null,
                'is_active' => true,
            ]);
            $user->save();
        });

        $this->info('Initial administrator created.');

        return self::SUCCESS;
    }
}
