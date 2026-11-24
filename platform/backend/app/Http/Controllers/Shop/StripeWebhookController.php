<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\OrderService;
use App\Services\StripeGateway;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/webhooks/stripe. The signature is verified with the webhook signing secret before anything is read.
 * Payment status changes only ever come from here or from a server-side PaymentIntent lookup, never from the browser.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeGateway $stripe, OrderService $orders): Response
    {
        try {
            $event = $stripe->constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (Throwable $e) {
            Log::notice('Rejected Stripe webhook: '.$e->getMessage());

            return response('Invalid signature', 400);
        }

        try {
            WebhookEvent::create(['provider' => 'stripe', 'event_id' => $event->id, 'type' => $event->type]);
        } catch (QueryException) {
            return response('Already processed', 200); // duplicate delivery
        }

        $obj = $event->data->object;
        $intentId = match (true) {
            str_starts_with($event->type, 'payment_intent.') => $obj->id ?? null,
            str_starts_with($event->type, 'charge.') => $obj->payment_intent ?? null,
            default => null,
        };
        if (! $intentId) {
            return response('Ignored', 200);
        }
        $payment = Payment::query()->where('provider', 'stripe')->where('provider_reference', $intentId)->first();
        $order = $payment ? Order::query()->find($payment->order_id) : null;
        if (! $order) {
            return response('Unknown payment', 200);
        }

        switch ($event->type) {
            case 'payment_intent.succeeded':
                // Re-check amount and currency against the order before marking it paid.
                $pi = $stripe->retrieveIntent($intentId);
                if ($pi->status === 'succeeded' && strtoupper($pi->currency) === strtoupper($order->currency)
                    && (int) $pi->amount_received >= StripeGateway::toMinor((float) $order->total, $order->currency)) {
                    $orders->markPaid($order, $intentId, $orders->intentDetails($pi));
                } else {
                    $orders->event($order, 'note', null, null, 'Stripe reported a payment that does not match the order total. Check the Stripe dashboard.');
                }
                break;
            case 'payment_intent.payment_failed':
                $orders->markFailed($order, $intentId, $obj->last_payment_error->message ?? null);
                break;
            case 'payment_intent.canceled':
                $orders->markFailed($order, $intentId, 'Payment was cancelled.');
                break;
            case 'charge.refunded':
                $orders->markRefunded($order, StripeGateway::fromMinor((int) ($obj->amount_refunded ?? 0), $order->currency));
                break;
        }

        return response('OK', 200);
    }
}
