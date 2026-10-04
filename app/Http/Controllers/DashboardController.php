<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use App\Services\CustomerCompanyTimeOverview;
use App\Services\OperatorCompanyTimeOverview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        OperatorCompanyTimeOverview $operatorTimeOverview,
        CustomerCompanyTimeOverview $customerTimeOverview,
    ): View {
        $user = $request->user();

        abort_unless($user instanceof User && $user->canAccessWorkspace(), 403);

        if (! $user->role->isSystemRole()) {
            return $this->customerDashboard($user, $customerTimeOverview);
        }

        return $this->operatorDashboard($user, $operatorTimeOverview);
    }

    private function operatorDashboard(User $user, OperatorCompanyTimeOverview $timeOverview): View
    {
        $metrics = [
            'activeCompanies' => Company::query()
                ->visibleTo($user)
                ->active()
                ->count(),
            'newRequests' => WorkRequest::query()
                ->visibleTo($user)
                ->where('status', WorkRequestStatus::Received->value)
                ->count(),
            'majorIncidents' => WorkRequest::query()
                ->visibleTo($user)
                ->majorIncidents()
                ->count(),
            'inProgressRequests' => WorkRequest::query()
                ->visibleTo($user)
                ->where('status', WorkRequestStatus::InProgress->value)
                ->count(),
            'awaitingApprovalRequests' => WorkRequest::query()->visibleTo($user)
                ->where('status', WorkRequestStatus::AwaitingApproval->value)->count(),
            'awaitingReviewRequests' => WorkRequest::query()->visibleTo($user)
                ->where('status', WorkRequestStatus::AwaitingReview->value)->count(),
        ];

        $majorIncidents = WorkRequest::query()
            ->visibleTo($user)
            ->with(['company:id,name', 'project:id,name', 'firstResponseEvent'])
            ->majorIncidents()
            ->oldest('requested_at')
            ->oldest('id')
            ->limit(5)
            ->get();
        $companyTime = $timeOverview->forMonth($user, today());

        return view('dashboard.operator', [
            'metrics' => $metrics,
            'awaitingApprovalRequests' => WorkRequest::query()->visibleTo($user)
                ->with(['company:id,name', 'project:id,name'])
                ->where('status', WorkRequestStatus::AwaitingApproval->value)
                ->oldest('requested_at')->oldest('id')->limit(5)->get(),
            'awaitingReviewRequests' => WorkRequest::query()->visibleTo($user)
                ->with(['company:id,name', 'project:id,name'])
                ->where('status', WorkRequestStatus::AwaitingReview->value)
                ->oldest('requested_at')->oldest('id')->limit(5)->get(),
            'majorIncidents' => $majorIncidents,
            'companyTime' => $companyTime,
            'asOf' => now()->format('Y년 n월 j일'),
        ]);
    }

    private function customerDashboard(User $user, CustomerCompanyTimeOverview $timeOverview): View
    {
        $customerTime = $timeOverview->forMonth($user, today());

        $metrics = [
            'activeProjects' => Project::query()
                ->visibleTo($user)
                ->where('status', ProjectStatus::Active->value)
                ->count(),
            'openRequests' => WorkRequest::query()
                ->visibleTo($user)
                ->whereIn('status', $this->openRequestStatuses())
                ->count(),
            'majorIncidents' => WorkRequest::query()
                ->visibleTo($user)
                ->majorIncidents()
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

        $majorIncidents = WorkRequest::query()
            ->visibleTo($user)
            ->with(['company:id,name', 'project:id,name', 'firstResponseEvent'])
            ->majorIncidents()
            ->oldest('requested_at')
            ->oldest('id')
            ->limit(5)
            ->get();

        return view('dashboard.customer', [
            'companyName' => $customerTime['company']->name,
            'customerTime' => $customerTime,
            'metrics' => $metrics,
            'projects' => $projects,
            'recentRequests' => $recentRequests,
            'majorIncidents' => $majorIncidents,
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
