<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CartPricing;
use App\Services\OrderService;
use App\Services\StripeGateway;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckoutController extends Controller
{
    private const ITEM_RULES = [
        'items' => 'required|array|min:1|max:50',
        'items.*.variant_id' => 'required|integer|min:1',
        'items.*.quantity' => 'required|integer|min:1|max:99',
    ];

    /** POST /api/cart/quote: live cart totals, availability and payment options. */
    public function quote(Request $request, CartPricing $pricing): JsonResponse
    {
        $v = $request->validate(self::ITEM_RULES + [
            'coupon_code' => 'nullable|string|max:50',
            'country' => 'nullable|string|size:2',
            'payment_method' => 'nullable|string|max:30',
        ]);
        $q = $pricing->quote($v['items'], $v['coupon_code'] ?? null, $v['country'] ?? null, $v['payment_method'] ?? null);
        unset($q['quantities'], $q['coupon']['id']);

        return response()->json($q);
    }

    /** POST /api/checkout: creates the order (stock reserved, totals recomputed on the server). */
    public function place(Request $request, OrderService $orders): JsonResponse
    {
        $v = $request->validate(self::ITEM_RULES + [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email:rfc|max:150',
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()\-]{7,30}$/'],
            'country' => 'required|string|size:2|alpha',
            'state' => 'nullable|string|max:100',
            'city' => 'required|string|max:100',
            'address' => 'required|string|min:5|max:500',
            'postal_code' => 'nullable|string|max:20',
            'note' => 'nullable|string|max:1000',
            'coupon_code' => 'nullable|string|max:50',
            'payment_method' => 'required|string|max:30',
            'accept_terms' => 'accepted',
        ]);
        foreach (['name', 'city', 'state', 'address', 'note'] as $k) {
            if (isset($v[$k])) {
                $v[$k] = trim(strip_tags($v[$k]));
            }
        }
        $order = $orders->place($v, $request->ip());

        return response()->json([
            'number' => $order->number,
            'token' => $order->access_token,
            'payment_method' => $order->payment_method,
            'requires_payment' => $order->payment_method !== 'cod',
            'total' => (float) $order->total,
            'currency' => $order->currency,
        ], 201);
    }

    private function findOrder(string $number, ?string $token): Order
    {
        $order = Order::query()->where('number', $number)->first();
        abort_unless($order && $token && hash_equals($order->access_token, $token), 404);

        return $order;
    }

    /** POST /api/orders/{number}/pay: Stripe PaymentIntent for this order (cards, Apple Pay, Google Pay). */
    public function pay(Request $request, string $number, StripeGateway $stripe): JsonResponse
    {
        $order = $this->findOrder($number, (string) $request->input('token'));
        abort_if($order->payment_method === 'cod', 422, 'This order is Cash on Delivery.');
        if ($order->payment_status === 'paid') {
            return response()->json(['status' => 'paid']);
        }
        abort_if($order->status === 'cancelled' || ! $order->holdsStock(), 422, 'This order has expired. Please place a new order.');

        $method = PaymentMethod::query()->where('code', $order->payment_method)->first();
        abort_unless($method && $method->provider === 'stripe' && $method->isConfigured(), 422, 'Card payments are not available right now.');

        try {
            $payment = DB::transaction(function () use ($order) {
                return Payment::query()->where('order_id', $order->id)->where('provider', 'stripe')->lockForUpdate()->latest('id')->first();
            });
            $intent = null;
            if ($payment && $payment->provider_reference) {
                $intent = $stripe->retrieveIntent($payment->provider_reference);
                if (in_array($intent->status, ['canceled'], true)) {
                    $intent = null;
                }
            }
            if (! $intent) {
                $intent = $stripe->createIntent((float) $order->total, $order->currency,
                    ['order_number' => $order->number, 'order_id' => (string) $order->id],
                    $order->customer_email, 'Order '.$order->number, 'order-'.$order->id.'-'.($payment?->id ?? 0));
                if ($payment && ! $payment->provider_reference) {
                    $payment->update(['provider_reference' => $intent->id]);
                } else {
                    Payment::create(['order_id' => $order->id, 'method' => $order->payment_method, 'provider' => 'stripe',
                        'provider_reference' => $intent->id, 'amount' => $order->total, 'currency' => $order->currency, 'status' => 'pending']);
                }
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Card payment could not be started. Please try again or choose Cash on Delivery.'], 502);
        }

        return response()->json([
            'client_secret' => $intent->client_secret,
            'publishable_key' => $method->option('publishable_key'),
            'amount' => (float) $order->total,
            'currency' => $order->currency,
        ]);
    }

    /**
     * GET /api/orders/{number}?token=: the buyer's own order (thank-you page).
     * For card orders still pending, asks Stripe directly so the page is right even before the webhook arrives.
     */
    public function show(Request $request, string $number, OrderService $orders, StripeGateway $stripe): JsonResponse
    {
        $order = $this->findOrder($number, (string) $request->query('token'));
        if ($order->payment_method !== 'cod' && in_array($order->payment_status, ['pending', 'failed'], true)) {
            $ref = $order->payments()->whereNotNull('provider_reference')->latest('id')->value('provider_reference');
            if ($ref) {
                try {
                    $pi = $stripe->retrieveIntent($ref);
                    if ($pi->status === 'succeeded' && (int) $pi->amount_received >= StripeGateway::toMinor((float) $order->total, $order->currency)
                        && strtoupper($pi->currency) === strtoupper($order->currency)) {
                        $orders->markPaid($order, $ref, $orders->intentDetails($pi));
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return response()->json(Present::customerOrder($order->refresh()->load(['items', 'events'])));
    }

    /** POST /api/track: order number + billing email. Rate limited. */
    public function track(Request $request): JsonResponse
    {
        $v = $request->validate(['number' => 'required|string|max:20', 'email' => 'required|email|max:150']);
        $order = Order::query()->where('number', strtoupper(trim($v['number'])))->first();
        if (! $order || ! hash_equals(mb_strtolower($order->customer_email), mb_strtolower(trim($v['email'])))) {
            return response()->json(['message' => 'We could not find an order with that number and email.'], 404);
        }

        return response()->json(Present::customerOrder($order->load(['items', 'events'])));
    }
}
