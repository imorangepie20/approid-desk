<?php

use App\Actions\SendMonthTransitionReminders;
use App\Actions\SendWeeklyProgressReports;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('desk:notify-month-transitions', function (): void {
    $count = (new SendMonthTransitionReminders)->handle(today());
    $this->info("월 전환 알림 대상 {$count}건을 처리했습니다.");
})->purpose('Notify operators and customer administrators about month transition candidates');

Artisan::command('desk:send-weekly-progress-reports', function (): void {
    $count = (new SendWeeklyProgressReports)->handle(today());
    $this->info("주간 진행 보고 {$count}건을 생성하고 발송 대기열에 등록했습니다.");
})->purpose('Generate and queue the previous calendar week progress reports');

Schedule::command('desk:notify-month-transitions')
    ->dailyAt('09:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('desk:scheduler-heartbeat')
    ->everyMinute()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(2)
    ->onOneServer();

Schedule::command('desk:send-weekly-progress-reports')
    ->weeklyOn(CarbonInterface::MONDAY, '09:15')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();
