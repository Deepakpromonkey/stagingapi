<?php

namespace Tests\Feature\DtPay;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Pay\Models\PayoutAccount;
use App\Pay\Stripe\Gateway;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

abstract class DtPayTestCase extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    protected FakeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->stripe = new FakeGateway;
        $this->app->instance(Gateway::class, $this->stripe);

        config(['dtpay.disk' => 'local']);
        Storage::fake('local');
    }

    protected function company(string $name = 'Northwind Logistics'): Company
    {
        return Company::create(['uuid' => Str::uuid(), 'company_name' => $name, 'status' => true]);
    }

    protected function user(string $role = 'owner_admin', ?Company $company = null): User
    {
        $company ??= $this->company();

        $user = User::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => $role.'-'.Str::random(6).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => $role === 'owner_admin',
        ]);

        $user->assignRole(Role::where('slug', $role)->firstOrFail());

        return $user->fresh();
    }

    protected function actingAsUser(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /** A manual payment of $amount, created through the API. */
    protected function createPayment(float $amount = 1000.00, string $dot = '3577340'): array
    {
        return $this->postJson('/api/v1/dtpay/payments', [
            'source' => 'manual',
            'carrier_dot' => $dot,
            'carrier_name' => 'Powell Distributing',
            'amount' => $amount,
            'load_number' => 'LD-5521',
            'origin' => 'Dallas, TX',
            'destination' => 'Memphis, TN',
        ])->assertCreated()->json('payment');
    }

    /** Pay with a fresh method of the given type; returns the payment JSON. */
    protected function pay(string $paymentId, string $type = 'card'): array
    {
        $customer = app(\App\Pay\PaymentService::class)->customerFor(auth()->user()->company, auth()->user());
        $pm = $this->stripe->addMethod($customer, $type);

        return $this->postJson("/api/v1/dtpay/payments/{$paymentId}/pay", ['payment_method_id' => $pm])->assertOk()->json('payment');
    }

    protected function carrierReady(string $dot = '3577340'): void
    {
        PayoutAccount::create(['carrier_dot' => $dot, 'livemode' => false, 'stripe_account_id' => 'acct_fake_'.$dot, 'details_submitted' => true, 'payouts_enabled' => true, 'transfers_active' => true]);
    }
}
