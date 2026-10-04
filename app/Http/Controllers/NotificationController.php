<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function __invoke(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAccessWorkspace(), 403);

        $record = $user->notifications()->findOrFail($notification);
        $record->markAsRead();
        $workRequestId = $record->data['work_request_id'] ?? null;
        if (is_int($workRequestId) || (is_string($workRequestId) && ctype_digit($workRequestId))) {
            $workRequest = WorkRequest::query()->visibleTo($user)->find((int) $workRequestId);
            if ($workRequest !== null && Gate::forUser($user)->allows('view', $workRequest)) {
                return redirect()->route('requests.show', $workRequest);
            }
        }

        return redirect()->route('dashboard');
    }
}
