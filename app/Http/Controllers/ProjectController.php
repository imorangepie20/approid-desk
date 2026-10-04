<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Project::class);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $companyId = $request->integer('company_id');
        $canFilterCompanies = $user->role->isSystemRole();

        $projects = Project::query()
            ->visibleTo($user)
            ->with('company:id,name')
            ->withCount('workRequests')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('company', fn (Builder $company): Builder => $company->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(
                ProjectStatus::tryFrom($status) !== null,
                fn (Builder $query): Builder => $query->where('status', $status),
            )
            ->when(
                $canFilterCompanies && $companyId > 0,
                fn (Builder $query): Builder => $query->where('company_id', $companyId),
            )
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString();

        $companies = $canFilterCompanies
            ? Company::query()->visibleTo($user)->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('projects.index', [
            'projects' => $projects,
            'companies' => $companies,
            'statuses' => ProjectStatus::cases(),
            'search' => $search,
            'selectedStatus' => $status,
            'selectedCompanyId' => $companyId,
            'canFilterCompanies' => $canFilterCompanies,
        ]);
    }

    public function show(Project $project): View
    {
        Gate::authorize('view', $project);

        $project->load('company:id,name');

        $terminalStatuses = [
            WorkRequestStatus::Completed->value,
            WorkRequestStatus::Cancelled->value,
        ];

        $metrics = [
            'totalRequests' => $project->workRequests()->count(),
            'openRequests' => $project->workRequests()->whereNotIn('status', $terminalStatuses)->count(),
            'completedRequests' => $project->workRequests()->where('status', WorkRequestStatus::Completed->value)->count(),
            'majorIncidents' => $project->workRequests()->majorIncidents()->count(),
        ];

        $recentRequests = $project->workRequests()
            ->with(['submitter:id,name', 'assignee:id,name'])
            ->latest('requested_at')
            ->limit(10)
            ->get();

        return view('projects.show', [
            'project' => $project,
            'metrics' => $metrics,
            'recentRequests' => $recentRequests,
        ]);
    }
}
