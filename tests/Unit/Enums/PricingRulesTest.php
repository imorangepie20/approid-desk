<?php

namespace Tests\Unit\Enums;

use App\Enums\PricingDecision;
use App\Enums\WorkDifficulty;
use PHPUnit\Framework\TestCase;

class PricingRulesTest extends TestCase
{
    public function test_difficulties_use_stable_values_and_labels(): void
    {
        $this->assertSame([
            'low' => '낮음',
            'normal' => '보통',
            'high' => '높음',
        ], $this->valuesAndLabels(WorkDifficulty::cases()));
    }

    public function test_decisions_use_stable_values_and_labels(): void
    {
        $this->assertSame([
            'feasible' => '견적 가능',
            'rejected' => '거절',
            'renegotiation' => '재협의',
        ], $this->valuesAndLabels(PricingDecision::cases()));
    }

    /**
     * @param  list<WorkDifficulty|PricingDecision>  $cases
     * @return array<string, string>
     */
    private function valuesAndLabels(array $cases): array
    {
        $result = [];

        foreach ($cases as $case) {
            $result[$case->value] = $case->label();
        }

        return $result;
    }
}
