<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->canAccessWorkspace(), 403);

        if (! $user->role->isSystemRole()) {
            return $this->customerDashboard($user);
        }

        return $this->operatorDashboard($user);
    }

    private function operatorDashboard(User $user): View
    {
        $openStatuses = $this->openRequestStatuses();

        $metrics = [
            'activeCompanies' => Company::query()
                ->visibleTo($user)
                ->active()
                ->count(),
            'newRequests' => WorkRequest::query()
                ->visibleTo($user)
                ->where('status', WorkRequestStatus::Received->value)
                ->count(),
            'urgentRequests' => WorkRequest::query()
                ->visibleTo($user)
                ->where('is_urgent', true)
                ->whereIn('status', $openStatuses)
                ->count(),
            'inProgressRequests' => WorkRequest::query()
                ->visibleTo($user)
                ->where('status', WorkRequestStatus::InProgress->value)
                ->count(),
        ];

        $urgentRequests = WorkRequest::query()
            ->visibleTo($user)
            ->with(['company:id,name', 'project:id,name'])
            ->where('is_urgent', true)
            ->whereIn('status', $openStatuses)
            ->latest('requested_at')
            ->limit(5)
            ->get();

        return view('dashboard.operator', [
            'metrics' => $metrics,
            'urgentRequests' => $urgentRequests,
            'asOf' => now()->format('Y년 n월 j일'),
        ]);
    }

    private function customerDashboard(User $user): View
    {
        abort_unless($user->company_id !== null, 403);

        $companyName = $user->company()->value('name');

        abort_unless(is_string($companyName), 403);

        $metrics = [
            'activeProjects' => Project::query()
                ->visibleTo($user)
                ->where('status', ProjectStatus::Active->value)
                ->count(),
            'openRequests' => WorkRequest::query()
                ->visibleTo($user)
                ->whereIn('status', $this->openRequestStatuses())
                ->count(),
            'awaitingApprovalRequests' => WorkRequest::query()
                ->visibleTo($user)
                ->where('status', WorkRequestStatus::AwaitingApproval->value)
                ->count(),
            'awaitingReviewRequests' => WorkRequest::query()
                ->visibleTo($user)
                ->where('status', WorkRequestStatus::AwaitingReview->value)
                ->count(),
        ];

        $projects = Project::query()
            ->visibleTo($user)
            ->latest('updated_at')
            ->limit(6)
            ->get();

        $recentRequests = WorkRequest::query()
            ->visibleTo($user)
            ->with('project:id,name')
            ->latest('requested_at')
            ->limit(8)
            ->get();

        return view('dashboard.customer', [
            'companyName' => $companyName,
            'metrics' => $metrics,
            'projects' => $projects,
            'recentRequests' => $recentRequests,
            'asOf' => now()->format('Y년 n월 j일'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function openRequestStatuses(): array
    {
        return [
            WorkRequestStatus::Received->value,
            WorkRequestStatus::Estimating->value,
            WorkRequestStatus::AwaitingApproval->value,
            WorkRequestStatus::Queued->value,
            WorkRequestStatus::InProgress->value,
            WorkRequestStatus::AwaitingReview->value,
            WorkRequestStatus::OnHold->value,
        ];
    }
}
