<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\CancelWorkLogUsage;
use App\Actions\CloseContractMonth;
use App\Actions\ConfirmWorkLog;
use App\Actions\SaveWorkLogDraft;
use App\Actions\TransitionWorkRequest;
use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class WorkLogUsageCancellationTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    /** @return array{WorkLog, User, User, ContractMonth} */
    private function confirmed(): array
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Usage cancellation test');
        $log = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '취소 대상', 'minutes' => 45, 'is_billable' => true,
        ]);

        return [(new ConfirmWorkLog)->handle($operator, $log, 1), $operator, $admin, $month];
    }

    public function test_usage_cancellation_adds_reasoned_immutable_ledger_and_preserves_work_log(): void
    {
        [$log, $operator, , $month] = $this->confirmed();
        $entry = (new CancelWorkLogUsage)->handle($operator, $log, '중복 입력 확인');
        $this->assertSame(TimeLedgerType::CancelUsage, $entry->type);
        $this->assertSame(45, $entry->minutes);
        $this->assertSame($operator->id, $entry->actor_id);
        $this->assertSame('중복 입력 확인', $entry->reason);
        $this->assertSame($log->id, $entry->source_id);
        $this->assertSame('work_log_usage_cancellation', $entry->source_type);
        $this->assertSame(WorkLogStatus::Confirmed, $log->fresh()->status);
        $this->assertSame(45, (int) $month->entries()->where('type', TimeLedgerType::Usage)->sum('minutes'));
        $this->assertSame(45, (int) $month->entries()->where('type', TimeLedgerType::CancelUsage)->sum('minutes'));
        $this->assertSame(85, $month->entries()->get()->sum(
            fn (TimeLedgerEntry $ledger): int => $ledger->minutes * $ledger->type->availableSign()
        ));
        $this->assertSame($entry->id, (new CancelWorkLogUsage)->handle($operator, $log, '중복 입력 확인')->id);
        $this->assertSame(1, TimeLedgerEntry::where('type', TimeLedgerType::CancelUsage)->count());
    }

    /** @return array<string, array{string}> */
    public static function invalidReasons(): array
    {
        return ['blank' => [' '], 'too long' => [str_repeat('x', 10001)]];
    }

    #[DataProvider('invalidReasons')]
    public function test_reason_is_required(string $reason): void
    {
        [$log, $operator] = $this->confirmed();
        $this->expectException(ValidationException::class);
        (new CancelWorkLogUsage)->handle($operator, $log, $reason);
    }

    public function test_different_retry_payload_is_rejected(): void
    {
        [$log, $operator] = $this->confirmed();
        (new CancelWorkLogUsage)->handle($operator, $log, '최초 취소 사유');
        try {
            (new CancelWorkLogUsage)->handle($operator, $log, '변경된 취소 사유');
            $this->fail('Expected payload conflict');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('work_log', $error->errors());
        }
        $this->assertSame('최초 취소 사유', TimeLedgerEntry::where('type', TimeLedgerType::CancelUsage)->sole()->reason);
    }

    public function test_customer_and_inactive_operator_cannot_cancel_usage(): void
    {
        [$log, $operator, $admin] = $this->confirmed();
        foreach ([$admin, $operator] as $index => $actor) {
            if ($index === 1) {
                DB::table('users')->where('id', $operator->id)->update(['is_active' => false]);
            }
            try {
                (new CancelWorkLogUsage)->handle($actor, $log, '권한 없음');
                $this->fail('Expected authorization failure');
            } catch (AuthorizationException) {
                $this->assertSame(0, TimeLedgerEntry::where('type', TimeLedgerType::CancelUsage)->count());
            }
        }
    }

    public function test_missing_usage_ledger_is_rejected_without_partial_write(): void
    {
        [$request, $operator] = $this->timeFixture();
        $log = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '불일치', 'minutes' => 30, 'is_billable' => true,
        ]);
        DB::table('work_logs')->where('id', $log->id)->update([
            'status' => WorkLogStatus::Confirmed->value, 'confirmed_at' => now(),
            'confirmed_by' => $operator->id, 'revision' => 2,
        ]);
        $this->expectException(ValidationException::class);
        (new CancelWorkLogUsage)->handle($operator, $log->fresh(), '원장 없음');
    }

    public function test_closed_month_usage_cannot_be_cancelled(): void
    {
        [$log, $operator, , $month] = $this->confirmed();
        (new TransitionWorkRequest)->handle($operator, $log->workRequest, WorkRequestStatus::Cancelled, '마감 준비');
        $this->travelTo($month->month->copy()->addMonth());
        (new CloseContractMonth)->handle($operator, $month);
        try {
            (new CancelWorkLogUsage)->handle($operator, $log, '마감 후 취소');
            $this->fail('Expected closed month rejection');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('month', $error->errors());
        }
        $this->assertSame(0, TimeLedgerEntry::where('type', TimeLedgerType::CancelUsage)->count());
    }
}
