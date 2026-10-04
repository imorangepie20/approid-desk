<?php

namespace App\Actions;

use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use App\Models\ContractMonth;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CancelWorkLogUsage
{
    public function handle(User $actor, WorkLog $log, string $reason): TimeLedgerEntry
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:10000']])->validate();

        return DB::transaction(function () use ($actor, $log, $reason): TimeLedgerEntry {
            $requestId = WorkLog::query()->findOrFail($log->id)->work_request_id;
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($requestId);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $log = WorkLog::query()->lockForUpdate()->findOrFail($log->id);
            Gate::forUser($actor)->authorize('cancelUsage', $log);
            if ($log->status !== WorkLogStatus::Confirmed) {
                throw ValidationException::withMessages(['work_log' => '확정된 작업기록의 실사용만 취소할 수 있습니다.']);
            }
            $usage = TimeLedgerEntry::query()->where('source_type', 'work_log')->where('source_id', $log->id)
                ->where('type', TimeLedgerType::Usage)->lockForUpdate()->first();
            if ($usage === null || $usage->work_request_id !== $request->id || $usage->minutes !== $log->minutes) {
                throw ValidationException::withMessages(['ledger' => '작업기록과 일치하는 실사용 원장을 찾을 수 없습니다.']);
            }
            $existing = TimeLedgerEntry::query()->where('source_type', 'work_log_usage_cancellation')
                ->where('source_id', $log->id)->where('type', TimeLedgerType::CancelUsage)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->company_id !== $log->company_id || $existing->contract_month_id !== $usage->contract_month_id
                    || $existing->work_request_id !== $request->id || $existing->minutes !== $log->minutes
                    || $existing->actor_id !== $actor->id || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['work_log' => '이미 다른 내용으로 실사용이 취소된 작업기록입니다.']);
                }

                return $existing;
            }
            $month = ContractMonth::query()->lockForUpdate()->findOrFail($usage->contract_month_id);
            if ($month->company_id !== $log->company_id || $month->status !== 'open') {
                throw ValidationException::withMessages(['month' => '마감된 월의 실사용은 이 기능으로 취소할 수 없습니다.']);
            }

            $cancellation = (new TimeLedgerEntry)->forceFill([
                'company_id' => $log->company_id,
                'contract_month_id' => $month->id,
                'work_request_id' => $request->id,
                'type' => TimeLedgerType::CancelUsage,
                'minutes' => $log->minutes,
                'source_type' => 'work_log_usage_cancellation',
                'source_id' => $log->id,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            $cancellation->save();

            return $cancellation;
        }, 5);
    }
}
