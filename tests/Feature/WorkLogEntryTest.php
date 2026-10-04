<?php

namespace Tests\Feature;

use App\Actions\ApproveEstimateVersion;
use App\Actions\CloseContractMonth;
use App\Actions\SaveWorkLogDraft;
use App\Enums\WorkLogStatus;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class WorkLogEntryTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['worked_on' => today()->toDateString(), 'minutes' => 30,
            'description' => '작업시간 화면 검증', 'is_billable' => '1'];
    }

    public function test_operator_saves_edits_and_confirms_billable_draft_idempotently(): void
    {
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Feature test');
        $this->actingAs($operator)->get(route('requests.show', $request))->assertSee(route('requests.work-logs.index', $request));
        $this->get(route('requests.work-logs.index', $request))->assertOk()->assertSee('등록된 작업시간이 없습니다.');
        $this->get(route('requests.work-logs.create', $request))->assertOk();
        $this->post(route('requests.work-logs.store', $request), $this->input())->assertRedirect(route('requests.work-logs.index', $request));
        $log = WorkLog::sole();
        $this->get(route('requests.work-logs.edit', [$request, $log]))->assertOk()->assertSee($log->description);
        $this->patch(route('requests.work-logs.update', [$request, $log]), array_replace($this->input(), ['minutes' => 40, 'revision' => 1]))->assertSessionHasNoErrors();
        $this->patch(route('requests.work-logs.update', [$request, $log]), $this->input() + ['revision' => 1])->assertSessionHasErrors('revision');
        $url = route('requests.work-logs.confirm', [$request, $log]);
        $this->post($url, ['revision' => 2])->assertSessionHasErrors('confirmed');
        $this->post($url, ['revision' => 1, 'confirmed' => 1])->assertSessionHasErrors('revision');
        for ($i = 0; $i < 2; $i++) {
            $this->post($url, ['revision' => 2, 'confirmed' => 1])->assertSessionHasNoErrors();
        }
        $this->assertSame(40, TimeLedgerEntry::where('type', 'usage')->sole()->minutes);
        $this->assertSame(WorkLogStatus::Confirmed, $log->fresh()->status);
        $this->get(route('requests.work-logs.edit', [$request, $log]))->assertForbidden();
        $this->patch(route('requests.work-logs.update', [$request, $log]), $this->input() + ['revision' => 3])->assertForbidden();
        $this->get(route('requests.work-logs.index', $request))->assertOk()->assertDontSee('name="confirmed"', false);
    }

    public function test_super_admin_can_take_over_and_confirm_another_workers_draft(): void
    {
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Feature test');
        $log = (new SaveWorkLogDraft)->handle($operator, $request, $this->input());
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('requests.work-logs.edit', [$request, $log]))
            ->assertOk()
            ->assertSee($log->description);

        $this->patch(route('requests.work-logs.update', [$request, $log]), array_replace($this->input(), [
            'minutes' => 45,
            'description' => '최고 관리자 인수 작업',
            'revision' => 1,
        ]))->assertSessionHasNoErrors();

        $this->post(route('requests.work-logs.confirm', [$request, $log]), [
            'revision' => 2,
            'confirmed' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame($operator->id, $log->fresh()->worker_id);
        $this->assertSame($superAdmin->id, $log->fresh()->confirmed_by);
        $this->assertSame(45, TimeLedgerEntry::where('type', 'usage')->sole()->minutes);
        $this->assertSame(WorkLogStatus::Confirmed, $log->fresh()->status);
    }

    public function test_non_billable_entry_requires_reason_and_does_not_change_ledger(): void
    {
        [$request, $operator] = $this->timeFixture();
        $input = array_replace($this->input(), ['is_billable' => '0']);
        $this->actingAs($operator)->post(route('requests.work-logs.store', $request), $input)->assertSessionHasErrors('non_billable_reason');
        $this->post(route('requests.work-logs.store', $request), $input + ['non_billable_reason' => '내부 수정'])->assertSessionHasNoErrors();
        $log = WorkLog::sole();
        $before = TimeLedgerEntry::count();
        $this->post(route('requests.work-logs.confirm', [$request, $log]), ['revision' => 1, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame($before, TimeLedgerEntry::count());
        $this->assertSame($operator->id, $log->fresh()->confirmed_by);
        $this->get(route('requests.work-logs.index', $request))->assertSee('내부 수정')->assertSee('비차감');
    }

    public function test_customers_cannot_access_internal_work_log_routes(): void
    {
        [$request, $operator, $admin] = $this->timeFixture();
        $log = (new SaveWorkLogDraft)->handle($operator, $request, $this->input());
        foreach ([$admin, User::factory()->for($request->company)->create(), User::factory()->customerAdmin()->create()] as $user) {
            $this->actingAs($user);
            foreach (['index', 'create'] as $action) {
                $this->get(route('requests.work-logs.'.$action, $request))->assertForbidden();
            }
            $this->get(route('requests.work-logs.edit', [$request, $log]))->assertForbidden();
            $this->post(route('requests.work-logs.store', $request), $this->input())->assertForbidden();
            $this->patch(route('requests.work-logs.update', [$request, $log]), $this->input() + ['revision' => 1])->assertForbidden();
            $this->post(route('requests.work-logs.confirm', [$request, $log]), ['revision' => 1, 'confirmed' => 1])->assertForbidden();
        }
    }

    public function test_other_worker_and_mismatched_request_are_rejected(): void
    {
        [$request, $operator] = $this->timeFixture();
        $log = (new SaveWorkLogDraft)->handle($operator, $request, $this->input());
        $other = User::factory()->operator()->create();
        $this->actingAs($other)->get(route('requests.work-logs.index', $request))->assertOk()->assertDontSee('name="confirmed"', false);
        $this->get(route('requests.work-logs.edit', [$request, $log]))->assertForbidden();
        $this->patch(route('requests.work-logs.update', [$request, $log]), $this->input() + ['revision' => 1])->assertForbidden();
        $this->post(route('requests.work-logs.confirm', [$request, $log]), ['revision' => 1, 'confirmed' => 1])->assertForbidden();
        $wrong = WorkRequest::factory()->create();
        $this->actingAs($operator)->get(route('requests.work-logs.edit', [$wrong, $log]))->assertNotFound();
        $this->post(route('requests.work-logs.confirm', [$wrong, $log]), ['revision' => 1, 'confirmed' => 1])->assertNotFound();
    }

    public function test_validation_and_reservation_errors_preserve_draft(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Feature test');
        $this->actingAs($operator)->post(route('requests.work-logs.store', $request), array_replace($this->input(), ['minutes' => 0, 'worked_on' => today()->addDay()->toDateString(), 'worker_id' => $admin->id]))
            ->assertSessionHasErrors(['minutes', 'worked_on', 'worker_id']);
        $this->post(route('requests.work-logs.store', $request), array_replace($this->input(), ['minutes' => 61]))->assertSessionHasNoErrors();
        $log = WorkLog::sole();
        $this->post(route('requests.work-logs.confirm', [$request, $log]), ['revision' => 1, 'confirmed' => 1])->assertSessionHasErrors('minutes');
        $this->assertSame(WorkLogStatus::Draft, $log->fresh()->status);
        $this->assertSame(0, TimeLedgerEntry::where('type', 'usage')->count());
    }

    public function test_closed_month_rejects_new_entries(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $date = today()->toDateString();
        $this->travelTo(today()->addMonth()->startOfMonth());
        (new CloseContractMonth)->handle($operator, $month);
        $this->actingAs($operator)->post(route('requests.work-logs.store', $request), array_replace($this->input(), ['worked_on' => $date]))->assertSessionHasErrors('worked_on');
        $this->assertSame(0, WorkLog::count());
    }
}
