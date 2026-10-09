<?php

namespace Tests\Unit;

use App\Support\Fmcsa;
use PHPUnit\Framework\TestCase;

class FmcsaDateTest extends TestCase
{
    public function test_two_digit_years_pivot_forward_for_near_future_dates(): void
    {
        // FMCSA writes cancellation notices a year or so ahead; those must
        // stay in this century rather than jumping back a hundred years.
        $next = new \DateTimeImmutable('+1 year');

        $this->assertSame((int) $next->format('Y'), Fmcsa::date(strtoupper($next->format('d-M-y')))->year);
    }

    public function test_two_digit_years_far_ahead_are_last_century(): void
    {
        $this->assertSame(1974, Fmcsa::date('01-JUN-74')->year);
        $this->assertSame(1955, Fmcsa::date('11-MAY-55')->year);
    }

    public function test_full_dates_and_blanks(): void
    {
        $this->assertSame(2004, Fmcsa::date('09/23/2004')->year);
        $this->assertNull(Fmcsa::date(''));
        $this->assertNull(Fmcsa::date(null));
    }
}
