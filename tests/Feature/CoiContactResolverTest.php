<?php

namespace Tests\Feature;

use App\Services\Coi\CoiContactResolver;
use App\Services\Coi\CoiProducerEmailReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Who a certificate request is addressed to.
 *
 * The agency that issued the certificate, read off the certificate — never the
 * carrier's own FMCSA address, which reaches the carrier and not the agency.
 */
class CoiContactResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The extractions live on the external host; an in-memory stand-in
        // with the one table the resolver reads, carrying no producer email —
        // which is what the real OCR looks like.
        config(['database.connections.external_db' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('external_db');

        Schema::connection('external_db')->create('coi_document_extractions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coi_document_id')->nullable();
            $table->unsignedBigInteger('dot_number');
            $table->string('status')->nullable();
            $table->json('extracted_json')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('extracted_at')->nullable();
        });

        DB::connection('external_db')->table('coi_document_extractions')->insert([
            'dot_number' => 3595533,
            'status' => 'true',
            'extracted_json' => json_encode(['data' => [
                'PRODUCER' => 'Cottingham & Butler, 800 Main St., Dubuque IA 52001',
                'INSURED' => 'Golden Plains Trucking Co, dispatch@goldenplains.example',
            ]]),
            'extracted_at' => now(),
        ]);
    }

    private function readerReturning(?string $email): void
    {
        $this->app->instance(CoiProducerEmailReader::class, new class($email) extends CoiProducerEmailReader
        {
            public function __construct(private readonly ?string $email) {}

            public function read(int|string $dotNumber): ?string
            {
                return $this->email;
            }
        });
    }

    public function test_the_producer_email_on_the_certificate_is_used(): void
    {
        $this->readerReturning('certificates@cottinghambutler.com');

        $this->assertSame(
            ['email' => 'certificates@cottinghambutler.com', 'source' => 'coi_document'],
            app(CoiContactResolver::class)->resolve(3595533),
        );
    }

    public function test_no_producer_email_means_no_contact_rather_than_the_carrier(): void
    {
        $this->readerReturning(null);

        $this->assertNull(app(CoiContactResolver::class)->resolve(3595533));
    }
}
