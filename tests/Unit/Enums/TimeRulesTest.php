<?php

namespace Tests\Unit\Enums;

use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use PHPUnit\Framework\TestCase;

class TimeRulesTest extends TestCase
{
    public function test_work_log_statuses_use_stable_values(): void
    {
        $this->assertSame(['draft', 'confirmed'], array_column(WorkLogStatus::cases(), 'value'));
    }

    public function test_ledger_types_use_stable_values_labels_and_available_balance_signs(): void
    {
        $actual = [];

        foreach (TimeLedgerType::cases() as $type) {
            $actual[$type->value] = [$type->label(), $type->availableSign()];
        }

        $this->assertSame([
            'provided' => ['제공', 1],
            'reserve' => ['예약', -1],
            'release' => ['예약 해제', 1],
            'usage' => ['실사용', -1],
            'cancel_usage' => ['사용 취소', 1],
            'adjust_increase' => ['조정 증가', 1],
            'adjust_decrease' => ['조정 감소', -1],
        ], $actual);
    }
}
