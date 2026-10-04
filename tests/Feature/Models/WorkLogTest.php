<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\SaveWorkLogDraft;
use App\Enums\WorkLogStatus;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class WorkLogTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    private User $operator;

    private WorkRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->operator()->create();
        $this->request = WorkRequest::factory()->create();
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['worked_on' => today()->toDateString(), 'description' => '구현 검토', 'minutes' => 45, 'is_billable' => true];
    }

    private function draft(): WorkLog
    {
        return (new SaveWorkLogDraft)->handle($this->operator, $this->request, $this->input());
    }

    public function test_draft_creation_update_and_no_ledger_side_effects(): void
    {
        $log = $this->draft();
        $this->assertSame($this->operator->id, $log->worker->id);
        $this->assertSame($this->request->id, $log->workRequest->id);
        $this->assertSame($log->id, $this->request->workLogs()->sole()->id);
        $this->assertSame(WorkLogStatus::Draft, $log->status);
        $this->assertNull($log->confirmed_at);
        $this->assertSame(1, $log->revision);
        $log = (new SaveWorkLogDraft)->handle($this->operator, $this->request,
            array_replace($this->input(), ['minutes' => 60, 'is_billable' => false, 'non_billable_reason' => ' 개발자 귀책 수정 ', 'revision' => 1]), $log);
        $this->assertFalse($log->is_billable);
        $this->assertSame('개발자 귀책 수정', $log->non_billable_reason);
        $this->assertSame(60, $log->minutes);
        $this->assertSame(2, $log->revision);
        $this->assertSame(0, TimeLedgerEntry::count());
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidInputs(): array
    {
        return ['zero' => ['minutes', 0], 'negative' => ['minutes', -1], 'fraction' => ['minutes', 1.5],
            'over day' => ['minutes', 1441], 'blank' => ['description', ' '], 'long description' => ['description', str_repeat('x', 10001)],
            'bad date' => ['worked_on', '2026-02-30'], 'future' => ['worked_on', '9999-12-31'],
            'billable' => ['is_billable', 'false'], 'status' => ['status', 'confirmed'],
            'confirmation' => ['confirmed_at', '2026-01-01'], 'confirmer' => ['confirmed_by', 123], 'worker' => ['worker_id', 123],
            'company' => ['company_id', 123], 'request' => ['work_request_id', 123]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_cannot_create_partial_records(string $field, mixed $value): void
    {
        try {
            (new SaveWorkLogDraft)->handle($this->operator, $this->request, array_replace($this->input(), [$field => $value]));
            $this->fail('Expected validation failure');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
        $this->assertSame(0, WorkLog::count());
        $this->assertSame(0, TimeLedgerEntry::count());
    }

    public function test_non_billable_reason_is_required_and_billable_reason_is_cleared(): void
    {
        foreach ([null, '', '   '] as $reason) {
            try {
                (new SaveWorkLogDraft)->handle($this->operator, $this->request, array_replace($this->input(), ['is_billable' => false, 'non_billable_reason' => $reason]));
                $this->fail('Reason required');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('non_billable_reason', $error->errors());
            }
        }
        $log = (new SaveWorkLogDraft)->handle($this->operator, $this->request, $this->input() + ['non_billable_reason' => '미사용 사유']);
        $this->assertNull($log->non_billable_reason);
    }

    public function test_stale_draft_and_other_worker_cannot_overwrite(): void
    {
        $log = $this->draft();
        $payload = $this->input() + ['revision' => 1];
        (new SaveWorkLogDraft)->handle($this->operator, $this->request, $payload, $log);
        try {
            (new SaveWorkLogDraft)->handle($this->operator, $this->request, $payload, $log);
            $this->fail('Stale write accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('revision', $error->errors());
        }
        $other = User::factory()->operator()->create();
        $this->assertFalse(Gate::forUser($other)->allows('update', $log));
        $this->assertTrue(Gate::forUser(User::factory()->superAdmin()->create())->allows('update', $log));
        $this->expectException(AuthorizationException::class);
        (new SaveWorkLogDraft)->handle($other, $this->request, array_replace($payload, ['revision' => 2]), $log);
    }

    public function test_customer_draft_privacy_and_creation_denial(): void
    {
        $log = $this->draft();
        foreach ([User::factory()->customerAdmin()->for($this->request->company)->create(),
            User::factory()->customerUser()->for($this->request->company)->create(), User::factory()->customerAdmin()->create()] as $customer) {
            $this->assertFalse(Gate::forUser($customer)->allows('view', $log));
            $this->assertSame(0, WorkLog::query()->visibleTo($customer)->count());
            try {
                (new SaveWorkLogDraft)->handle($customer, $this->request, $this->input());
                $this->fail('Customer could log work');
            } catch (AuthorizationException) {
                $this->assertSame(1, WorkLog::count());
            }
        }
    }

    public function test_service_reloads_deactivated_actor(): void
    {
        User::query()->whereKey($this->operator->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        (new SaveWorkLogDraft)->handle($this->operator, $this->request, $this->input());
    }

    public function test_model_cannot_confirm_without_the_future_ledger_workflow(): void
    {
        $log = $this->draft();
        $this->expectException(LogicException::class);
        $log->forceFill(['status' => WorkLogStatus::Confirmed, 'confirmed_at' => now()])->save();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidDatabaseValues(): array
    {
        return ['minutes' => [['minutes' => 0]], 'description' => [['description' => ' ']],
            'reason' => [['is_billable' => false, 'non_billable_reason' => null]],
            'revision' => [['revision' => 0]], 'status' => [['status' => 'invalid']],
            'confirmation' => [['status' => 'confirmed', 'confirmed_at' => null]],
            'confirmer' => [['status' => 'confirmed', 'confirmed_at' => '2026-01-01 00:00:00', 'confirmed_by' => null]],
            'draft date' => [['confirmed_at' => '2026-01-01 00:00:00']]];
    }

    #[DataProvider('invalidDatabaseValues')]
    public function test_database_rejects_invalid_values(array $changes): void
    {
        $log = $this->draft();
        $this->expectException(QueryException::class);
        DB::table('work_logs')->where('id', $log->id)->update($changes);
    }

    public function test_database_rejects_cross_company_request(): void
    {
        $log = $this->draft();
        $row = DB::table('work_logs')->where('id', $log->id)->first();
        $this->assertNotNull($row);
        $data = (array) $row;
        unset($data['id']);
        $data['work_request_id'] = WorkRequest::factory()->create()->id;
        $this->expectException(QueryException::class);
        DB::table('work_logs')->insert($data);
    }

    public function test_confirmed_fixture_is_immutable_and_scoped(): void
    {
        $log = $this->draft();
        // Schema fixture only, not a supported confirmation workflow.
        DB::table('work_logs')->where('id', $log->id)->update([
            'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => $this->operator->id,
        ]);
        $log->refresh();
        $customer = User::factory()->customerAdmin()->for($this->request->company)->create();
        $this->assertTrue(Gate::forUser($customer)->allows('view', $log));
        $this->assertFalse(Gate::forUser($this->operator)->allows('update', $log));
        $this->assertSame(1, WorkLog::query()->visibleTo($customer)->count());
        $this->assertSame(0, WorkLog::query()->visibleTo(User::factory()->customerAdmin()->create())->count());
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('work_logs')->where('id', $log->id);
                $operation === 'update' ? $query->update(['minutes' => 99]) : $query->delete();
                $this->fail('Confirmed record changed');
            } catch (QueryException) {
                $this->assertSame(45, $log->fresh()->minutes);
            }
        }
    }

    public function test_failed_save_rolls_back(): void
    {
        WorkLog::created(function (): void {
            throw new RuntimeException('failure after insert');
        });
        try {
            $this->draft();
            $this->fail('Expected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('failure after insert', $error->getMessage());
        }
        $this->assertSame(0, WorkLog::count());
        $this->assertSame(0, TimeLedgerEntry::count());
    }

    public function test_drafts_leave_existing_reserved_time_unchanged(): void
    {
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Test');
        $before = TimeLedgerEntry::query()->orderBy('id')->get()->toArray();
        $log = (new SaveWorkLogDraft)->handle($operator, $request, $this->input());
        (new SaveWorkLogDraft)->handle($operator, $request, array_replace($this->input(),
            ['revision' => 1, 'is_billable' => false, 'non_billable_reason' => '개발자 귀책']), $log);
        $this->assertSame($before, TimeLedgerEntry::query()->orderBy('id')->get()->toArray());
    }

    public function test_database_rejects_identity_reassignment(): void
    {
        $log = $this->draft();
        $other = User::factory()->operator()->create();
        $this->expectException(QueryException::class);
        DB::table('work_logs')->where('id', $log->id)->update(['worker_id' => $other->id]);
    }

    public function test_request_mismatch_cannot_edit_an_existing_draft(): void
    {
        $log = $this->draft();
        $other = WorkRequest::factory()->create();
        $this->expectException(HttpException::class);
        (new SaveWorkLogDraft)->handle($this->operator, $other, $this->input() + ['revision' => 1], $log);
    }
}
