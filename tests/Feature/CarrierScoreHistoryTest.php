<?php

namespace Tests\Feature;

use App\Services\DtScore\DtScore;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Drayage\DrayageTestCase;

class CarrierScoreHistoryTest extends DrayageTestCase
{
    public function test_history_lists_each_score_change_newest_first(): void
    {
        $dt = app(DtScore::class);
        $result = fn (int $score, array $fired, bool $review = false) => [
            'overall_score' => $score,
            'status' => 'Approved',
            'band' => ['key' => 'preferred'],
            'needs_manual_review' => $review,
            'v3' => [
                'rules_fired' => array_map(fn ($id) => ['id' => $id], $fired),
                'flags' => $review ? ['insurance_feed_inconsistency'] : [],
            ],
        ];

        $dt->recordEvaluation('4361498', $result(88, []));
        $dt->recordEvaluation('4361498', $result(83, ['INS-07'], true));

        Sanctum::actingAs($this->brokerUser('agent'));

        $history = $this->getJson('/api/v1/carrier/4361498/score-history')->assertOk()->json('history');

        $this->assertSame([83, 88], array_column($history, 'score'));
        $this->assertTrue($history[0]['needs_manual_review']);
        $this->assertSame(['INS-07'], $history[0]['rules_fired']);
        $this->assertSame(['insurance_feed_inconsistency'], $history[0]['flags']);
        $this->assertArrayNotHasKey('payload', $history[0], 'the full receipt stays server-side');
    }

    public function test_history_needs_a_signed_in_broker(): void
    {
        $this->getJson('/api/v1/carrier/4361498/score-history')->assertUnauthorized();
    }
}
