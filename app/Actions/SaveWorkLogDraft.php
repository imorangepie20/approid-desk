<?php

namespace App\Actions;

use App\Enums\WorkLogStatus;
use App\Models\ContractMonth;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveWorkLogDraft
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, WorkRequest $request, array $input, ?WorkLog $log = null): WorkLog
    {
        return DB::transaction(function () use ($actor, $request, $input, $log): WorkLog {
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($request->id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('create', [WorkLog::class, $request]);
            if ($log !== null) {
                $log = WorkLog::query()->lockForUpdate()->findOrFail($log->id);
                Gate::forUser($actor)->authorize('update', $log);
                abort_unless($log->work_request_id === $request->id, 404);
            }
            foreach (['description', 'non_billable_reason'] as $field) {
                if (isset($input[$field]) && is_string($input[$field])) {
                    $input[$field] = trim($input[$field]);
                }
            }
            $data = Validator::make($input, [
                'worked_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:today'],
                'description' => ['required', 'string', 'max:10000'],
                'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
                'is_billable' => ['required', 'boolean'],
                'non_billable_reason' => [Rule::requiredIf(in_array($input['is_billable'] ?? null, [false, 0, '0'], true)), 'nullable', 'string', 'max:10000'],
                'revision' => [$log === null ? 'prohibited' : 'required', 'integer', 'min:1'],
                'status' => ['prohibited'], 'confirmed_at' => ['prohibited'], 'confirmed_by' => ['prohibited'],
                'company_id' => ['prohibited'], 'worker_id' => ['prohibited'], 'work_request_id' => ['prohibited'],
            ])->validate();
            $months = array_unique(array_filter([substr($data['worked_on'], 0, 7).'-01', $log?->worked_on->copy()->startOfMonth()->toDateString()]));
            $periods = ContractMonth::query()->where('service_contract_id', $request->service_contract_id)
                ->whereIn('month', $months)->orderBy('id')->lockForUpdate()->get();
            if ($periods->contains('status', 'closed')) {
                throw ValidationException::withMessages(['worked_on' => '마감된 월에는 작업기록을 추가하거나 변경할 수 없습니다.']);
            }
            if ($log !== null && $log->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => '작업기록이 변경되었습니다. 최신 내용을 확인한 뒤 다시 저장해 주세요.']);
            }
            $log ??= (new WorkLog)->forceFill(['company_id' => $request->company_id,
                'work_request_id' => $request->id, 'worker_id' => $actor->id, 'status' => WorkLogStatus::Draft,
                'confirmed_at' => null, 'confirmed_by' => null, 'revision' => 0]);
            $log->forceFill(['worked_on' => $data['worked_on'], 'description' => $data['description'],
                'minutes' => (int) $data['minutes'], 'is_billable' => (bool) $data['is_billable'],
                'non_billable_reason' => $data['is_billable'] ? null : $data['non_billable_reason'],
                'revision' => $log->revision + 1])->save();

            return $log;
        }, 5);
    }
}
