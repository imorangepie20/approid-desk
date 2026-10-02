<?php

namespace App\Http\Controllers;

use App\Enums\CompanyStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize(Permission::ManageCompanies->value);

        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();

        $companies = Company::query()
            ->withCount([
                'users',
                'projects',
                'userInvitations as pending_invitations_count' => fn (Builder $query): Builder => $query
                    ->whereNull('accepted_at')
                    ->whereNull('accepted_user_id')
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->whereHas(
                        'company',
                        fn (Builder $company): Builder => $company->where('status', CompanyStatus::Active->value),
                    ),
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('primary_contact_name', 'like', "%{$search}%")
                        ->orWhere('primary_contact_email', 'like', "%{$search}%");
                });
            })
            ->when(
                CompanyStatus::tryFrom($status) !== null,
                fn (Builder $query): Builder => $query->where('status', $status),
            )
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString();

        return view('companies.index', [
            'companies' => $companies,
            'statuses' => CompanyStatus::cases(),
            'search' => $search,
            'selectedStatus' => $status,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Company::class);

        $company = Company::query()->create($this->validatedCompany($request));

        return redirect()
            ->route('companies.show', $company)
            ->with('success', '고객사를 등록했습니다. 이어서 담당자를 초대해 주세요.');
    }

    public function show(Request $request, Company $company): View
    {
        Gate::authorize('view', $company);
        Gate::authorize(Permission::ManageCompanyUsers->value);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $company->loadCount(['users', 'projects']);

        $users = User::query()
            ->visibleTo($user)
            ->where('company_id', $company->id)
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $invitations = UserInvitation::query()
            ->visibleTo($user)
            ->with('inviter:id,name')
            ->where('company_id', $company->id)
            ->latest()
            ->limit(25)
            ->get();

        return view('companies.show', [
            'company' => $company,
            'users' => $users,
            'invitations' => $invitations,
            'statuses' => CompanyStatus::cases(),
            'customerRoles' => [UserRole::CustomerAdmin, UserRole::CustomerUser],
            'canUpdateCompany' => Gate::allows('update', $company),
            'defaultExpiration' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('update', $company);

        $company->update($this->validatedCompany($request));

        return redirect()
            ->route('companies.show', $company)
            ->with('success', '고객사 정보를 저장했습니다.');
    }

    /** @return array<string, mixed> */
    private function validatedCompany(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(CompanyStatus::class)],
            'primary_contact_name' => ['nullable', 'string', 'max:255'],
            'primary_contact_email' => ['nullable', 'email', 'max:255'],
            'primary_contact_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
