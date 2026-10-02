<?php

namespace Tests\Unit\Enums;

use App\Enums\WorkRequestStatus;
use PHPUnit\Framework\TestCase;

class WorkRequestStatusTest extends TestCase
{
    public function test_statuses_use_stable_database_values_and_korean_labels(): void
    {
        $expected = [
            'received' => '접수',
            'estimating' => '견적 중',
            'awaiting_approval' => '승인 대기',
            'queued' => '작업 대기',
            'in_progress' => '진행 중',
            'awaiting_review' => '검수 대기',
            'completed' => '완료',
            'on_hold' => '보류',
            'cancelled' => '취소',
        ];

        $actual = [];

        foreach (WorkRequestStatus::cases() as $status) {
            $actual[$status->value] = $status->label();
        }

        $this->assertSame($expected, $actual);
    }

    public function test_only_completed_and_cancelled_are_terminal_statuses(): void
    {
        $this->assertTrue(WorkRequestStatus::Completed->isTerminal());
        $this->assertTrue(WorkRequestStatus::Cancelled->isTerminal());

        $this->assertFalse(WorkRequestStatus::Received->isTerminal());
        $this->assertFalse(WorkRequestStatus::InProgress->isTerminal());
        $this->assertFalse(WorkRequestStatus::OnHold->isTerminal());
    }
}
