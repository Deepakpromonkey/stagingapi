<?php

namespace Tests\Unit;

use App\Services\Coi\CoiProducerEmailReader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The producer address read off a certificate, once the model has answered.
 *
 * The call itself is not exercised here — what matters is that only a real
 * address survives, so nothing is ever mailed to a sentence.
 */
class CoiProducerEmailReadingTest extends TestCase
{
    private function read(string $raw): ?string
    {
        $method = new ReflectionMethod(CoiProducerEmailReader::class, 'parseEmail');

        return $method->invoke(
            (new \ReflectionClass(CoiProducerEmailReader::class))->newInstanceWithoutConstructor(),
            $raw
        );
    }

    public function test_a_bare_address_is_kept_lowercased(): void
    {
        $this->assertSame('certificates@cottinghambutler.com', $this->read('Certificates@CottinghamButler.com'));
    }

    public function test_an_address_inside_a_sentence_is_still_found(): void
    {
        $this->assertSame('certs@rtsinsurance.com', $this->read('The producer email is certs@rtsinsurance.com.'));
    }

    public function test_none_means_no_address(): void
    {
        $this->assertNull($this->read('NONE'));
        $this->assertNull($this->read('none'));
        $this->assertNull($this->read(''));
    }

    public function test_text_without_an_address_is_not_an_address(): void
    {
        $this->assertNull($this->read('The producer block has no email.'));
    }
}
