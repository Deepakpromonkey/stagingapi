<?php

namespace Tests\Unit;

use App\Support\Vin;
use PHPUnit\Framework\TestCase;

/**
 * The pattern is the load-bearing idea in the VIN cache: if it were wrong, two
 * different trucks could collapse onto one cache entry and the fleet table
 * would show the wrong year for one of them.
 */
class VinPatternTest extends TestCase
{
    public function test_pattern_is_positions_one_to_eight_and_ten(): void
    {
        // 1FUJGLDR 9 C LBP8834
        //  |_ 1-8   |  |_ 10 (model year code)
        //           |_ 9 (check digit, dropped)
        $this->assertSame('1FUJGLDRC', Vin::pattern('1FUJGLDR9CLBP8834'));
    }

    public function test_units_differing_only_in_serial_share_a_pattern(): void
    {
        $this->assertSame(
            Vin::pattern('1FUJGLDR9CLBP8834'),
            Vin::pattern('1FUJGLDR2CLBS9911'),
        );
    }

    public function test_units_of_a_different_model_year_do_not_share_a_pattern(): void
    {
        // Position 10 is C (2012) against D (2013).
        $this->assertNotSame(
            Vin::pattern('1FUJGLDR9CLBP8834'),
            Vin::pattern('1FUJGLDR9DLBP8834'),
        );
    }

    public function test_pattern_converts_to_the_partial_vin_vpic_expects(): void
    {
        $this->assertSame('1FUJGLDR*C', Vin::toPartialVin(Vin::pattern('1FUJGLDR9CLBP8834')));
    }

    public function test_normalize_strips_separators_and_uppercases(): void
    {
        $this->assertSame('1FUJGLDR9CLBP8834', Vin::normalize(' 1fujgldr9-clbp8834 '));
    }

    /**
     * @dataProvider invalidVins
     */
    public function test_invalid_vins_have_no_pattern(?string $vin): void
    {
        $this->assertNull(Vin::pattern($vin));
    }

    public static function invalidVins(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'too short' => ['1FUJGLDR9CLBP88'],
            'too long' => ['1FUJGLDR9CLBP88345'],
            // The feed uses runs of zeros to mean "no VIN recorded".
            'zero placeholder' => ['00000000000000000'],
            /*
             * Well-formed on their face but still not VINs: position 1 is the
             * geographic area and zero is not an assigned value. Real examples
             * pulled from the inspections feed.
             */
            'zero-padded serial' => ['00000000041005122'],
            'zero-padded alnum' => ['000000000AZ387696'],
            // I, O and Q are never used in a VIN.
            'contains letter O' => ['1FUJGLDR9CLBPO834'],
            'contains letter I' => ['1FUJGLDR9CLBPI834'],
        ];
    }

    /**
     * PHP casts a numeric-string array key to an integer, and these patterns
     * are deduplicated through array keys. An integer bound against the
     * CHAR(9) pattern column puts MySQL into numeric context: the primary key
     * index is abandoned and, under strict mode, the query dies on the first
     * non-numeric pattern it meets.
     */
    public function test_all_digit_patterns_stay_strings(): void
    {
        $patterns = Vin::patterns(['20240322341005122', '1FUJGLDR9CLBP8834']);

        foreach ($patterns as $pattern) {
            $this->assertIsString($pattern, 'a numeric pattern leaked as an integer');
        }

        $this->assertContains('202403224', $patterns);
    }

    public function test_patterns_deduplicates_across_a_fleet(): void
    {
        $patterns = Vin::patterns([
            '1FUJGLDR9CLBP8834',
            '1FUJGLDR2CLBS9911',  // same spec, different truck
            '1XPBDP9X1MD756743',
            null,
            '00000000000000000',
        ]);

        $this->assertCount(2, $patterns);
    }
}
