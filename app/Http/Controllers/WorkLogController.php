<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmNonBillableWorkLog;
use App\Actions\ConfirmWorkLog;
use App\Actions\SaveWorkLogDraft;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkLogController extends Controller
{
    public function index(WorkRequest $workRequest): View
    {
        Gate::authorize('create', [WorkLog::class, $workRequest]);

        return view('work-logs.index', [
            'workRequest' => $workRequest,
            'logs' => WorkLog::query()->where('work_request_id', $workRequest->id)
                ->with(['worker', 'confirmer'])->orderByDesc('worked_on')->orderByDesc('id')->paginate(20),
        ]);
    }

    public function create(WorkRequest $workRequest): View
    {
        Gate::authorize('create', [WorkLog::class, $workRequest]);

        return view('work-logs.form', ['workRequest' => $workRequest, 'log' => null]);
    }

    public function edit(WorkRequest $workRequest, WorkLog $workLog): View
    {
        $this->authorizeLog($workRequest, $workLog);
        Gate::authorize('update', $workLog);

        return view('work-logs.form', ['workRequest' => $workRequest, 'log' => $workLog]);
    }

    public function store(Request $request, WorkRequest $workRequest): RedirectResponse
    {
        (new SaveWorkLogDraft)->handle($this->actor($request), $workRequest, $request->all());

        return $this->saved($workRequest, '작업시간 초안을 저장했습니다.');
    }

    public function update(Request $request, WorkRequest $workRequest, WorkLog $workLog): RedirectResponse
    {
        $this->authorizeLog($workRequest, $workLog);
        (new SaveWorkLogDraft)->handle($this->actor($request), $workRequest, $request->all(), $workLog);

        return $this->saved($workRequest, '작업시간 초안을 수정했습니다.');
    }

    public function confirm(Request $request, WorkRequest $workRequest, WorkLog $workLog): RedirectResponse
    {
        $this->authorizeLog($workRequest, $workLog);
        Gate::authorize('confirm', $workLog);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']]);
        $action = $workLog->is_billable ? new ConfirmWorkLog : new ConfirmNonBillableWorkLog;
        $action->handle($this->actor($request), $workLog, (int) $data['revision']);

        return $this->saved($workRequest, '작업시간 확정을 확인했습니다.');
    }

    private function authorizeLog(WorkRequest $request, WorkLog $log): void
    {
        Gate::authorize('create', [WorkLog::class, $request]);
        abort_unless($log->work_request_id === $request->id, 404);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function saved(WorkRequest $request, string $message): RedirectResponse
    {
        return redirect()->route('requests.work-logs.index', $request)->with('success', $message);
    }
}
