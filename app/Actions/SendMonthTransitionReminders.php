<?php

namespace App\Actions;

use App\Enums\NotificationType;
use App\Models\ContractMonth;
use App\Models\MonthTransitionNotice;
use App\Models\WorkRequest;
use App\Services\MonthTransitionCandidates;
use App\Services\NotificationRecipients;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SendMonthTransitionReminders
{
    public function handle(CarbonInterface $asOf): int
    {
        $sent = 0;
        foreach ((new MonthTransitionCandidates)->get($asOf) as $candidate) {
            $created = DB::transaction(function () use ($candidate, $asOf): bool {
                $request = WorkRequest::query()->lockForUpdate()->findOrFail($candidate['request']->id);
                $month = ContractMonth::query()->lockForUpdate()->findOrFail($candidate['month']->id);
                $fresh = (new MonthTransitionCandidates)->for($request, $month, $asOf, true);
                if ($fresh === null || MonthTransitionNotice::query()
                    ->where('work_request_id', $request->id)->where('contract_month_id', $month->id)
                    ->where('reserve_entry_id', $fresh['reserve_entry_id'])->exists()) {
                    return false;
                }
                $recipients = (new NotificationRecipients)->for(NotificationType::MonthTransitionNeeded, $request);
                if ($recipients->isEmpty()) {
                    return false;
                }
                $notice = (new MonthTransitionNotice)->forceFill([
                    'company_id' => $request->company_id,
                    'contract_month_id' => $month->id,
                    'work_request_id' => $request->id,
                    'reserve_entry_id' => $fresh['reserve_entry_id'],
                    'remaining_minutes' => $fresh['remaining_minutes'],
                    'detected_for' => $asOf->toDateString(),
                    'recipient_count' => $recipients->count(),
                    'notified_at' => now(),
                ]);
                $notice->save();
                (new SendBusinessNotification)->handle(
                    NotificationType::MonthTransitionNeeded,
                    $request,
                    context: [
                        'notice_id' => $notice->id,
                        'contract_month_id' => $month->id,
                        'month' => $month->month->format('Y-m'),
                        'remaining_minutes' => $notice->remaining_minutes,
                    ],
                );

                return true;
            }, 5);
            $sent += $created ? 1 : 0;
        }

        return $sent;
    }
}
