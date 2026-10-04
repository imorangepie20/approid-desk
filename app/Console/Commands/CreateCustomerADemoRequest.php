<?php

namespace App\Console\Commands;

use App\Actions\CreateCustomerADemoRequest as CreateCustomerADemoRequestAction;
use App\Enums\UserRole;
use App\Models\ContractMonth;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateCustomerADemoRequest extends Command
{
    protected $signature = 'desk:create-customer-a-demo-request
        {--actor= : Active system user email recorded as the demo data creator}
        {--force : Allow synthetic demo data creation in production}';

    protected $description = 'Create the idempotent customer A portfolio demo request and its time foundation';

    public function handle(CreateCustomerADemoRequestAction $action): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Production demo data creation requires --force.');

            return self::FAILURE;
        }

        $actor = $this->actor();
        if (! $actor instanceof User) {
            return self::FAILURE;
        }

        $request = $action->handle($actor);
        $request->load(['company', 'project', 'serviceContract']);
        $month = ContractMonth::query()
            ->where('service_contract_id', $request->service_contract_id)
            ->where('month', $request->serviceContract?->starts_on->copy()->startOfMonth()->toDateString())
            ->firstOrFail();

        $this->info('Customer A demo request is ready.');
        $this->table(['Resource', 'ID / value'], [
            ['Company', "#{$request->company_id} {$request->company->name}"],
            ['Project', "#{$request->project_id} {$request->project->name}"],
            ['Contract', "#{$request->service_contract_id} signed"],
            ['Contract month', "#{$month->id} {$month->provided_minutes} minutes"],
            ['Request', "#{$request->id} {$request->title}"],
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
