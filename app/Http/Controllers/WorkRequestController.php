<?php

namespace App\Http\Controllers;

use App\Enums\CompanyStatus;
use App\Enums\IntakeChannel;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', WorkRequest::class);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $search = trim($request->string('search')->toString());
        $selectedStatus = $request->string('status')->toString();
        $selectedType = $request->string('type')->toString();
        $selectedPriority = $request->string('priority')->toString();
        $status = WorkRequestStatus::tryFrom($selectedStatus);
        $type = WorkRequestType::tryFrom($selectedType);
        $priority = WorkRequestPriority::tryFrom($selectedPriority);
        $companyId = $request->integer('company_id');
        $projectId = $request->integer('project_id');
        $requestedFrom = $this->dateFilter($request->string('requested_from')->toString());
        $requestedTo = $this->dateFilter($request->string('requested_to')->toString());
        $canFilterCompanies = $user->role->isSystemRole();

        $workRequests = WorkRequest::query()
            ->visibleTo($user)
            ->with([
                'company:id,name',
                'project:id,company_id,name',
                'submitter:id,name',
                'assignee:id,name',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('requirements', 'like', "%{$search}%")
                        ->orWhereHas('company', fn (Builder $company): Builder => $company->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('project', fn (Builder $project): Builder => $project->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status->value))
            ->when($type !== null, fn (Builder $query): Builder => $query->where('type', $type->value))
            ->when($priority !== null, fn (Builder $query): Builder => $query->where('priority', $priority->value))
            ->when(
                $canFilterCompanies && $companyId > 0,
                fn (Builder $query): Builder => $query->where('company_id', $companyId),
            )
            ->when($projectId > 0, fn (Builder $query): Builder => $query->where('project_id', $projectId))
            ->when($requestedFrom !== null, fn (Builder $query): Builder => $query->whereDate('requested_at', '>=', $requestedFrom))
            ->when($requestedTo !== null, fn (Builder $query): Builder => $query->whereDate('requested_at', '<=', $requestedTo))
            ->latest('requested_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $companies = $canFilterCompanies
            ? Company::query()->visibleTo($user)->orderBy('name')->get(['id', 'name'])
            : collect();

        $projects = Project::query()
            ->visibleTo($user)
            ->when(
                $canFilterCompanies && $companyId > 0,
                fn (Builder $query): Builder => $query->where('company_id', $companyId),
            )
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        return view('requests.index', [
            'workRequests' => $workRequests,
            'companies' => $companies,
            'projects' => $projects,
            'statuses' => WorkRequestStatus::cases(),
            'types' => WorkRequestType::cases(),
            'priorities' => WorkRequestPriority::cases(),
            'search' => $search,
            'selectedStatus' => $selectedStatus,
            'selectedType' => $selectedType,
            'selectedPriority' => $selectedPriority,
            'selectedCompanyId' => $companyId,
            'selectedProjectId' => $projectId,
            'requestedFrom' => $requestedFrom ?? '',
            'requestedTo' => $requestedTo ?? '',
            'canFilterCompanies' => $canFilterCompanies,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', WorkRequest::class);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $canSelectCompany = $user->role->isSystemRole();
        $selectedCompanyId = $canSelectCompany
            ? $request->integer('company_id')
            : ($user->company_id ?? 0);

        $companies = $canSelectCompany
            ? Company::query()->visibleTo($user)->active()->orderBy('name')->get(['id', 'name'])
            : collect();

        $projects = Project::query()
            ->visibleTo($user)
            ->with('company:id,name')
            ->whereHas('company', fn (Builder $query): Builder => $query->where('status', CompanyStatus::Active->value))
            ->when(
                $canSelectCompany && $selectedCompanyId > 0,
                fn (Builder $query): Builder => $query->where('company_id', $selectedCompanyId),
            )
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        return view('requests.create', [
            'companies' => $companies,
            'projects' => $projects,
            'types' => WorkRequestType::cases(),
            'priorities' => WorkRequestPriority::cases(),
            'intakeChannels' => IntakeChannel::cases(),
            'canSelectCompany' => $canSelectCompany,
            'selectedCompanyId' => $selectedCompanyId,
            'customerCompany' => $canSelectCompany ? null : $user->company,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', WorkRequest::class);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $isSystemUser = $user->role->isSystemRole();
        $rules = [
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'requirements' => ['required', 'string', 'max:50000'],
            'type' => ['required', Rule::enum(WorkRequestType::class)],
            'priority' => ['required', Rule::enum(WorkRequestPriority::class)],
            'is_urgent' => ['sometimes', 'boolean'],
            'desired_due_date' => ['nullable', 'date'],
        ];

        if ($isSystemUser) {
            $rules += [
                'company_id' => [
                    'required',
                    'integer',
                    Rule::exists('companies', 'id')->where('status', CompanyStatus::Active->value),
                ],
                'intake_channel' => ['required', Rule::enum(IntakeChannel::class)],
                'source_reference' => ['nullable', 'string', 'max:2048'],
                'intake_summary' => ['nullable', 'string', 'max:5000'],
                'requested_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
                'late_entry_reason' => ['nullable', 'string', 'max:5000'],
            ];
        }

        $validated = $request->validate($rules);
        $companyId = $isSystemUser ? (int) $validated['company_id'] : $user->company_id;
        abort_unless($companyId !== null, 403);

        $project = Project::query()
            ->visibleTo($user)
            ->whereKey((int) $validated['project_id'])
            ->where('company_id', $companyId)
            ->whereHas('company', fn (Builder $query): Builder => $query->where('status', CompanyStatus::Active->value))
            ->first();

        if (! $project instanceof Project) {
            throw ValidationException::withMessages([
                'project_id' => '선택한 고객사에서 사용할 수 있는 프로젝트를 선택해 주세요.',
            ]);
        }

        $registeredAt = now();
        $intakeChannel = $isSystemUser
            ? IntakeChannel::from((string) $validated['intake_channel'])
            : IntakeChannel::Web;
        $requestedAt = $isSystemUser && ! empty($validated['requested_at'])
            ? now()->createFromFormat('Y-m-d\TH:i', (string) $validated['requested_at'])
            : $registeredAt;

        $errors = [];

        if ($requestedAt->isAfter($registeredAt)) {
            $errors['requested_at'] = '요청 일시는 등록 일시보다 늦을 수 없습니다.';
        }

        if ($isSystemUser && $intakeChannel !== IntakeChannel::Web) {
            if (blank($validated['source_reference'] ?? null)) {
                $errors['source_reference'] = '대리 접수 시 원문 출처를 입력해 주세요.';
            }

            if (blank($validated['intake_summary'] ?? null)) {
                $errors['intake_summary'] = '대리 접수 시 접수 요약을 입력해 주세요.';
            }
        }

        if (! $requestedAt->isSameDay($registeredAt) && blank($validated['late_entry_reason'] ?? null)) {
            $errors['late_entry_reason'] = '요청일과 등록일이 다르면 지연 등록 사유를 입력해 주세요.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $workRequest = DB::transaction(fn (): WorkRequest => WorkRequest::query()->create([
            'company_id' => $companyId,
            'project_id' => $project->id,
            'submitted_by' => $user->id,
            'assigned_to' => null,
            'parent_request_id' => null,
            'title' => $validated['title'],
            'requirements' => $validated['requirements'],
            'type' => $validated['type'],
            'priority' => $validated['priority'],
            'is_urgent' => $request->boolean('is_urgent'),
            'desired_due_date' => $validated['desired_due_date'] ?? null,
            'intake_channel' => $intakeChannel,
            'source_reference' => $intakeChannel === IntakeChannel::Web ? null : ($validated['source_reference'] ?? null),
            'intake_summary' => $intakeChannel === IntakeChannel::Web ? null : ($validated['intake_summary'] ?? null),
            'status' => WorkRequestStatus::Received,
            'requested_at' => $requestedAt,
            'registered_at' => $registeredAt,
            'late_entry_reason' => $requestedAt->isSameDay($registeredAt) ? null : ($validated['late_entry_reason'] ?? null),
        ]));

        return redirect()
            ->route('requests.show', $workRequest)
            ->with('success', '요청을 등록했습니다.');
    }

    public function show(WorkRequest $workRequest): View
    {
        Gate::authorize('view', $workRequest);

        $workRequest->load([
            'company:id,name',
            'project:id,company_id,name',
            'submitter:id,name',
            'assignee:id,name',
            'comments' => fn ($query) => $query->with('author:id,name')->oldest(),
            'activities' => fn ($query) => $query->with('actor:id,name')->latest('occurred_at')->latest('id'),
        ]);

        return view('requests.show', [
            'workRequest' => $workRequest,
            'canComment' => Gate::allows('create', [WorkRequestComment::class, $workRequest]),
        ]);
    }

    private function dateFilter(string $date): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $date : null;
    }
}
