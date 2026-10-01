<?php

namespace Tests\Unit;

use App\Services\Coi\InsuranceExpiryExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The reading of an agency's reply, once the model has answered.
 *
 * The call itself is not exercised here — what matters is that a well-formed
 * answer is turned into the same shape every time, and that a malformed one
 * refuses rather than quietly reading as "the agency said nothing".
 */
class CoiReplyReadingTest extends TestCase
{
    private function read(string $raw): array
    {
        $method = new ReflectionMethod(InsuranceExpiryExtractor::class, 'parseDetails');

        return $method->invoke(
            (new \ReflectionClass(InsuranceExpiryExtractor::class))->newInstanceWithoutConstructor(),
            $raw
        );
    }

    private function date(?string $value, string $raw = '{}'): mixed
    {
        $method = new ReflectionMethod(InsuranceExpiryExtractor::class, 'parseDate');

        return $method->invoke(
            (new \ReflectionClass(InsuranceExpiryExtractor::class))->newInstanceWithoutConstructor(),
            $value,
            $raw
        );
    }

    public function test_it_reads_a_reefer_reply_with_exclusions_and_sub_limits(): void
    {
        // Sequence 03: the exclusions are the point, not the date.
        $details = $this->read(json_encode([
            'expiry_date' => '2027-07-01',
            'policy_number' => 'NR-CA-5521',
            'insurer' => 'Great Northern Casualty',
            'coverages' => [
                ['type' => 'cargo', 'limit' => 250000, 'expiry_date' => '2027-07-01'],
            ],
            'exclusions' => ['Unattended vehicle theft', '$2,500 deductible', ''],
            'sub_limits' => [['commodity' => 'seafood', 'limit' => 100000]],
            'signals' => ['', 'filing_lag'],
            'summary' => 'Cargo excludes unattended theft.',
        ]));

        $this->assertSame('2027-07-01', $details['expiry_date']);
        $this->assertSame('Great Northern Casualty', $details['insurer']);
        $this->assertSame(100000, $details['sub_limits'][0]['limit']);

        // Blank entries are dropped rather than rendered as empty chips.
        $this->assertSame(['Unattended vehicle theft', '$2,500 deductible'], $details['exclusions']);
        $this->assertSame(['filing_lag'], $details['signals']);
    }

    public function test_it_tolerates_a_fenced_block(): void
    {
        $details = $this->read("```json\n{\"expiry_date\": null, \"signals\": [\"out_of_office\"]}\n```");

        $this->assertNull($details['expiry_date']);
        $this->assertSame(['out_of_office'], $details['signals']);
    }

    public function test_missing_keys_come_back_as_empty_rather_than_absent(): void
    {
        // Callers index these directly; a missing key must not be a notice.
        $details = $this->read('{"expiry_date": null}');

        $this->assertSame([], $details['exclusions']);
        $this->assertSame([], $details['coverages']);
        $this->assertSame([], $details['signals']);
        $this->assertNull($details['holder_name']);
    }

    public function test_an_unreadable_answer_refuses_instead_of_reading_as_empty(): void
    {
        $this->expectException(RuntimeException::class);

        $this->read('I could not find an expiry date in that email.');
    }

    public function test_a_date_is_only_accepted_in_the_format_it_was_asked_for(): void
    {
        // 04/05/2027 is the 5th of April on a US certificate and the 4th of May
        // to Carbon. Refusing it is the only safe answer.
        $this->expectException(RuntimeException::class);

        $this->date('04/05/2027');
    }

    public function test_not_found_and_null_both_mean_no_date(): void
    {
        $this->assertNull($this->date(null));
        $this->assertNull($this->date('NOT FOUND'));
        $this->assertSame('2027-04-30', $this->date('2027-04-30')->toDateString());
    }
}
