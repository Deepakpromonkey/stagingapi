<?php

namespace Tests\Unit;

use App\Support\LoadWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * When a load holds its driver, read from the times the booking forms store:
 * a date, a time and a timezone label, per time.
 */
class LoadWindowTest extends TestCase
{
    private function tripSheet(array $pickup, array $delivery): ?LoadWindow
    {
        return LoadWindow::fromStops([
            ['stop_type' => 'Pickup', 'start_date' => $pickup[0], 'start_time' => $pickup[1], 'start_timezone' => $pickup[2],
                'end_date' => '', 'end_time' => '', 'end_timezone' => ''],
            ['stop_type' => 'Delivery', 'start_date' => '', 'start_time' => '', 'start_timezone' => '',
                'end_date' => $delivery[0], 'end_time' => $delivery[1], 'end_timezone' => $delivery[2]],
        ]);
    }

    private function utc(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i');
    }

    public function test_a_trip_sheet_runs_from_its_pickup_to_its_delivery_in_their_own_zones(): void
    {
        $window = $this->tripSheet(['2026-10-20', '09:00', 'Central'], ['2026-10-22', '17:00', 'Eastern']);

        // Daylight saving: CDT is UTC-5 and EDT UTC-4 in October.
        $this->assertSame('2026-10-20 14:00', $this->utc($window->start));
        $this->assertSame('2026-10-22 21:00', $this->utc($window->end));
        $this->assertSame('from Oct 20, 2026 9:00 AM CDT to Oct 22, 2026 5:00 PM EDT', $window->describe());
    }

    public function test_standard_time_after_the_clocks_go_back(): void
    {
        $window = $this->tripSheet(['2026-12-14', '09:00', 'Central'], ['2026-12-15', '17:00', 'Pacific']);

        $this->assertSame('2026-12-14 15:00', $this->utc($window->start));
        $this->assertSame('2026-12-16 01:00', $this->utc($window->end));
    }

    public function test_every_label_the_forms_have_used_names_its_zone(): void
    {
        $this->assertSame('America/New_York', LoadWindow::zoneFor('Eastern'));
        $this->assertSame('America/Chicago', LoadWindow::zoneFor('(UTC-06:00) Central'));
        $this->assertSame('America/Phoenix', LoadWindow::zoneFor(' arizona '));
        $this->assertSame('America/Los_Angeles', LoadWindow::zoneFor('Pacific'));
        $this->assertSame('America/Denver', LoadWindow::zoneFor('Mountain'));
        $this->assertSame('Asia/Kolkata', LoadWindow::zoneFor('(UTC+05:30) India'));
        $this->assertSame('Asia/Kolkata', LoadWindow::zoneFor('IST'));
        $this->assertSame('America/Chicago', LoadWindow::zoneFor('america/chicago'));

        foreach ([null, '', '   ', 'Somewhere', 42, []] as $label) {
            $this->assertNull(LoadWindow::zoneFor($label), var_export($label, true).' names no zone');
        }
    }

    public function test_a_date_with_no_time_holds_the_whole_day(): void
    {
        // The trip sheet's pickup time and timezone are optional; the date is
        // read in the zone of the load's other time.
        $window = $this->tripSheet(['2026-10-20', '', ''], ['2026-10-21', '17:00', 'Central']);

        $this->assertSame('2026-10-20 05:00', $this->utc($window->start));
        $this->assertSame('from Oct 20, 2026 to Oct 21, 2026 5:00 PM CDT', $window->describe());

        $oneDay = LoadWindow::fromStops([['start_date' => '2026-10-20']]);

        $this->assertSame('2026-10-20 00:00', $this->utc($oneDay->start));
        $this->assertSame('2026-10-20 23:59', $this->utc($oneDay->end));
        $this->assertSame('on Oct 20, 2026', $oneDay->describe());
    }

    public function test_times_are_read_however_they_were_written(): void
    {
        foreach (['21:30', '21:30:00', '9:30 PM', '09:30pm', ' 21:30 '] as $time) {
            $window = LoadWindow::fromStops([['start_date' => '2026-10-20', 'start_time' => $time, 'start_timezone' => 'India']]);

            $this->assertSame('2026-10-20 16:00', $this->utc($window->start), $time);
        }

        // One it can't read leaves the whole day, rather than a wrong time.
        foreach (['25:00', '9 PM', 'noon', '13:00 PM'] as $time) {
            $window = LoadWindow::fromStops([['start_date' => '2026-10-20', 'start_time' => $time, 'start_timezone' => 'Central']]);

            $this->assertSame('on Oct 20, 2026', $window->describe(), $time);
        }
    }

