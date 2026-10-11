<?php

namespace App\Pay\Stripe;

/**
 * Everything DT Pay asks of Stripe. StripeGateway talks to Stripe; tests
 * bind FakeGateway. Results come back as plain arrays so nothing above this
 * layer depends on Stripe's object model.
 */
interface Gateway
{
    /** True when the configured key moves real money. */
    public function livemode(): bool;

    public function publishableKey(): ?string;

    /** Create a Stripe customer; returns its id. */
    public function createCustomer(string $name, ?string $email, array $metadata): string;

    /** @return list<array{id: string, type: string, label: string, brand: ?string, last4: ?string, exp: ?string, bank: ?string}> */
    public function listPaymentMethods(string $customerId): array;

    /** @return array{id: string, type: string, label: string, customer: ?string} */
    public function retrievePaymentMethod(string $paymentMethodId): array;

    public function detachPaymentMethod(string $paymentMethodId): void;

    /** A SetupIntent for saving a card or bank account; returns its client secret. */
    public function createSetupIntent(string $customerId, array $methodTypes): string;

    /**
     * @return array{id: string, status: string, client_secret: ?string, charge_id: ?string, error: ?string}
     */
    public function createPaymentIntent(array $params, string $idempotencyKey): array;

    /** @return array{id: string, status: string, client_secret: ?string, charge_id: ?string, error: ?string} */
    public function retrievePaymentIntent(string $paymentIntentId): array;

    /** Returns the transfer id. */
    public function createTransfer(int $amountCents, string $currency, string $destination, ?string $sourceCharge, array $metadata, string $idempotencyKey): string;

    /** @return array{id: string, status: string} */
    public function createRefund(string $paymentIntentId, array $metadata, string $idempotencyKey): array;

    /** A new Express account for a carrier; returns its id. */
    public function createExpressAccount(string $carrierDot, ?string $email, ?string $businessName): string;

    /** A hosted onboarding link for an Express account. */
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string;

    /** @return array{details_submitted: bool, payouts_enabled: bool, transfers_active: bool} */
    public function retrieveAccount(string $accountId): array;

    /** Verify and decode a webhook. Throws on a bad signature. */
    public function webhookEvent(string $payload, string $signature): array;
}
