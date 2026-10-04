<?php

namespace App\Actions;

use App\Enums\CompanyStatus;
use App\Enums\TimeLedgerType;
use App\Enums\UserRole;
use App\Enums\WorkLogStatus;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\ContractMonth;
use App\Models\EstimateApproval;
use App\Models\EstimateVersion;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use App\Services\ContractMonthTotals;
use App\Services\ContractMonthWorkTotals;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RunCustomerADemoWorkflow
{
    public const CUSTOMER_ADMIN_NAME = '데모 고객사 A 관리자';

    public const CUSTOMER_B_MARKER = '포트폴리오 데모 시나리오 · 고객사 B';

    public const WORK_LOG_DESCRIPTION = '고객 포털 알림 설정 화면과 저장 처리를 구현하고 모바일 표시를 검증했습니다.';

    public const WORKED_MINUTES = 150;

    public function approve(User $actor): EstimateApproval
    {
        [$request, $estimate] = $this->requestAndEstimate();
        $existing = EstimateApproval::query()->where('estimate_version_id', $estimate->id)->first();

        if ($existing instanceof EstimateApproval) {
            $this->assertReservation($request, $estimate, $existing);

            return $existing;
        }

        if ($request->status !== WorkRequestStatus::AwaitingApproval) {
            throw ValidationException::withMessages(['status' => '승인 대기 상태의 4.28 데모 견적이 필요합니다.']);
        }

        $approval = (new ApproveEstimateVersion)->handle(
            $this->customerAdmin($request, $actor),
            $estimate,
            (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT,
            '127.0.0.1',
            'APPROID Desk portfolio demo',
        );
        $this->assertReservation($request->fresh(), $estimate, $approval);

        return $approval;
    }

    public function start(User $actor): WorkRequest
    {
        [$request] = $this->requestAndEstimate();
        $this->approve($actor);
        $request->refresh();

        if ($request->status === WorkRequestStatus::Queued) {
            (new TransitionWorkRequest)->handle($actor, $request, WorkRequestStatus::InProgress);
            $request->refresh();
        }

        if (! in_array($request->status, [
            WorkRequestStatus::InProgress,
            WorkRequestStatus::AwaitingReview,
            WorkRequestStatus::Completed,
        ], true)) {
            throw ValidationException::withMessages(['status' => '승인과 계약 확인 뒤에만 데모 작업을 시작할 수 있습니다.']);
        }
        if (! $request->serviceContract()->firstOrFail()->permitsWork()) {
            throw ValidationException::withMessages(['contract' => '서명 확인된 유효한 데모 계약이 필요합니다.']);
        }

        return $request;
    }

    public function recordUsage(User $actor): WorkLog
    {
        $request = $this->start($actor);
        $existing = WorkLog::query()
            ->where('work_request_id', $request->id)
            ->where('description', self::WORK_LOG_DESCRIPTION)
            ->first();

        if ($existing instanceof WorkLog) {
            if ($existing->minutes !== self::WORKED_MINUTES || ! $existing->is_billable) {
                throw ValidationException::withMessages(['work_log' => '기존 데모 작업기록의 시간과 차감 구분을 확인해 주세요.']);
            }
            if ($existing->status === WorkLogStatus::Draft) {
                return (new ConfirmWorkLog)->handle($actor, $existing, $existing->revision);
            }

            $this->assertUsage($request, $existing);

            return $existing;
        }

        if ($request->status !== WorkRequestStatus::InProgress) {
            throw ValidationException::withMessages(['status' => '진행 중인 데모 요청에서만 작업시간을 기록할 수 있습니다.']);
        }

        $estimate = $request->approvedEstimateVersion()->firstOrFail();
        $workedOn = $estimate->usage_month->copy()->endOfMonth()->min(today())->toDateString();
        $log = (new SaveWorkLogDraft)->handle($actor, $request, [
            'worked_on' => $workedOn,
            'description' => self::WORK_LOG_DESCRIPTION,
            'minutes' => self::WORKED_MINUTES,
            'is_billable' => true,
        ]);
        $log = (new ConfirmWorkLog)->handle($actor, $log, $log->revision);
        $this->assertUsage($request, $log);

        return $log;
    }

    public function complete(User $actor): WorkRequest
    {
        $log = $this->recordUsage($actor);
        $request = $log->workRequest()->firstOrFail();

        if ($request->status === WorkRequestStatus::InProgress) {
            (new TransitionWorkRequest)->handle($actor, $request, WorkRequestStatus::AwaitingReview);
            $request->refresh();
        }
        if ($request->status === WorkRequestStatus::AwaitingReview) {
            (new TransitionWorkRequest)->handle($this->customerAdmin($request, $actor), $request, WorkRequestStatus::Completed);
            $request->refresh();
        }
        if ($request->status !== WorkRequestStatus::Completed) {
            throw ValidationException::withMessages(['status' => '검수 완료된 데모 요청이 필요합니다.']);
        }

        $month = $this->contractMonth($request);
        $totals = (new ContractMonthTotals)->calculate($month->entries()->get());
        if ($totals['remaining_reserved'] !== 0 || $totals['release'] !== SubmitCustomerADemoEstimate::ESTIMATED_MINUTES) {
            throw ValidationException::withMessages(['ledger' => '150분 사용 뒤 남은 예약 30분이 정확히 반환되지 않았습니다.']);
        }

        return $request;
    }

    /** @return array{month: ContractMonth, ledger: array<string, int>, work: array<string, int>} */
    public function verifyUsage(User $actor): array
    {
        $request = $this->complete($actor);
        $month = $this->contractMonth($request);
        $ledger = (new ContractMonthTotals)->calculate($month->entries()->get());
        $work = (new ContractMonthWorkTotals)->calculate($month);

        if ($ledger['provided'] !== CreateCustomerADemoRequest::PROVIDED_MINUTES
            || $ledger['reserve'] !== SubmitCustomerADemoEstimate::ESTIMATED_MINUTES
            || $ledger['release'] !== SubmitCustomerADemoEstimate::ESTIMATED_MINUTES
            || $ledger['net_usage'] !== self::WORKED_MINUTES
            || $ledger['remaining_reserved'] !== 0
            || $ledger['available'] !== CreateCustomerADemoRequest::PROVIDED_MINUTES - self::WORKED_MINUTES
            || $work['billable_minutes'] !== self::WORKED_MINUTES
            || $work['customer_charged_minutes'] !== self::WORKED_MINUTES) {
            throw ValidationException::withMessages(['usage' => '월 사용내역의 150분 차감과 원장 합계가 예상과 다릅니다.']);
        }

        return compact('month', 'ledger', 'work');
    }

    public function verifyIsolation(User $actor): Company
    {
        $usage = $this->verifyUsage($actor);
        [$request, $estimate] = $this->requestAndEstimate();
        $log = WorkLog::query()->where('work_request_id', $request->id)
            ->where('description', self::WORK_LOG_DESCRIPTION)->firstOrFail();
        $company = Company::query()->firstOrCreate(
            ['notes' => self::CUSTOMER_B_MARKER],
            [
                'name' => '데모 고객사 B',
                'status' => CompanyStatus::Active,
                'primary_contact_name' => '이담당',
                'primary_contact_email' => 'customer-b@example.test',
                'primary_contact_phone' => null,
            ],
        );
        $foreignUser = new User;
        $foreignUser->forceFill([
            'company_id' => $company->id,
            'role' => UserRole::CustomerAdmin,
            'is_active' => true,
        ]);
        $gate = Gate::forUser($foreignUser);

        if ($gate->allows('view', $request)
            || $gate->allows('view', $estimate)
            || $gate->allows('view', $log)
            || $gate->allows('view', $usage['month'])
            || WorkRequest::query()->visibleTo($foreignUser)->whereKey($request)->exists()
            || ContractMonth::query()->visibleTo($foreignUser)->whereKey($usage['month'])->exists()) {
            throw ValidationException::withMessages(['isolation' => '고객사 B에서 고객사 A 자료가 노출되었습니다.']);
        }

        return $company;
    }

    private function customerAdmin(WorkRequest $request, User $actor): User
    {
        $email = Str::lower(
            Str::beforeLast($actor->email, '@').'+desk-demo-customer-a@'.Str::afterLast($actor->email, '@'),
        );
        $admin = User::query()
            ->where('company_id', $request->company_id)
            ->where('name', self::CUSTOMER_ADMIN_NAME)
            ->first() ?? User::query()->where('email', $email)->first();
        if (! $admin instanceof User) {
            $admin = new User;
            $admin->forceFill([
                'company_id' => $request->company_id,
                'role' => UserRole::CustomerAdmin,
                'is_active' => true,
                'name' => self::CUSTOMER_ADMIN_NAME,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(64)),
            ])->save();
        }
        if ($admin->company_id !== $request->company_id
            || $admin->role !== UserRole::CustomerAdmin
            || ! $admin->is_active) {
            throw ValidationException::withMessages(['approver' => '데모 고객사 관리자 계정의 소속과 상태를 확인해 주세요.']);
        }

        return $admin;
    }

    /** @return array{WorkRequest, EstimateVersion} */
    private function requestAndEstimate(): array
    {
        $request = WorkRequest::query()
            ->where('source_reference', CreateCustomerADemoRequest::REQUEST_REFERENCE)
            ->first();
        if (! $request instanceof WorkRequest) {
            throw ValidationException::withMessages(['demo_request' => '4.27 고객사 A 데모 요청을 먼저 생성해 주세요.']);
        }
        $estimate = $request->estimateVersions()
            ->where('included_scope', SubmitCustomerADemoEstimate::INCLUDED_SCOPE)
            ->first();
        if (! $estimate instanceof EstimateVersion || $estimate->submitted_at === null
            || $estimate->estimated_minutes !== SubmitCustomerADemoEstimate::ESTIMATED_MINUTES) {
            throw ValidationException::withMessages(['estimate' => '4.28 제출 완료 상태의 180분 데모 견적이 필요합니다.']);
        }

        return [$request, $estimate];
    }

    private function contractMonth(WorkRequest $request): ContractMonth
    {
        $estimate = $request->approvedEstimateVersion()->firstOrFail();

        return ContractMonth::query()
            ->where('service_contract_id', $request->service_contract_id)
            ->whereDate('month', $estimate->usage_month)
            ->firstOrFail();
    }

    private function assertReservation(WorkRequest $request, EstimateVersion $estimate, EstimateApproval $approval): void
    {
        $reserved = (int) TimeLedgerEntry::query()
            ->where('work_request_id', $request->id)
            ->where('type', TimeLedgerType::Reserve)
            ->where('source_type', 'estimate_approval')
            ->where('source_id', $approval->id)
            ->sum('minutes');
        if ($request->approved_estimate_version_id !== $estimate->id
            || $reserved !== SubmitCustomerADemoEstimate::ESTIMATED_MINUTES) {
            throw ValidationException::withMessages(['reservation' => '견적 승인과 180분 예약 원장이 일치하지 않습니다.']);
        }
    }

    private function assertUsage(WorkRequest $request, WorkLog $log): void
    {
        $usage = (int) TimeLedgerEntry::query()
            ->where('work_request_id', $request->id)
            ->where('type', TimeLedgerType::Usage)
            ->where('source_type', 'work_log')
            ->where('source_id', $log->id)
            ->sum('minutes');
        if ($log->status !== WorkLogStatus::Confirmed || $usage !== self::WORKED_MINUTES) {
            throw ValidationException::withMessages(['usage' => '데모 작업 150분이 한 번만 확정되지 않았습니다.']);
        }
    }
}
