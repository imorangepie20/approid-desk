<?php

namespace App\Http\Controllers;

use App\Actions\CompleteMajorIncidentRollback;
use App\Actions\StartMajorIncidentRollback;
use App\Enums\MajorIncidentRollbackOutcome;
use App\Models\MajorIncidentRollback;
use App\Models\User;
use App\Models\WorkRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MajorIncidentRollbackController extends Controller
{
    public function store(Request $request, WorkRequest $workRequest, StartMajorIncidentRollback $start): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'rollback_target' => ['required', 'string', 'max:255'],
            'rollback_plan' => ['required', 'string', 'max:10000'],
            'rollback_verification_plan' => ['required', 'string', 'max:10000'],
            'rollback_started_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'rollback_start_confirmed' => ['accepted'],
        ]);

        $start->handle(
            $user,
            $workRequest,
            (string) $validated['rollback_target'],
            (string) $validated['rollback_plan'],
            (string) $validated['rollback_verification_plan'],
            CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $validated['rollback_started_at']),
            $request->boolean('rollback_start_confirmed'),
        );

        return redirect()
            ->route('requests.show', $workRequest)
            ->withFragment('major-incident-rollbacks')
            ->with('success', '롤백 실행을 기록했습니다.');
    }

    public function complete(
        Request $request,
        WorkRequest $workRequest,
        MajorIncidentRollback $rollback,
        CompleteMajorIncidentRollback $complete,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $rollback = $workRequest->majorIncidentRollbacks()->findOrFail($rollback->id);
        $validated = $request->validate([
            'rollback_outcome' => ['required', Rule::enum(MajorIncidentRollbackOutcome::class)],
            'rollback_result_summary' => ['required', 'string', 'max:255'],
            'rollback_result_details' => ['required', 'string', 'max:10000'],
            'rollback_completed_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'rollback_result_confirmed' => ['accepted'],
        ]);

        $complete->handle(
            $user,
            $workRequest,
            $rollback,
            MajorIncidentRollbackOutcome::from((string) $validated['rollback_outcome']),
            (string) $validated['rollback_result_summary'],
            (string) $validated['rollback_result_details'],
            CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $validated['rollback_completed_at']),
            $request->boolean('rollback_result_confirmed'),
        );

        return redirect()
            ->route('requests.show', $workRequest)
            ->withFragment('major-incident-rollbacks')
            ->with('success', '롤백 결과를 확정했습니다.');
    }
}
