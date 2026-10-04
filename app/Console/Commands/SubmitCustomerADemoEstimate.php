<?php

namespace App\Console\Commands;

use App\Actions\SubmitCustomerADemoEstimate as SubmitCustomerADemoEstimateAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmitCustomerADemoEstimate extends Command
{
    protected $signature = 'desk:submit-customer-a-demo-estimate
        {--actor= : Active system user email recorded as the estimate author}
        {--force : Allow synthetic demo data changes in production}';

    protected $description = 'Create and submit the idempotent 180-minute customer A portfolio demo estimate';

    public function handle(SubmitCustomerADemoEstimateAction $action): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Production demo data changes require --force.');

            return self::FAILURE;
        }

        $actor = $this->actor();
        if (! $actor instanceof User) {
            return self::FAILURE;
        }

        try {
            $estimate = $action->handle($actor);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->error(is_string($message) ? $message : 'Demo estimate validation failed.');

            return self::FAILURE;
        }
        $request = $estimate->workRequest()->firstOrFail();

        $this->info('Customer A demo estimate is submitted.');
        $this->table(['Resource', 'ID / value'], [
            ['Request', "#{$request->id} {$request->title}"],
            ['Estimate', "#{$estimate->id} version {$estimate->version}"],
            ['Estimated time', "{$estimate->estimated_minutes} minutes"],
            ['Amount', number_format($estimate->amount).' KRW'],
            ['Usage month', $estimate->usage_month->format('Y-m')],
            ['Status', $request->status->value],
        ]);

        return self::SUCCESS;
    }

    private function actor(): ?User
    {
        $query = User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::SuperAdmin->value, UserRole::Operator->value]);
        $email = Str::lower(trim((string) $this->option('actor')));

        if ($email !== '') {
            $actor = (clone $query)->where('email', $email)->first();
            if (! $actor instanceof User) {
                $this->error('The selected actor is not an active system user.');
            }

            return $actor;
        }

        $actors = (clone $query)->orderBy('id')->limit(2)->get();
        if ($actors->count() !== 1) {
            $this->error('Specify --actor when there is not exactly one active system user.');

            return null;
        }

        return $actors->first();
    }
}
