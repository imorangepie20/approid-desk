<?php

namespace App\Console\Commands;

use App\Actions\RunCustomerADemoWorkflow as RunCustomerADemoWorkflowAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RunCustomerADemoWorkflow extends Command
{
    protected $signature = 'desk:run-customer-a-demo
        {step=all : approve, start, usage, complete, report, isolation, or all}
        {--actor= : Active system user email recorded as the demo worker}
        {--force : Allow synthetic demo data changes in production}';

    protected $description = 'Run the idempotent customer A portfolio workflow from approval through isolation verification';

    public function handle(RunCustomerADemoWorkflowAction $action): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Production demo workflow changes require --force.');

            return self::FAILURE;
        }
        $actor = $this->actor();
        if (! $actor instanceof User) {
            return self::FAILURE;
        }
        $step = Str::lower(trim((string) $this->argument('step')));

        try {
            $rows = match ($step) {
                'approve' => $this->approve($action, $actor),
                'start' => $this->start($action, $actor),
                'usage' => $this->usage($action, $actor),
                'complete' => $this->completeStep($action, $actor),
                'report' => $this->report($action, $actor),
                'isolation' => $this->isolation($action, $actor),
                'all' => $this->all($action, $actor),
                default => throw ValidationException::withMessages(['step' => '지원하는 단계: approve, start, usage, complete, report, isolation, all']),
            };
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->error(is_string($message) ? $message : 'Demo workflow validation failed.');

            return self::FAILURE;
        }

        $this->info('Customer A demo workflow step passed: '.$step);
        $this->table(['Check', 'Result'], $rows);

        return self::SUCCESS;
    }

    /** @return array<int, array{string, string}> */
    private function approve(RunCustomerADemoWorkflowAction $action, User $actor): array
    {
        $approval = $action->approve($actor);

        return [['Approval', '#'.$approval->id], ['Reservation', '180 minutes']];
    }

    /** @return array<int, array{string, string}> */
    private function start(RunCustomerADemoWorkflowAction $action, User $actor): array
    {
        $request = $action->start($actor);

        return [['Request', '#'.$request->id], ['Status', $request->status->value], ['Contract', 'signed']];
    }

    /** @return array<int, array{string, string}> */
    private function usage(RunCustomerADemoWorkflowAction $action, User $actor): array
    {
        $log = $action->recordUsage($actor);

        return [['Work log', '#'.$log->id], ['Confirmed usage', $log->minutes.' minutes']];
    }

    /** @return array<int, array{string, string}> */
    private function completeStep(RunCustomerADemoWorkflowAction $action, User $actor): array
    {
        $request = $action->complete($actor);

        return [['Request', '#'.$request->id], ['Status', $request->status->value], ['Returned reservation', '30 minutes']];
    }

    /** @return array<int, array{string, string}> */
    private function report(RunCustomerADemoWorkflowAction $action, User $actor): array
    {
        $result = $action->verifyUsage($actor);

        return [
            ['Contract month', '#'.$result['month']->id.' '.$result['month']->month->format('Y-m')],
            ['Customer usage / CSV', $result['ledger']['net_usage'].' minutes'],
            ['Available', $result['ledger']['available'].' minutes'],
        ];
    }

    /** @return array<int, array{string, string}> */
    private function isolation(RunCustomerADemoWorkflowAction $action, User $actor): array
    {
        $company = $action->verifyIsolation($actor);

        return [['Foreign company', '#'.$company->id.' '.$company->name], ['Customer A access', 'denied']];
    }

    /** @return array<int, array{string, string}> */
    private function all(RunCustomerADemoWorkflowAction $action, User $actor): array
    {
        $action->approve($actor);
        $action->start($actor);
        $action->recordUsage($actor);
        $action->complete($actor);
        $result = $action->verifyUsage($actor);
        $company = $action->verifyIsolation($actor);

        return [
            ['Approval / reservation', 'approved / 180 minutes'],
            ['Work / returned', '150 / 30 minutes'],
            ['Monthly usage / available', $result['ledger']['net_usage'].' / '.$result['ledger']['available'].' minutes'],
            ['Foreign company', '#'.$company->id.' access denied'],
        ];
    }

    private function actor(): ?User
    {
        $query = User::query()->where('is_active', true)
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
