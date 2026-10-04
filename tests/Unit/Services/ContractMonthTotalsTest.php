<?php

namespace Tests\Unit\Services;

use App\Enums\TimeLedgerType;
use App\Models\TimeLedgerEntry;
use App\Services\ContractMonthTotals;
use PHPUnit\Framework\TestCase;

class ContractMonthTotalsTest extends TestCase
{
    public function test_empty_ledger_returns_every_supported_total_as_zero(): void
    {
        $this->assertSame([
            'provided' => 0,
            'reserve' => 0,
            'release' => 0,
            'usage' => 0,
            'cancel_usage' => 0,
            'adjust_increase' => 0,
            'adjust_decrease' => 0,
            'remaining_reserved' => 0,
            'net_usage' => 0,
            'available' => 0,
        ], (new ContractMonthTotals)->calculate([]));
    }

    public function test_ledger_totals_apply_reservations_usage_cancellations_and_adjustments_once(): void
    {
        $entries = [
            $this->entry(TimeLedgerType::Provided, 100),
            $this->entry(TimeLedgerType::Reserve, 25),
            $this->entry(TimeLedgerType::Reserve, 15),
            $this->entry(TimeLedgerType::Release, 10),
            $this->entry(TimeLedgerType::Usage, 20),
            $this->entry(TimeLedgerType::CancelUsage, 5),
            $this->entry(TimeLedgerType::AdjustIncrease, 7),
            $this->entry(TimeLedgerType::AdjustDecrease, 2),
        ];

        $this->assertSame([
            'provided' => 100,
            'reserve' => 40,
            'release' => 10,
            'usage' => 20,
            'cancel_usage' => 5,
            'adjust_increase' => 7,
            'adjust_decrease' => 2,
            'remaining_reserved' => 30,
            'net_usage' => 15,
            'available' => 60,
        ], (new ContractMonthTotals)->calculate($entries));
    }

    private function entry(TimeLedgerType $type, int $minutes): TimeLedgerEntry
    {
        return (new TimeLedgerEntry)->forceFill(['type' => $type, 'minutes' => $minutes]);
    }
}
