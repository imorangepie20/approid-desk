<?php

namespace App\Http\Controllers;

use App\Actions\ApproveEstimateVersion;
use App\Actions\RequestEstimateRevision;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EstimateDecisionController extends Controller
{
    public function show(WorkRequest $workRequest, EstimateVersion $estimate): View
    {
        $this->authorizeDecision($workRequest, $estimate);
        Gate::authorize('view', $estimate);

        return view('estimates.decision', [
            'workRequest' => $workRequest,
            'estimate' => $estimate,
            'approvalText' => ApproveEstimateVersion::APPROVAL_TEXT,
            'idempotencyKey' => (string) Str::uuid(),
            'canDecide' => Gate::allows('decide', $estimate),
        ]);
    }

    public function approve(Request $request, WorkRequest $workRequest, EstimateVersion $estimate): RedirectResponse
    {
        $this->authorizeDecision($workRequest, $estimate);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'approval_text' => ['required', 'string', Rule::in([ApproveEstimateVersion::APPROVAL_TEXT])],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        (new ApproveEstimateVersion)->handle($actor, $estimate, $data['idempotency_key'], $data['approval_text'],
            $request->ip() ?? '', $request->userAgent() ?? '');

        return redirect()->route('requests.show', $workRequest)->with('success', '견적 승인이 처리되었습니다. 최신 상태는 요청 상세에서 확인해 주세요.');
    }

    public function revision(Request $request, WorkRequest $workRequest, EstimateVersion $estimate): RedirectResponse
    {
        $this->authorizeDecision($workRequest, $estimate);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:10000']]);
        (new RequestEstimateRevision)->handle($actor, $estimate, $data['reason']);

        return redirect()->route('requests.show', $workRequest)->with('success', '수정을 요청했습니다. 운영자가 견적을 다시 검토합니다.');
    }

    private function authorizeDecision(WorkRequest $workRequest, EstimateVersion $estimate): void
    {
        Gate::authorize('view', $workRequest);
        abort_unless($estimate->work_request_id === $workRequest->id, 404);
        Gate::authorize('approve', $estimate);
    }
}
