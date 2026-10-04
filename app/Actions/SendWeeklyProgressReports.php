<?php

namespace App\Actions;

use App\Enums\CompanyStatus;
use App\Enums\NotificationType;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WeeklyProgressReport;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use App\Notifications\BusinessNotification;
use App\Services\WeeklyProgressReportRecipients;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;

class SendWeeklyProgressReports
{
    public function handle(CarbonInterface $asOf): int
    {
        [$periodStart, $periodEnd] = $this->period($asOf);
        $generated = 0;

        $this->candidates($periodStart, $periodEnd)->eachById(
            function (Company $company) use ($periodStart, $periodEnd, &$generated): void {
                $created = DB::transaction(
                    fn (): bool => $this->generateFor($company, $periodStart, $periodEnd),
                    5,
                );
                $generated += $created ? 1 : 0;
            },
        );

        return $generated;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(CarbonInterface $asOf): array
    {
        $periodStart = CarbonImmutable::instance($asOf)
            ->startOfWeek(CarbonInterface::MONDAY)
            ->subWeek()
            ->startOfDay();

        return [$periodStart, $periodStart->addDays(6)->endOfDay()];
    }

    /** @return Builder<Company> */
    private function candidates(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return Company::query()
            ->where('status', CompanyStatus::Active->value)
            ->whereHas('workRequests', function ($query) use ($periodStart, $periodEnd): void {
                $query->whereIn('status', $this->openStatuses())
                    ->orWhereBetween('registered_at', [$periodStart, $periodEnd])
                    ->orWhereHas('statusChanges', fn ($query) => $query
                        ->whereBetween('occurred_at', [$periodStart, $periodEnd]));
            })
            ->orderBy('id');
    }

    private function generateFor(
        Company $candidate,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): bool {
        $company = Company::query()->lockForUpdate()->findOrFail($candidate->id);
        if ($company->status !== CompanyStatus::Active
            || WeeklyProgressReport::query()->where('company_id', $company->id)
                ->whereDate('period_start', $periodStart)->exists()) {
            return false;
        }

        $recipients = (new WeeklyProgressReportRecipients)->for($company);
        if ($recipients->isEmpty()) {
            return false;
        }

        $snapshot = $this->snapshot($company, $periodStart, $periodEnd);
        if ($snapshot['open_request_count'] === 0
            && $snapshot['new_request_count'] === 0
            && $snapshot['changed_request_count'] === 0) {
            return false;
        }

        $report = new WeeklyProgressReport;
        $report->forceFill([
            'company_id' => $company->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            ...$snapshot,
            'recipient_count' => $recipients->count(),
            'generated_at' => now(),
        ])->save();

        $this->dispatch($report, $company, $recipients);

        return true;
    }

    /** @return array{open_request_count: int, new_request_count: int, changed_request_count: int, completed_request_count: int, status_counts: array<string, int>} */
    private function snapshot(
        Company $company,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): array {
        $counts = WorkRequest::query()
            ->where('company_id', $company->id)
            ->whereIn('status', $this->openStatuses())
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $statusCounts = [];
        foreach ($this->openStatuses() as $status) {
            $statusCounts[$status] = (int) ($counts[$status] ?? 0);
        }

        $changes = WorkRequestStatusChange::query()
            ->where('company_id', $company->id)
            ->whereBetween('occurred_at', [$periodStart, $periodEnd]);

        return [
            'open_request_count' => array_sum($statusCounts),
            'new_request_count' => WorkRequest::query()->where('company_id', $company->id)
                ->whereBetween('registered_at', [$periodStart, $periodEnd])->count(),
            'changed_request_count' => (clone $changes)->distinct()->count('work_request_id'),
            'completed_request_count' => (clone $changes)
                ->where('to_status', WorkRequestStatus::Completed->value)
                ->distinct()->count('work_request_id'),
            'status_counts' => $statusCounts,
        ];
    }

    /** @param Collection<int, User> $recipients */
    private function dispatch(WeeklyProgressReport $report, Company $company, Collection $recipients): void
    {
        $type = NotificationType::WeeklyProgressReport;
        $eventKey = hash('sha256', $type->value.'|'.$report->id);
        $periodLabel = $report->period_start->format('Y.m.d').'-'.$report->period_end->format('m.d');
        $title = $company->name.' 주간 진행 보고 '.$periodLabel;
        $message = "열린 요청 {$report->open_request_count}건, 신규 {$report->new_request_count}건, 상태 변경 {$report->changed_request_count}건, 완료 {$report->completed_request_count}건입니다.";
        $context = [
            'weekly_report_id' => $report->id,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'open_request_count' => $report->open_request_count,
            'new_request_count' => $report->new_request_count,
            'changed_request_count' => $report->changed_request_count,
            'completed_request_count' => $report->completed_request_count,
        ];

        foreach ($recipients as $recipient) {
            $deliveryIds = [];
            foreach ($type->channels() as $channel) {
                $delivery = NotificationDelivery::query()->create([
                    'event_key' => $eventKey,
                    'company_id' => $company->id,
                    'work_request_id' => null,
                    'weekly_progress_report_id' => $report->id,
                    'notification_type' => $type->value,
                    'notifiable_type' => $recipient->getMorphClass(),
                    'notifiable_id' => $recipient->getKey(),
                    'channel' => $channel->value,
                    'delivery_data' => [
                        'request_title' => $title,
                        'message' => $message,
                        'action_url' => '/dashboard',
                        'context' => $context,
                    ],
                ]);
                $deliveryIds[$channel->value] = $delivery->id;
            }

            $notification = new BusinessNotification(
                type: $type,
                companyId: $company->id,
                workRequestId: null,
                requestTitle: $title,
                message: $message,
                actionUrl: '/dashboard',
                context: $context,
                eventKey: $eventKey,
                deliveryIds: $deliveryIds,
                channels: array_keys($deliveryIds),
            );
            $notificationId = collect($deliveryIds)->first();
            if (! is_string($notificationId)) {
                throw new LogicException('주간 진행 보고에는 하나 이상의 알림 채널이 필요합니다.');
            }
            $notification->id = $notificationId;
            Notification::send($recipient, $notification);
        }
    }

    /** @return list<string> */
    private function openStatuses(): array
    {
        return array_values(array_map(
            static fn (WorkRequestStatus $status): string => $status->value,
            array_filter(WorkRequestStatus::cases(), static fn (WorkRequestStatus $status): bool => ! $status->isTerminal()),
        ));
    }
}