    public function test_the_window_spans_every_stop_whatever_their_order(): void
    {
        $window = LoadWindow::fromStops([
            ['start_date' => '2026-10-21', 'start_time' => '08:00', 'start_timezone' => 'Central'],
            ['end_date' => '2026-10-25', 'end_time' => '10:00', 'end_timezone' => 'Central'],
            ['end_date' => '2026-10-23', 'end_time' => '10:00', 'end_timezone' => 'Central'],
            ['start_date' => '2026-10-20', 'start_time' => '23:00', 'start_timezone' => 'Central'],
        ]);

        $this->assertSame('2026-10-21 04:00', $this->utc($window->start));
        $this->assertSame('2026-10-25 15:00', $this->utc($window->end));
    }

    public function test_an_eld_loads_pickup_and_delivery_fields(): void
    {
        $window = LoadWindow::fromPickupAndDelivery([
            'pickup_date' => '2026-10-20', 'pickup_time' => '09:00', 'pickup_timezone' => 'Central',
            'delivery_date' => '2026-10-22', 'delivery_time' => '17:00', 'delivery_timezone' => 'Eastern',
        ]);

        $this->assertSame('2026-10-20 14:00', $this->utc($window->start));
        $this->assertSame('2026-10-22 21:00', $this->utc($window->end));

        // As a `date` cast hands them back.
        $cast = LoadWindow::fromPickupAndDelivery([
            'pickup_date' => CarbonImmutable::parse('2026-10-20', 'UTC'), 'pickup_time' => '09:00', 'pickup_timezone' => 'Central',
            'delivery_date' => CarbonImmutable::parse('2026-10-22', 'UTC'), 'delivery_time' => '17:00', 'delivery_timezone' => 'Eastern',
        ]);

        $this->assertSame('2026-10-20 14:00', $this->utc($cast->start));
        $this->assertSame('2026-10-22 21:00', $this->utc($cast->end));
    }

    public function test_no_dates_is_no_window(): void
    {
        $this->assertNull(LoadWindow::fromStops([]));
        $this->assertNull($this->tripSheet(['', '09:00', 'Central'], ['', '17:00', 'Eastern']));
        $this->assertNull(LoadWindow::fromPickupAndDelivery(['tracking_method' => 'driver_phone']));
        $this->assertNull(LoadWindow::fromStops(['not a stop', 7, null]));

        // Not dates: impossible, or not written Y-m-d.
        foreach (['2026-02-30', '10/20/2026', '20261020', 'tomorrow', 20261020] as $date) {
            $this->assertNull(LoadWindow::fromStops([['start_date' => $date]]), var_export($date, true));
        }

        // As a DATE column can come back.
        $this->assertNotNull(LoadWindow::fromStops([['start_date' => '2026-10-20 00:00:00']]));
    }

    public function test_overlapping(): void
    {
        $first = $this->tripSheet(['2026-10-20', '09:00', 'Central'], ['2026-10-22', '17:00', 'Eastern']);

        $during = $this->tripSheet(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']);
        $inside = $this->tripSheet(['2026-10-21', '08:00', 'Central'], ['2026-10-21', '12:00', 'Central']);
        $around = $this->tripSheet(['2026-10-19', '08:00', 'Central'], ['2026-10-25', '12:00', 'Central']);

        foreach ([$during, $inside, $around] as $other) {
            $this->assertTrue($first->overlaps($other));
            $this->assertTrue($other->overlaps($first));
        }

        // 4 PM Central is 5 PM Eastern - the moment the first one ends.
        $backToBack = $this->tripSheet(['2026-10-22', '16:00', 'Central'], ['2026-10-23', '12:00', 'Central']);
        $aMinuteEarly = $this->tripSheet(['2026-10-22', '15:59', 'Central'], ['2026-10-23', '12:00', 'Central']);
        $later = $this->tripSheet(['2026-10-24', '08:00', 'Central'], ['2026-10-25', '12:00', 'Central']);
        $before = $this->tripSheet(['2026-10-17', '08:00', 'Central'], ['2026-10-19', '12:00', 'Central']);

        $this->assertFalse($first->overlaps($backToBack));
        $this->assertFalse($backToBack->overlaps($first));
        $this->assertTrue($first->overlaps($aMinuteEarly));
        $this->assertFalse($first->overlaps($later));
        $this->assertFalse($first->overlaps($before));
    }
}
