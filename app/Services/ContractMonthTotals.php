<?php

namespace App\Services;

use App\Enums\TimeLedgerType;
use App\Models\TimeLedgerEntry;

class ContractMonthTotals
{
    /** @param iterable<TimeLedgerEntry> $entries
     * @return array<string, int>
     */
    public function calculate(iterable $entries): array
    {
        $totals = array_fill_keys(array_column(TimeLedgerType::cases(), 'value'), 0);
        foreach ($entries as $entry) {
            $totals[$entry->type->value] += $entry->minutes;
        }
        $totals['remaining_reserved'] = $totals['reserve'] - $totals['release'];
        $totals['net_usage'] = $totals['usage'] - $totals['cancel_usage'];
        $totals['available'] = $totals['provided'] - $totals['remaining_reserved'] - $totals['net_usage']
            + $totals['adjust_increase'] - $totals['adjust_decrease'];

        return $totals;
    }
}
