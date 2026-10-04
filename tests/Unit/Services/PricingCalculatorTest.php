<?php

namespace Tests\Unit\Services;

use App\Services\PricingCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PricingCalculatorTest extends TestCase
{
    /** @return array<string, array{int, int, int, bool, int}> */
    public static function supportedAmounts(): array
    {
        return [
            'one hour without surcharge' => [60_000, 60, 2_500, false, 60_000],
            'non urgent ignores configured surcharge' => [60_000, 90, 100_000, false, 90_000],
            'urgent surcharge' => [60_000, 90, 2_500, true, 112_500],
            'fraction rounds up once after surcharge' => [1_001, 1, 2_500, true, 21],
            'minimum rounds to one won' => [1, 1, 0, false, 1],
            'supported maximum remains within integer range' => [100_000_000, 10_000_000, 100_000, true, 183_333_333_333_334],
        ];
    }

    #[DataProvider('supportedAmounts')]
    public function test_calculates_integer_won_with_one_final_round_up(
        int $hourlyRate,
        int $minutes,
        int $surchargeBps,
        bool $urgent,
        int $expected,
    ): void {
        $this->assertSame($expected, (new PricingCalculator)->calculate(
            $hourlyRate,
            $minutes,
            $surchargeBps,
            $urgent,
        ));
    }

    /** @return array<string, array{int, int, int}> */
    public static function invalidInputs(): array
    {
        return [
            'zero rate' => [0, 1, 0],
            'rate above maximum' => [100_000_001, 1, 0],
            'zero minutes' => [1, 0, 0],
            'minutes above maximum' => [1, 10_000_001, 0],
            'negative surcharge' => [1, 1, -1],
            'surcharge above maximum' => [1, 1, 100_001],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_values_outside_the_supported_domain(int $hourlyRate, int $minutes, int $surchargeBps): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PricingCalculator)->calculate($hourlyRate, $minutes, $surchargeBps, true);
    }
}
