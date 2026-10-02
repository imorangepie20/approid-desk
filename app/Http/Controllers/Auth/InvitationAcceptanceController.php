<?php

namespace App\Http\Controllers\Auth;

use App\Actions\AcceptUserInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\UserInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class InvitationAcceptanceController extends Controller
{
    public function show(string $token): View
    {
        $invitation = UserInvitation::query()
            ->usable()
            ->where('token_hash', hash('sha256', $token))
            ->with('company:id,name')
            ->firstOrFail();

        return view('pages.auth.accept-invitation', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    public function store(
        AcceptInvitationRequest $request,
        string $token,
        AcceptUserInvitation $acceptInvitation,
    ): RedirectResponse {
        $user = $acceptInvitation->handle(
            token: $token,
            name: (string) $request->validated('name'),
            password: (string) $request->validated('password'),
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
