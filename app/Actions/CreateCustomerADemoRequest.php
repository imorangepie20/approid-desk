<?php

namespace App\Actions;

use App\Enums\CompanyStatus;
use App\Enums\IntakeChannel;
use App\Enums\ProjectStatus;
use App\Enums\ServiceContractStatus;
use App\Enums\ServiceContractType;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use App\Models\Company;
use App\Models\Project;
use App\Models\ServiceContract;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CreateCustomerADemoRequest
{
    public const COMPANY_MARKER = '포트폴리오 데모 시나리오 · 고객사 A';

    public const PROJECT_MARKER = '포트폴리오 데모 시나리오 A의 기준 프로젝트';

    public const CONTRACT_PATH = 'contracts/demo/customer-a-maintenance.pdf';

    public const REQUEST_REFERENCE = '포트폴리오 데모 시나리오 A-001';

    public const PROVIDED_MINUTES = 1200;

    public function handle(User $actor): WorkRequest
    {
        return DB::transaction(function () use ($actor): WorkRequest {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);

            if (! $actor->is_active || ! $actor->role->isSystemRole()) {
                throw new AuthorizationException('활성 운영 계정만 데모 요청을 만들 수 있습니다.');
            }

            $existing = WorkRequest::query()
                ->where('source_reference', self::REQUEST_REFERENCE)
                ->first();

            if ($existing instanceof WorkRequest) {
                return $existing;
            }

            $company = Company::query()->firstOrCreate(
                ['notes' => self::COMPANY_MARKER],
                [
                    'name' => '데모 고객사 A',
                    'status' => CompanyStatus::Active,
                    'primary_contact_name' => '김담당',
                    'primary_contact_email' => 'customer-a@example.test',
                    'primary_contact_phone' => null,
                ],
            );

            $project = Project::query()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'technical_notes' => self::PROJECT_MARKER,
                ],
                [
                    'name' => '고객 포털 개선',
                    'description' => '견적 승인부터 작업 완료와 월 사용내역까지 시연하는 포트폴리오 프로젝트',
                    'status' => ProjectStatus::Active,
                    'site_url' => null,
                    'is_existing_site' => true,
                    'source_code_secured' => true,
                    'database_dump_secured' => true,
                ],
            );

            $contract = ServiceContract::query()
                ->where('company_id', $company->id)
                ->where('document_path', self::CONTRACT_PATH)
                ->first();

            if (! $contract instanceof ServiceContract) {
                $contract = new ServiceContract;
                $contract->forceFill([
                    'company_id' => $company->id,
                    'type' => ServiceContractType::Maintenance,
                    'starts_on' => today()->startOfMonth(),
                    'ends_on' => today()->startOfMonth()->addYear()->subDay(),
                    'status' => ServiceContractStatus::Active,
                    'document_path' => self::CONTRACT_PATH,
                    'signature_confirmed_at' => now(),
                    'signature_confirmed_by' => $actor->id,
                ])->save();
            }

            (new ProvideContractMonth)->handle(
                $actor,
                $contract,
                today()->startOfMonth()->toDateString(),
                self::PROVIDED_MINUTES,
            );

            $registeredAt = now();

            return WorkRequest::query()->create([
                'company_id' => $company->id,
                'project_id' => $project->id,
                'service_contract_id' => $contract->id,
                'submitted_by' => $actor->id,
                'assigned_to' => null,
                'parent_request_id' => null,
                'title' => '고객 포털 알림 설정 개선',
                'requirements' => implode("\n", [
                    '고객 포털에서 업무 알림 수신 여부와 채널을 직접 설정할 수 있게 개선합니다.',
                    '- 이메일과 서비스 내 알림을 각각 켜고 끌 수 있어야 합니다.',
                    '- 저장한 설정은 다시 접속해도 유지되어야 합니다.',
                    '- 모바일 화면에서도 설정 항목과 저장 버튼이 겹치지 않아야 합니다.',
                ]),
                'type' => WorkRequestType::Feature,
                'priority' => WorkRequestPriority::Normal,
                'is_urgent' => false,
                'desired_due_date' => today()->addWeeks(2),
                'intake_channel' => IntakeChannel::Other,
                'source_reference' => self::REQUEST_REFERENCE,
                'intake_summary' => '포트폴리오 전체 업무 흐름을 재현하기 위한 합성 데모 요청',
                'status' => WorkRequestStatus::Received,
                'requested_at' => $registeredAt,
                'registered_at' => $registeredAt,
                'late_entry_reason' => null,
            ]);
        }, 5);
    }
}
