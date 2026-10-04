<?php

namespace App\Actions;

use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use App\Models\ContractMonth;
use App\Models\EstimateApproval;
use App\Models\EstimateVersion;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConfirmWorkLog
{
    /** The revision identifies the exact draft reviewed by the caller, including on retry. */
    public function handle(User $actor, WorkLog $log, int $revision): WorkLog
    {
        return DB::transaction(function () use ($actor, $log, $revision): WorkLog {
            // Resolve identity from storage, never from mutable caller attributes.
            $requestId = WorkLog::query()->findOrFail($log->id)->work_request_id;
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($requestId);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $log = WorkLog::query()->lockForUpdate()->findOrFail($log->id);
            Gate::forUser($actor)->authorize('confirm', $log);

            if ($revision < 1 || $log->revision !== $revision + ($log->status === WorkLogStatus::Confirmed ? 1 : 0)) {
                throw ValidationException::withMessages(['revision' => '작업기록이 변경되었습니다. 최신 내용을 확인한 뒤 다시 확정해 주세요.']);
            }
            if (! $log->is_billable) {
                throw ValidationException::withMessages(['is_billable' => '차감 제외 기록의 확정은 별도 비차감 처리 기능에서 지원합니다.']);
            }
            if ($log->status === WorkLogStatus::Confirmed) {
                return $log;
            }
            if ($request->status->isTerminal()) {
                throw ValidationException::withMessages(['request' => '완료 또는 취소된 요청의 작업시간은 확정할 수 없습니다.']);
            }
            $estimate = EstimateVersion::query()->where('work_request_id', $request->id)
                ->lockForUpdate()->find($request->approved_estimate_version_id);
            if ($estimate === null || EstimateApproval::query()->where('estimate_version_id', $estimate->id)->lockForUpdate()->first() === null) {
                throw ValidationException::withMessages(['estimate' => '승인된 견적이 필요합니다.']);
            }
            if ($log->worked_on->isAfter(today()) || ! $log->worked_on->isSameMonth($estimate->usage_month)) {
                throw ValidationException::withMessages(['worked_on' => '작업일은 승인 견적의 사용 대상 월에 속해야 하며 미래일 수 없습니다.']);
            }
            $month = ContractMonth::query()->where('company_id', $request->company_id)
                ->where('service_contract_id', $request->service_contract_id)
                ->whereDate('month', $estimate->usage_month)->lockForUpdate()->first();
            if ($month === null || $month->status !== 'open') {
                throw ValidationException::withMessages(['month' => '사용 대상 월의 계약시간이 없거나 마감되었습니다.']);
            }
            $reserved = $month->entries()->where('work_request_id', $request->id)
                ->whereIn('type', [TimeLedgerType::Reserve->value, TimeLedgerType::Release->value])
                ->orderBy('id')->lockForUpdate()->get()
                ->sum(fn (TimeLedgerEntry $entry): int => $entry->minutes * ($entry->type === TimeLedgerType::Reserve ? 1 : -1));
            if ($reserved < $log->minutes) {
                throw ValidationException::withMessages(['minutes' => '남은 예약시간이 부족합니다. 추가 견적 승인을 먼저 받아 주세요.']);
            }
            $confirmedAt = now();
            foreach ([TimeLedgerType::Release, TimeLedgerType::Usage] as $type) {
                (new TimeLedgerEntry)->forceFill([
                    'company_id' => $log->company_id, 'contract_month_id' => $month->id,
                    'work_request_id' => $request->id, 'type' => $type, 'minutes' => $log->minutes,
                    'source_type' => 'work_log', 'source_id' => $log->id, 'actor_id' => $actor->id,
                    'reason' => '작업시간 확정', 'occurred_at' => $confirmedAt,
                ])->save();
            }
            // Deliberately bypass the model's general-purpose save guard only here,
            // after both immutable entries exist inside this same transaction.
            DB::table('work_logs')->where('id', $log->id)->update([
                'status' => WorkLogStatus::Confirmed->value, 'confirmed_at' => $confirmedAt,
                'confirmed_by' => $actor->id,
                'revision' => $log->revision + 1, 'updated_at' => $confirmedAt,
            ]);

            return $log->refresh();
        }, 5);
    }
}
