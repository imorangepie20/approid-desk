<?php

namespace App\Actions;

use App\Enums\WorkLogStatus;
use App\Models\ContractMonth;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConfirmNonBillableWorkLog
{
    /** The revision identifies the exact non-billable draft reviewed by the caller. */
    public function handle(User $actor, WorkLog $log, int $revision): WorkLog
    {
        return DB::transaction(function () use ($actor, $log, $revision): WorkLog {
            $requestId = WorkLog::query()->findOrFail($log->id)->work_request_id;
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($requestId);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $log = WorkLog::query()->lockForUpdate()->findOrFail($log->id);
            Gate::forUser($actor)->authorize('confirm', $log);

            if ($revision < 1 || $log->revision !== $revision + ($log->status === WorkLogStatus::Confirmed ? 1 : 0)) {
                throw ValidationException::withMessages(['revision' => '작업기록이 변경되었습니다. 최신 내용을 확인한 뒤 다시 확정해 주세요.']);
            }
            if ($log->is_billable) {
                throw ValidationException::withMessages(['is_billable' => '고객 차감 작업은 예약을 실사용으로 전환하는 확정 기능을 사용해 주세요.']);
            }
            if ($log->status === WorkLogStatus::Confirmed) {
                return $log;
            }
            if ($request->status->isTerminal()) {
                throw ValidationException::withMessages(['request' => '완료 또는 취소된 요청은 무상 재작업으로 재개한 뒤 기록해 주세요.']);
            }
            if ($log->worked_on->isAfter(today())) {
                throw ValidationException::withMessages(['worked_on' => '작업일은 미래일 수 없습니다.']);
            }
            $month = ContractMonth::query()->where('company_id', $request->company_id)
                ->where('service_contract_id', $request->service_contract_id)
                ->whereDate('month', $log->worked_on->copy()->startOfMonth())
                ->lockForUpdate()->first();
            if ($month === null || $month->status !== 'open') {
                throw ValidationException::withMessages(['month' => '작업일에 해당하는 열린 계약 월이 필요합니다.']);
            }

            $confirmedAt = now();
            DB::table('work_logs')->where('id', $log->id)->update([
                'status' => WorkLogStatus::Confirmed->value,
                'confirmed_at' => $confirmedAt,
                'confirmed_by' => $actor->id,
                'revision' => $log->revision + 1,
                'updated_at' => $confirmedAt,
            ]);

            return $log->refresh();
        }, 5);
    }
}
