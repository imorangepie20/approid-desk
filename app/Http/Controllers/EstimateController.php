<?php

namespace App\Http\Controllers;

use App\Actions\AssessRequestPricing;
use App\Actions\CreateEstimateVersion;
use App\Actions\SubmitEstimateVersion;
use App\Actions\TransitionWorkRequest;
use App\Enums\WorkDifficulty;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateVersion;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use App\Services\EstimateWorkflowRules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EstimateController extends Controller
{
    public function create(WorkRequest $workRequest): View
    {
        Gate::authorize('create', [EstimateVersion::class, $workRequest]);
        $this->assertWritable($workRequest);

        return view('estimates.create', [
            'workRequest' => $workRequest,
            'latest' => $workRequest->latestEstimateVersion()->first(),
            'difficulties' => WorkDifficulty::cases(),
            'rules' => PricingRule::query()->where('work_type', $workRequest->type->value)
                ->whereDate('valid_from', '<=', today())
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
                ->orderBy('difficulty')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, WorkRequest $workRequest): RedirectResponse
    {
        Gate::authorize('create', [EstimateVersion::class, $workRequest]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate([
            'difficulty' => ['required', Rule::enum(WorkDifficulty::class)],
            'estimated_minutes' => ['required', 'integer', 'min:1', 'max:10000000'],
            'rationale' => ['required', 'string', 'max:10000'],
            'included_scope' => ['required', 'string', 'max:10000'],
            'excluded_scope' => ['required', 'string', 'max:10000'],
            'scheduled_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'usage_month' => ['required', 'date_format:Y-m', 'regex:/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/'],
            'base_version' => ['required', 'integer', 'min:0'],
        ]);
        $estimate = DB::transaction(function () use ($actor, $workRequest, $data): EstimateVersion {
            $locked = WorkRequest::query()->lockForUpdate()->findOrFail($workRequest->id);
            $freshActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($freshActor)->authorize('create', [EstimateVersion::class, $locked]);
            $this->assertWritable($locked);
            if ((int) $locked->estimateVersions()->max('version') !== (int) $data['base_version']) {
                throw ValidationException::withMessages(['base_version' => '다른 견적이 작성되었습니다. 요청 상세에서 최신 버전을 확인한 뒤 다시 작성해 주세요.']);
            }
            $assessment = (new AssessRequestPricing)->handle($freshActor, $locked, WorkDifficulty::from($data['difficulty']),
                (int) $data['estimated_minutes'], today(), $data['rationale']);
            $estimate = (new CreateEstimateVersion)->handle($freshActor, $locked, $assessment, $data['included_scope'],
                $data['excluded_scope'], CarbonImmutable::parse($data['scheduled_on']), CarbonImmutable::parse($data['usage_month'].'-01'));
            if ($locked->status === WorkRequestStatus::Received) {
                (new TransitionWorkRequest)->handle($freshActor, $locked, WorkRequestStatus::Estimating);
            }

            return $estimate;
        }, 5);

        return redirect()->route('requests.estimates.preview', [$workRequest, $estimate]);
    }

    public function preview(WorkRequest $workRequest, EstimateVersion $estimate): View
    {
        Gate::authorize('create', [EstimateVersion::class, $workRequest]);
        abort_unless($estimate->work_request_id === $workRequest->id, 404);
        Gate::authorize('submit', $estimate);

        return view('estimates.preview', [
            'workRequest' => $workRequest,
            'estimate' => $estimate,
            'canSubmit' => Gate::allows('submitDraft', $estimate),
        ]);
    }

    public function submit(Request $request, WorkRequest $workRequest, EstimateVersion $estimate): RedirectResponse
    {
        Gate::authorize('create', [EstimateVersion::class, $workRequest]);
        abort_unless($estimate->work_request_id === $workRequest->id, 404);
        Gate::authorize('submit', $estimate);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($actor, $workRequest, $estimate): void {
            $locked = WorkRequest::query()->lockForUpdate()->findOrFail($workRequest->id);
            $version = EstimateVersion::query()->lockForUpdate()->findOrFail($estimate->id);
            $freshActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($freshActor)->authorize('submit', $version);
            if ($version->submitted_at !== null) {
                return; // Retrying a submitted version must not move the request again.
            }
            $this->assertWritable($locked);
            if (! (new EstimateWorkflowRules)->canSubmit($locked, $version)) {
                throw ValidationException::withMessages(['estimate' => '작성 가능한 상태의 최신 초안만 제출할 수 있습니다.']);
            }
            (new SubmitEstimateVersion)->handle($freshActor, $version);
            (new TransitionWorkRequest)->handle($freshActor, $locked, WorkRequestStatus::AwaitingApproval);
        }, 5);

        return redirect()->route('requests.show', $workRequest)->with('success', '견적 제출을 확인했습니다. 최신 상태는 요청 상세에서 확인해 주세요.');
    }

    private function assertWritable(WorkRequest $request): void
    {
        if (! (new EstimateWorkflowRules)->canWrite($request)) {
            throw ValidationException::withMessages(['estimate' => '접수·견적 중 또는 승인 후 작업 대기·진행 중 상태에서만 작성할 수 있습니다.']);
        }
    }
}
