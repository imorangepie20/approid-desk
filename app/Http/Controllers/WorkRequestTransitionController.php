<?php

namespace App\Http\Controllers;

use App\Actions\TransitionWorkRequest;
use App\Enums\WorkRequestStatus;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkRequestTransitionController extends Controller
{
    public function __invoke(Request $request, WorkRequest $workRequest): RedirectResponse
    {
        Gate::authorize('view', $workRequest);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate([
            'status' => ['required', Rule::enum(WorkRequestStatus::class)],
            'expected_status' => ['required', Rule::enum(WorkRequestStatus::class)],
            'expected_change' => ['required', 'integer', 'min:0'],
            'confirmed' => ['required', 'accepted'],
            'reason' => ['nullable', 'string', 'max:10000'],
            'is_free_rework' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($actor, $workRequest, $data): void {
            $locked = WorkRequest::query()->lockForUpdate()->findOrFail($workRequest->id);
            $freshActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($freshActor)->authorize('view', $locked);
            if ($locked->status->value !== $data['expected_status']
                || (int) $locked->statusChanges()->max('id') !== (int) $data['expected_change']) {
                throw ValidationException::withMessages(['status' => '상태가 변경되었습니다. 요청 상세를 새로고침한 뒤 다시 확인해 주세요.']);
            }
            $to = WorkRequestStatus::from($data['status']);
            Gate::forUser($freshActor)->authorize('transition', [$locked, $to]);
            $isFreeRework = $locked->status === WorkRequestStatus::Completed && $to === WorkRequestStatus::InProgress;
            if ($isFreeRework !== (bool) ($data['is_free_rework'] ?? false)) {
                throw ValidationException::withMessages(['is_free_rework' => '무상 재작업 여부를 다시 확인해 주세요.']);
            }
            (new TransitionWorkRequest)->handle($freshActor, $locked, $to, $data['reason'] ?? null, $isFreeRework);
        }, 5);

        return redirect()->route('requests.show', $workRequest)->with('success', '요청 상태를 변경했습니다.');
    }
}
