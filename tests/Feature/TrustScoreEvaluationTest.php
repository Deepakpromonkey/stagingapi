<?php

namespace Tests\Feature;

use App\Services\DtScore\DtScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PortableMigrations;
use Tests\TestCase;

class TrustScoreEvaluationTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private function scored(int $score, array $fired = ['ID-01']): array
    {
        return [
            'overall_score' => $score,
            'status' => 'Approved',
            'band' => ['key' => 'preferred'],
            'needs_manual_review' => false,
            'v3' => [
                'rules_fired' => array_map(fn ($id) => ['id' => $id], $fired),
                'score_cap_applied' => null,
            ],
        ];
    }

    public function test_scoring_twice_with_unchanged_data_records_once(): void
    {
        $dt = app(DtScore::class);

        $dt->recordEvaluation('4361498', $this->scored(83));
        $dt->recordEvaluation('4361498', $this->scored(83));

        $this->assertSame(1, DB::table('trust_score_evaluations')->where('dot_number', '4361498')->count());
    }

    public function test_a_changed_result_records_a_new_row(): void
    {
        $dt = app(DtScore::class);

        $dt->recordEvaluation('4361498', $this->scored(83));
        $dt->recordEvaluation('4361498', $this->scored(71, ['ID-01', 'INS-10']));

        $rows = DB::table('trust_score_evaluations')->where('dot_number', '4361498')->orderBy('id')->get();

        $this->assertSame([83, 71], $rows->pluck('score')->map(fn ($s) => (int) $s)->all());
        $this->assertSame((int) $rows->last()->id, DtScore::latestEvaluationId('4361498'));
        $this->assertSame(config('dtscore.model_version'), $rows->last()->model_version);
    }

    public function test_an_unscored_carrier_has_no_evaluation(): void
    {
        $this->assertNull(DtScore::latestEvaluationId('999'));
        $this->assertNull(DtScore::latestEvaluationId(null));
    }
}
