<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkRequestCommentController extends Controller
{
    public function store(Request $request, WorkRequest $workRequest): RedirectResponse
    {
        Gate::authorize('create', [WorkRequestComment::class, $workRequest]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        DB::transaction(fn (): WorkRequestComment => $workRequest->comments()->create([
            'company_id' => $workRequest->company_id,
            'author_id' => $user->id,
            'body' => $validated['body'],
        ]));

        return redirect()
            ->to(route('requests.show', $workRequest).'#comments')
            ->with('success', '댓글을 등록했습니다.');
    }
}
