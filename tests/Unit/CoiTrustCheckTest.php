<?php

namespace Tests\Unit;

use App\Services\Coi\CoiTrustCheck;
use PHPUnit\Framework\TestCase;

/**
 * The quiet failures: a certificate that parses perfectly and should not be
 * believed.
 */
class CoiTrustCheckTest extends TestCase
{
    private CoiTrustCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->check = new CoiTrustCheck();
    }

    public function test_an_agency_disowning_a_certificate_is_a_hard_stop(): void
    {
        // Sequence 14.
        $result = $this->check->check('dana@northlineagency.example', [
            'signals' => ['certificate_disowned'],
        ]);

        $this->assertSame('do_not_rely', $result['verdict']);
        $this->assertSame('disowned', $result['flags'][0]['code']);
    }

    public function test_an_altered_limit_is_a_hard_stop_even_with_a_valid_date(): void
    {
        // Sequence 15: same certificate number, one number changed. Every
        // other check on this reply passes.
        $result = $this->check->check('dana@northlineagency.example', [
            'expiry_date' => '2027-04-03',
            'signals' => ['limit_discrepancy'],
        ]);

        $this->assertSame('do_not_rely', $result['verdict']);
    }

    public function test_a_free_mail_producer_warns_without_stopping(): void
    {
        // Small agencies really do use gmail; it is a tell, not a verdict.
        $result = $this->check->check('someagent@gmail.com', ['signals' => []]);

        $this->assertSame('check_before_relying', $result['verdict']);
        $this->assertTrue($result['free_mail']);
        $this->assertSame('gmail.com', $result['reply_domain']);
    }

    public function test_a_stop_outranks_a_warning(): void
    {
        $result = $this->check->check('someagent@gmail.com', [
            'signals' => ['certificate_disowned'],
        ]);

        $this->assertSame('do_not_rely', $result['verdict']);
        $this->assertCount(2, $result['flags']);
    }

    public function test_an_ordinary_agency_reply_raises_nothing(): void
    {
        $result = $this->check->check('dana@northlineagency.example', [
            'signals' => ['renewal_pending'],
        ]);

        $this->assertSame('no_concerns', $result['verdict']);
        $this->assertSame([], $result['flags']);
    }

    public function test_a_missing_sender_is_not_treated_as_free_mail(): void
    {
        $result = $this->check->check(null, ['signals' => []]);

        $this->assertSame('no_concerns', $result['verdict']);
        $this->assertNull($result['reply_domain']);
    }
}
