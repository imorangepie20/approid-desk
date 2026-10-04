<?php

namespace App\Http\Controllers;

use App\Actions\RetryNotificationDelivery;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\Permission;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NotificationDeliveryController extends Controller
{
    public function index(Request $request): View
    {
        $this->actor($request);
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(NotificationDeliveryStatus::class)],
            'channel' => ['nullable', Rule::enum(NotificationChannel::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = NotificationDeliveryStatus::tryFrom($validated['status'] ?? '')
            ?? NotificationDeliveryStatus::Failed;
        $channel = NotificationChannel::tryFrom($validated['channel'] ?? '');
        $counts = NotificationDelivery::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $deliveries = NotificationDelivery::query()
            ->with([
                'workRequest:id,company_id,title',
                'weeklyProgressReport:id,company_id,period_start,period_end',
                'notifiable',
                'latestRetry.requester:id,name',
            ])
            ->withCount('retries')
            ->where('status', $status->value)
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel->value))
            ->orderByDesc($status === NotificationDeliveryStatus::Failed ? 'failed_at' : 'updated_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('notification-deliveries.index', [
            'deliveries' => $deliveries,
            'counts' => $counts,
            'selectedStatus' => $status,
            'selectedChannel' => $channel,
        ]);
    }

    public function retry(
        Request $request,
        NotificationDelivery $notificationDelivery,
        RetryNotificationDelivery $retry,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $retry->handle($actor, $notificationDelivery);

        return redirect()
            ->route('notification-deliveries.index', ['status' => NotificationDeliveryStatus::Failed->value])
            ->with('success', '알림 재시도를 큐에 등록했습니다.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $actor = User::query()->findOrFail($user->id);
        Gate::forUser($actor)->authorize(Permission::ManageNotifications->value);

        return $actor;
    }
}
