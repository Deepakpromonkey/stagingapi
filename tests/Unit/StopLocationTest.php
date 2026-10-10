<?php

namespace Tests\Unit;

use App\Support\StopLocation;
use PHPUnit\Framework\TestCase;

/**
 * The rule the driver side applies to a stop's coordinates: a latitude from
 * -90 to 90 and a longitude from -180 to 180, anything else is no location.
 */
class StopLocationTest extends TestCase
{
    public function test_latitudes_the_driver_side_can_use(): void
    {
        foreach ([0, 90, -90, 28.4198677, -33.8688, '28.4198677', ' 45 ', '-90'] as $value) {
            $this->assertTrue(StopLocation::isLatitude($value), var_export($value, true).' should be a latitude');
        }
    }

    public function test_latitudes_it_cannot(): void
    {
        foreach ([90.0000001, -90.5, 91, null, '', '   ', 'abc', '12abc', true, false, [], INF, NAN] as $value) {
            $this->assertFalse(StopLocation::isLatitude($value), var_export($value, true).' should not be a latitude');
        }
    }

    public function test_longitudes_the_driver_side_can_use(): void
    {
        foreach ([0, 180, -180, 77.0382266, -96.944, '77.0382266', '-180'] as $value) {
            $this->assertTrue(StopLocation::isLongitude($value), var_export($value, true).' should be a longitude');
        }
    }

    public function test_longitudes_it_cannot(): void
    {
        foreach ([180.0000001, -181, 360, null, '', 'abc', true, [], INF, -INF, NAN] as $value) {
            $this->assertFalse(StopLocation::isLongitude($value), var_export($value, true).' should not be a longitude');
        }
    }

    public function test_numbers_come_back_as_floats(): void
    {
        $this->assertSame(28.4198677, StopLocation::number('28.4198677'));
        $this->assertSame(-90.0, StopLocation::number(-90));
        $this->assertNull(StopLocation::number('abc'));
        $this->assertNull(StopLocation::number(true));
    }
}
