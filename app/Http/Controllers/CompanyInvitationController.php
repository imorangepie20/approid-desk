<?php

namespace App\Http\Controllers;

use App\Actions\CreateUserInvitation;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CompanyInvitationController extends Controller
{
    public function store(
        Request $request,
        Company $company,
        CreateUserInvitation $createUserInvitation,
    ): RedirectResponse {
        Gate::authorize('create', [UserInvitation::class, $company]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in([
                UserRole::CustomerAdmin->value,
                UserRole::CustomerUser->value,
            ])],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $created = $createUserInvitation->handle(
            inviter: $user,
            company: $company,
            email: (string) $validated['email'],
            role: UserRole::from((string) $validated['role']),
            expiresAt: CarbonImmutable::parse((string) $validated['expires_at']),
        );

        return redirect()
            ->route('companies.show', $company)
            ->with('success', '사용자 초대를 만들었습니다.')
            ->with('invitation_url', route('invitations.accept', $created->token));
    }

    public function destroy(
        Company $company,
        UserInvitation $invitation,
    ): RedirectResponse {
        abort_unless($invitation->company_id === $company->id, 404);
        Gate::authorize('delete', $invitation);

        if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
            $invitation->update(['revoked_at' => now()]);
        }

        return redirect()
            ->route('companies.show', $company)
            ->with('success', '초대를 취소했습니다.');
    }
}
