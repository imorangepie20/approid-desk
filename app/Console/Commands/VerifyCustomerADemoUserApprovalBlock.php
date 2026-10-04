<?php

namespace App\Console\Commands;

use App\Actions\VerifyCustomerADemoUserApprovalBlock as VerifyCustomerADemoUserApprovalBlockAction;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class VerifyCustomerADemoUserApprovalBlock extends Command
{
    protected $signature = 'desk:verify-customer-a-demo-user-approval-block';

    protected $description = 'Verify that a customer A general user can view but cannot approve the submitted demo estimate';

    public function handle(VerifyCustomerADemoUserApprovalBlockAction $action): int
    {
        try {
            $estimate = $action->handle();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->error(is_string($message) ? $message : 'Demo approval boundary validation failed.');

            return self::FAILURE;
        }

        $request = $estimate->workRequest()->firstOrFail();

        $this->info('Customer A general user approval is blocked.');
        $this->table(['Check', 'Result'], [
            ['Request', "#{$request->id} {$request->title}"],
            ['Estimate', "#{$estimate->id} version {$estimate->version}"],
            ['Request visibility', 'allowed'],
            ['Submitted estimate visibility', 'allowed'],
            ['Approval permission', 'denied'],
            ['Decision route ability', 'denied'],
            ['Revision request ability', 'denied'],
            ['Request status', $request->status->value],
        ]);

        return self::SUCCESS;
    }
}
