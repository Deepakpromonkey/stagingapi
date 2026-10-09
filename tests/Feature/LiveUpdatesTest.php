<?php

namespace Tests\Feature;

use App\Events\NotificationsChanged;
use App\Events\ShipmentUpdated;
use App\Models\CarrierConnectRequest;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

class LiveUpdatesTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private function company(): Company
    {
        return Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ]);
    }

    private function shipment(Company $company, array $attributes = []): Shipment
    {
        $user = User::firstOrCreate(['company_id' => $company->id], [
            'uuid' => Str::uuid(),
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => 'sam-'.Str::random(8).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => false,
        ]);

        return Shipment::create(array_merge([
            'uuid' => (string) Str::orderedUuid(),
            'company_id' => $company->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'shipment_no' => 'SHP-'.Str::random(10),
            'tracking_method' => 'eld',
            'status' => 'active',
        ], $attributes));
    }

    public function test_a_new_shipment_is_announced_to_the_company_and_its_own_channel(): void
    {
        Event::fake([ShipmentUpdated::class]);

        $shipment = $this->shipment($this->company());

        Event::assertDispatched(ShipmentUpdated::class, function (ShipmentUpdated $event) use ($shipment) {
            $channels = collect($event->broadcastOn())->map->name->all();

            return $event->uuid === $shipment->uuid
                && in_array('private-shipment.'.$shipment->uuid, $channels, true)
                && in_array('private-company.'.$shipment->company->uuid, $channels, true);
        });
    }

    public function test_a_status_change_reaches_the_company_channel(): void
    {
        $shipment = $this->shipment($this->company());

        Event::fake([ShipmentUpdated::class]);

        $shipment->update(['status' => 'completed']);

        Event::assertDispatched(ShipmentUpdated::class, fn (ShipmentUpdated $event) => $event->companyWide
            && $event->status === 'completed');
    }

    public function test_a_new_position_alone_reaches_only_the_shipments_own_channel(): void
    {
        $shipment = $this->shipment($this->company());

        Event::fake([ShipmentUpdated::class]);

        $shipment->forceFill(['last_ping_at' => now()])->save();

        Event::assertDispatched(ShipmentUpdated::class, function (ShipmentUpdated $event) {
            return ! $event->companyWide && count($event->broadcastOn()) === 1;
        });
    }

    public function test_an_edit_nothing_on_screen_follows_announces_nothing(): void
    {
        $shipment = $this->shipment($this->company());

        Event::fake([ShipmentUpdated::class]);

        $shipment->update(['notes' => 'Gate code 4411']);

        Event::assertNotDispatched(ShipmentUpdated::class);
    }

    public function test_the_payload_is_a_signal_not_the_shipment(): void
    {
        $event = new ShipmentUpdated('uuid-1', 'active', 'company-uuid', true);

        $this->assertSame(['uuid' => 'uuid-1', 'status' => 'active'], $event->broadcastWith());
        $this->assertSame('shipment.updated', $event->broadcastAs());
    }

    public function test_an_onboarding_change_refreshes_the_companys_bell(): void
    {
        $company = $this->company();

        Event::fake([NotificationsChanged::class]);

        CarrierConnectRequest::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'carrier_dot_number' => '1234567',
            'carrier_row_id' => '99001',
            'carrier_legal_name' => "Frank's Trucking",
            'carrier_email' => 'frank@franks.test',
            'token' => Str::random(64),
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
            'sent_on' => now(),
        ]);

        Event::assertDispatched(NotificationsChanged::class, fn (NotificationsChanged $event) => $event->companyUuid === (string) $company->uuid
            && $event->broadcastOn()[0]->name === 'private-company.'.$company->uuid);
    }
}
