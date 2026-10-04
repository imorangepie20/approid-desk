<?php

namespace Tests\Feature;

use App\Actions\AdjustContractMonth;
use App\Actions\ApproveEstimateVersion;
use App\Actions\CancelWorkLogUsage;
use App\Actions\CloseContractMonth;
use App\Actions\ConfirmNonBillableWorkLog;
use App\Actions\ConfirmWorkLog;
use App\Actions\ProvideContractMonth;
use App\Actions\SaveWorkLogDraft;
use App\Enums\TimeLedgerType;
use App\Enums\UserRole;
use App\Models\ServiceContract;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class MonthlyUsageExportTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    /** @return array<int, array<int, string|null>> */
    private function rows(TestResponse $response): array
    {
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function log(User $actor, WorkRequest $request, int $minutes, string $description, bool $billable): WorkLog
    {
        $log = (new SaveWorkLogDraft)->handle($actor, $request, ['worked_on' => today()->toDateString(),
            'minutes' => $minutes, 'description' => $description, 'is_billable' => $billable,
            'non_billable_reason' => $billable ? null : '내부 귀책 사유']);
        ($billable ? new ConfirmWorkLog : new ConfirmNonBillableWorkLog)->handle($actor, $log, 1);

        return $log;
    }

    /** @return array<string, array{UserRole, bool}> */
    public static function roles(): array
    {
        return ['super' => [UserRole::SuperAdmin, true], 'operator' => [UserRole::Operator, true],
            'admin' => [UserRole::CustomerAdmin, true], 'user' => [UserRole::CustomerUser, false]];
    }

    #[DataProvider('roles')]
    public function test_export_requires_usage_permission_and_company_scope(UserRole $role, bool $allowed): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $foreign = (new ProvideContractMonth)->handle($operator, ServiceContract::factory()->signed()->create(), today()->startOfMonth()->toDateString(), 99);
        $user = User::factory()->create(['role' => $role, 'company_id' => $role->isSystemRole() ? null : $request->company_id]);
        foreach (['usage', 'ledger'] as $tab) {
            $url = route('usage.export', [$month, 'tab' => $tab]);
            $this->actingAs($user)->get($url)->assertStatus($allowed ? 200 : 403);
            $this->get(route('usage.export', [$foreign, 'tab' => $tab]))->assertStatus($role->isSystemRole() ? 200 : 403);
        }
    }

    public function test_guest_inactive_revoked_and_inactive_company_cannot_export(): void
    {
        [$request, $operator, $admin, $month] = $this->timeFixture();
        $url = route('usage.export', $month);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($operator);
        User::query()->whereKey($operator->id)->update(['is_active' => false]);
        $this->get($url)->assertForbidden();
        $this->actingAs($admin);
        User::query()->whereKey($admin->id)->update(['role' => UserRole::CustomerUser]);
        $this->get($url)->assertForbidden();
        $active = User::factory()->customerAdmin()->for($request->company)->create();
        $request->company->update(['status' => 'inactive']);
        $this->actingAs($active)->get($url)->assertForbidden();
    }

    public function test_usage_csv_preserves_text_and_cancellation_but_hides_internal_work_from_customers(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture(200, 180);
        $request->update(['title' => "요청, \"인용\"\n두 번째 줄"]);
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'CSV test');
        $cancelled = $this->log($operator, $request, 30, '취소 작업', true);
        (new CancelWorkLogUsage)->handle($operator, $cancelled, '내부 취소 사유');
        $this->log($operator, $request, 150, "내용, \"검토\"\r\n경로 \\files", true);
        $this->log($operator, $request, 20, '내부 비차감 작업', false);
        (new SaveWorkLogDraft)->handle($operator, $request, ['worked_on' => today()->toDateString(), 'minutes' => 5,
            'description' => '초안 제외', 'is_billable' => true]);
        $response = $this->actingAs($admin)->get(route('usage.export', $month));
        $response->assertDownload('monthly-usage-'.$month->month->format('Y-m').'-'.$month->id.'.csv');
        $rows = $this->rows($response);
        $this->assertCount(3, $rows);
        $this->assertCount(11, $rows[0]);
        $this->assertSame($request->title, $rows[1][5]);
        $this->assertSame("내용, \"검토\"\r\n경로 \\files", $rows[1][7]);
        $this->assertSame('150', $rows[1][10]);
        $this->assertSame('사용 취소', $rows[2][8]);
        $this->assertSame('30', $rows[2][9]);
        $this->assertSame('0', $rows[2][10]);
        $this->assertStringNotContainsString('내부', $response->streamedContent());
        $operatorRows = $this->rows($this->actingAs($operator)->get(route('usage.export', $month)));
        $this->assertCount(4, $operatorRows);
        $this->assertCount(13, $operatorRows[0]);
        $this->assertSame('비차감', $operatorRows[1][8]);
        $this->assertSame('0', $operatorRows[1][10]);
        $this->assertSame('내부 귀책 사유', $operatorRows[1][12]);
    }

    public function test_ledger_csv_includes_closed_month_adjustments_and_obeys_filter_and_privacy(): void
    {
        [, $operator, $admin, $month] = $this->timeFixture();
        $this->travelTo($month->month->copy()->addMonth());
        (new CloseContractMonth)->handle($operator, $month);
        (new AdjustContractMonth)->handle($operator, $month, $month->entries()->sole(), TimeLedgerType::AdjustDecrease, 25, '내부 정정 사유', (string) Str::uuid());
        $url = route('usage.export', [$month, 'tab' => 'ledger']);
        $response = $this->actingAs($admin)->get($url);
        $rows = $this->rows($response);
        $this->assertCount(3, $rows);
        $this->assertCount(10, $rows[0]);
        $this->assertSame('조정 감소', $rows[1][5]);
        $this->assertSame('-25', $rows[1][7]);
        $this->assertStringNotContainsString('내부', $response->streamedContent());
        $this->assertSame(75, array_sum(array_map(fn ($row) => (int) $row[7], array_slice($rows, 1))));
        $operatorRows = $this->rows($this->actingAs($operator)->get($url));
        $this->assertCount(14, $operatorRows[0]);
        $this->assertSame($operator->name, $operatorRows[1][10]);
        $this->assertSame('내부 정정 사유', $operatorRows[1][13]);
        $filtered = $this->rows($this->get(route('usage.export', [$month, 'tab' => 'ledger', 'type' => 'provided', 'page' => 2])));
        $this->assertCount(2, $filtered);
        $this->assertSame('제공', $filtered[1][5]);
    }

    public function test_csv_neutralizes_formula_prefixes_in_all_untrusted_text_columns(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $request->company->update(['name' => '=1+2']);
        $request->update(['title' => '+SUM(1,2)']);
        $operator->update(['name' => '@operator']);
        $prefixes = ['=1+2', '+1+2', '-1+2', '@SUM(1,2)', "\t=1+2", "\r=1+2", "\n=1+2", '  =1+2', "\u{FEFF}=1+2"];
        foreach ($prefixes as $prefix) {
            $this->log($operator, $request, 1, $prefix, false);
        }
        $rows = $this->rows($this->actingAs($operator)->get(route('usage.export', $month)));
        foreach (array_slice($rows, 1) as $index => $row) {
            $this->assertSame("'=1+2", $row[1]);
            $this->assertSame("'+SUM(1,2)", $row[5]);
            $this->assertSame("'".trim(array_reverse($prefixes)[$index]), $row[7]);
            $this->assertSame("'@operator", $row[11]);
        }
        foreach ($prefixes as $prefix) {
            $request->company->update(['name' => $prefix]);
            $rows = $this->rows($this->get(route('usage.export', $month)));
            $this->assertSame("'".$prefix, $rows[1][1]);
        }
    }

    public function test_csv_exports_across_chunks_without_page_limits_and_excludes_other_contracts_and_months(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $prototype = $this->log($operator, $request, 1, 'bulk', false)->fresh()->getAttributes();
        unset($prototype['id']);
        // Valid synthetic confirmed rows exercise the export batch boundary without 500 unrelated confirmations.
        DB::table('work_logs')->insert(array_fill(0, 500, $prototype));
        $other = WorkRequest::factory()->withSignedContract()->for($request->company)->create();
        (new ProvideContractMonth)->handle($operator, $other->serviceContract, today()->startOfMonth()->toDateString(), 100);
        $this->log($operator, $other, 1, '타 계약 제외', false);
        $this->travelTo($month->month->copy()->addMonth());
        (new ProvideContractMonth)->handle($operator, $request->serviceContract, today()->startOfMonth()->toDateString(), 100);
        $this->log($operator, $request, 1, '타 월 제외', false);
        $rows = $this->rows($this->actingAs($operator)->get(route('usage.export', [$month, 'page' => 2])));
        $this->assertCount(502, $rows);
        $this->assertCount(501, array_unique(array_column(array_slice($rows, 1), 3)));
        $this->assertSame(['bulk'], array_unique(array_column(array_slice($rows, 1), 7)));
    }

    public function test_empty_csv_invalid_filters_unknown_month_and_navigation(): void
    {
        [, , $admin, $month] = $this->timeFixture();
        $this->actingAs($admin)->get(route('usage.show', [$month, 'tab' => 'ledger', 'type' => 'usage', 'page' => 2]))
            ->assertSee(e(route('usage.export', [$month, 'tab' => 'ledger', 'type' => 'usage'])), false);
        foreach (['usage', 'ledger'] as $tab) {
            $rows = $this->rows($this->get(route('usage.export', [$month, 'tab' => $tab, 'type' => 'usage'])));
            $this->assertCount(1, $rows);
        }
        $this->get(route('usage.export', [$month, 'tab' => 'invalid', 'type' => 'invalid']))->assertSessionHasErrors(['tab', 'type']);
        $this->get(route('usage.export', 999999))->assertNotFound();
    }
}
