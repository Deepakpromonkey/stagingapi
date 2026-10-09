<?php

namespace Tests\Feature;

use App\Services\DtScore\DtScore;
use Tests\TestCase;

/**
 * Search and shortlist cards read cached scores, so the cache has to carry
 * enough to tell a clean score from a flagged one.
 */
class DtScoreCacheEntryTest extends TestCase
{
    public function test_a_cached_entry_carries_the_band_and_the_review_flag(): void
    {
        DtScore::store('4361498', 83, 'Approved', 'preferred', true);
        DtScore::store('3415372', 91, 'Approved', 'preferred');

        $entries = DtScore::cachedEntries(['4361498', '3415372', '999']);

        $this->assertSame(
            ['score' => 83, 'status' => 'Approved', 'band' => 'preferred', 'needs_manual_review' => true],
            $entries['4361498']
        );
        $this->assertFalse($entries['3415372']['needs_manual_review']);
        $this->assertArrayNotHasKey('999', $entries, 'nothing is calculated for an uncached carrier');
    }

    public function test_many_with_status_returns_the_same_entry(): void
    {
        DtScore::store('4361498', 83, 'Approved', 'preferred', true);

        $this->assertTrue(DtScore::manyWithStatus(['4361498'])['4361498']['needs_manual_review']);
        $this->assertSame(83, DtScore::cached('4361498'));
    }
}
