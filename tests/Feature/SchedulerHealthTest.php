<?php

namespace Tests\Feature;

use App\Services\SchedulerHealth;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(SchedulerHealth::CACHE_KEY);
    }

    public function test_heartbeat_command_records_the_current_time(): void
    {
        $this->travelTo('2026-10-04 09:12:00');

        $this->artisan('desk:scheduler-heartbeat')
            ->expectsOutput('Scheduler heartbeat recorded at 2026-10-04T09:12:00+09:00.')
            ->assertSuccessful();

        $this->assertSame(
            '2026-10-04T09:12:00+09:00',
            Cache::get(SchedulerHealth::CACHE_KEY),
        );
    }

    public function test_check_succeeds_for_a_recent_heartbeat(): void
    {
        $this->travelTo('2026-10-04 09:12:00');
        $this->artisan('desk:scheduler-heartbeat')->assertSuccessful();
        $this->travel(90)->seconds();

        $this->artisan('desk:check-scheduler')
            ->expectsOutput('Scheduler heartbeat is healthy (90 seconds old; maximum 180).')
            ->assertSuccessful();
    }

    public function test_check_fails_for_missing_stale_or_future_heartbeats(): void
    {
        $this->artisan('desk:check-scheduler')
            ->expectsOutput('No valid scheduler heartbeat has been recorded.')
            ->assertFailed();

        $this->travelTo('2026-10-04 09:12:00');
        $this->artisan('desk:scheduler-heartbeat')->assertSuccessful();
        $this->travel(181)->seconds();
        $this->artisan('desk:check-scheduler')
            ->expectsOutput('Scheduler heartbeat is stale (181 seconds old; maximum 180).')
            ->assertFailed();

        Cache::forever(SchedulerHealth::CACHE_KEY, now()->addSecond()->toIso8601String());
        $this->artisan('desk:check-scheduler')
            ->expectsOutput('The scheduler heartbeat is ahead of the application clock.')
            ->assertFailed();
    }

    public function test_check_validates_and_honors_the_maximum_age_override(): void
    {
        $this->travelTo('2026-10-04 09:12:00');
        $this->artisan('desk:scheduler-heartbeat')->assertSuccessful();
        $this->travel(20)->seconds();

        $this->artisan('desk:check-scheduler --max-age=10')->assertFailed();
        $this->artisan('desk:check-scheduler --max-age=20')->assertSuccessful();
        $this->artisan('desk:check-scheduler --max-age=invalid')
            ->expectsOutput('The scheduler heartbeat maximum age must be a positive integer.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_heartbeat_schedule_runs_every_minute_with_shared_locks(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'desk:scheduler-heartbeat'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(2, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }
}
