<?php

namespace App\Services;

use App\Models\PaymentMethod;
use RuntimeException;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Thin wrapper around the official Stripe SDK. Cards, Apple Pay and Google Pay all run through one
 * PaymentIntent with automatic payment methods (Stripe shows the wallets the device supports).
 * The secret key and webhook secret come from the encrypted payment_methods.credentials column.
 */
class StripeGateway
{
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public function method(): PaymentMethod
    {
        $m = PaymentMethod::query()->where('provider', 'stripe')->first();
        if (! $m || ! $m->credential('secret_key')) {
            throw new RuntimeException('Card payments are not configured.');
        }

        return $m;
    }

    protected function client(): StripeClient
    {
        return new StripeClient(['api_key' => $this->method()->credential('secret_key'), 'max_network_retries' => 2]);
    }

    public static function toMinor(float $amount, string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? (int) round($amount) : (int) round($amount * 100);
    }

    public static function fromMinor(int $amount, string $currency): float
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? (float) $amount : $amount / 100;
    }

    public function createIntent(float $amount, string $currency, array $metadata, string $email, string $description, string $idempotencyKey): PaymentIntent
    {
        return $this->client()->paymentIntents->create([
            'amount' => self::toMinor($amount, $currency),
            'currency' => strtolower($currency),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => $metadata,
            'receipt_email' => $email,
            'description' => $description,
        ], ['idempotency_key' => $idempotencyKey]);
    }

    public function retrieveIntent(string $id): PaymentIntent
    {
        return $this->client()->paymentIntents->retrieve($id, ['expand' => ['latest_charge']]);
    }

    public function cancelIntent(string $id): void
    {
        $this->client()->paymentIntents->cancel($id);
    }

    public function refund(string $paymentIntentId, ?int $amountMinor = null): Refund
    {
        $p = ['payment_intent' => $paymentIntentId];
        if ($amountMinor !== null) {
            $p['amount'] = $amountMinor;
        }

        return $this->client()->refunds->create($p);
    }

    /** Verifies the Stripe-Signature header; throws on a bad or missing signature. */
    public function constructEvent(string $payload, string $signature): Event
    {
        $secret = $this->method()->credential('webhook_secret');
        if (! $secret) {
            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        return Webhook::constructEvent($payload, $signature, $secret, 300);
    }
}
