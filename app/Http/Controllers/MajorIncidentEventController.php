<?php

namespace App\Http\Controllers;

use App\Actions\RecordMajorIncidentEvent;
use App\Enums\MajorIncidentEventType;
use App\Models\User;
use App\Models\WorkRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MajorIncidentEventController extends Controller
{
    public function store(Request $request, WorkRequest $workRequest, RecordMajorIncidentEvent $record): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'event_type' => ['required', Rule::enum(MajorIncidentEventType::class)],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'summary' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string', 'max:10000'],
        ]);

        $record->handle(
            $user,
            $workRequest,
            MajorIncidentEventType::from((string) $validated['event_type']),
            (string) $validated['summary'],
            (string) $validated['details'],
            CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $validated['occurred_at']),
        );

        return redirect()
            ->route('requests.show', $workRequest)
            ->withFragment('major-incident-history')
            ->with('success', '장애 대응 이력을 등록했습니다.');
    }
}
