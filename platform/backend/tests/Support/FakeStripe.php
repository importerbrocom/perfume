<?php

namespace Tests\Support;

use App\Services\StripeGateway;
use Stripe\PaymentIntent;
use Stripe\Refund;

/** Stripe stand-in for tests: no network. Webhook signature checking stays real (inherited). */
class FakeStripe extends StripeGateway
{
    /** @var array<string,array> */
    public array $intents = [];

    public array $refunds = [];

    public function createIntent(float $amount, string $currency, array $metadata, string $email, string $description, string $idempotencyKey): PaymentIntent
    {
        $id = 'pi_test_'.(count($this->intents) + 1);
        $this->intents[$id] = ['id' => $id, 'object' => 'payment_intent', 'amount' => self::toMinor($amount, $currency), 'amount_received' => 0,
            'currency' => strtolower($currency), 'status' => 'requires_payment_method', 'client_secret' => $id.'_secret_x', 'metadata' => $metadata, 'latest_charge' => null];

        return PaymentIntent::constructFrom($this->intents[$id]);
    }

    public function succeed(string $id, string $wallet = 'apple_pay'): void
    {
        $this->intents[$id]['status'] = 'succeeded';
        $this->intents[$id]['amount_received'] = $this->intents[$id]['amount'];
        $this->intents[$id]['latest_charge'] = ['id' => 'ch_1', 'object' => 'charge', 'payment_method_details' => [
            'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'wallet' => ['type' => $wallet]],
        ]];
    }

    public function retrieveIntent(string $id): PaymentIntent
    {
        return PaymentIntent::constructFrom($this->intents[$id]);
    }

    public function cancelIntent(string $id): void
    {
        $this->intents[$id]['status'] = 'canceled';
    }

    public function refund(string $paymentIntentId, ?int $amountMinor = null): Refund
    {
        $this->refunds[] = $paymentIntentId;

        return Refund::constructFrom(['id' => 're_1', 'object' => 'refund']);
    }
}
