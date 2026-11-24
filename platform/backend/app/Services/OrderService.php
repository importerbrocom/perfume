<?php

namespace App\Services;

use App\Mail\OrderMail;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderService
{
    /** Allowed admin status changes. */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'processing', 'cancelled'],
        'confirmed' => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'returned'],
        'delivered' => ['returned'],
        'cancelled' => [],
        'returned' => [],
    ];

    public function __construct(private StockService $stock, private CartPricing $pricing) {}

    /**
     * Creates an order: locks the variants, re-prices everything on the server, reserves stock, records the payment.
     *
     * @param  array  $data  validated checkout data
     */
    public function place(array $data, ?string $ip = null): Order
    {
        $order = DB::transaction(function () use ($data, $ip) {
            $quantities = [];
            foreach ($data['items'] as $it) {
                $quantities[(int) $it['variant_id']] = min(99, ($quantities[(int) $it['variant_id']] ?? 0) + (int) $it['quantity']);
            }
            $variants = $this->stock->lockAndCheck($quantities);
            $q = $this->pricing->quote($data['items'], $data['coupon_code'] ?? null, $data['country'], $data['payment_method'], $variants);

            if ($q['issues']) {
                throw ValidationException::withMessages(['items' => collect($q['issues'])->pluck('message')->unique()->implode(' ')]);
            }
            if ($q['subtotal'] <= 0) {
                throw ValidationException::withMessages(['items' => 'Your cart is empty.']);
            }
            if (! $q['ships_to_country']) {
                throw ValidationException::withMessages(['country' => 'We do not deliver to this country yet. Please contact us on WhatsApp.']);
            }
            if (! $q['payment_method_valid']) {
                throw ValidationException::withMessages(['payment_method' => 'This payment method is not available for your order.']);
            }
            if (! empty($data['coupon_code']) && ! $q['coupon']) {
                throw ValidationException::withMessages(['coupon_code' => $q['coupon_message'] ?? 'Invalid coupon.']);
            }
            $method = PaymentMethod::query()->where('code', $data['payment_method'])->firstOrFail();

            if ($q['coupon']) {
                // Atomic usage count; fails if the last use was taken by a concurrent order.
                $ok = Coupon::query()->whereKey($q['coupon']['id'])
                    ->where(fn ($w) => $w->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
                    ->increment('used_count');
                if (! $ok) {
                    throw ValidationException::withMessages(['coupon_code' => 'This coupon has reached its usage limit.']);
                }
            }

            $email = mb_strtolower(trim($data['email']));
            $customer = Customer::query()->firstOrNew(['email' => $email]);
            $customer->fill([
                'name' => $data['name'], 'phone' => $data['phone'], 'country' => strtoupper($data['country']), 'city' => $data['city'],
            ]);
            $customer->save();
            if ($customer->is_blocked) {
                throw ValidationException::withMessages(['email' => 'We cannot accept online orders for this account. Please contact us.']);
            }

            $isCod = $method->code === 'cod';
            $order = Order::create([
                'number' => 'TMP-'.Str::random(12),
                'access_token' => Str::random(48),
                'customer_id' => $customer->id,
                'status' => 'pending',
                'payment_status' => $isCod ? 'cod_pending' : 'pending',
                'payment_method' => $method->code,
                'currency' => $q['currency'],
                'customer_name' => $data['name'],
                'customer_email' => $email,
                'customer_phone' => $data['phone'],
                'ship_country' => strtoupper($data['country']),
                'ship_state' => $data['state'] ?? null,
                'ship_city' => $data['city'],
                'ship_address' => $data['address'],
                'ship_postal_code' => $data['postal_code'] ?? null,
                'customer_note' => $data['note'] ?? null,
                'subtotal' => $q['subtotal'],
                'discount_total' => $q['discount'],
                'coupon_code' => $q['coupon']['code'] ?? null,
                'shipping_total' => $q['shipping'],
                'payment_fee' => $q['payment_fee'],
                'total' => $q['total'],
                'ip' => $ip,
            ]);
            $order->forceFill(['number' => 'PM'.(100000 + $order->id)])->save();

            foreach ($q['lines'] as $l) {
                $v = $variants[$l['variant_id']];
                $order->items()->create([
                    'product_id' => $l['product_id'],
                    'variant_id' => $l['variant_id'],
                    'product_name' => $l['product_name'],
                    'variant_name' => $l['variant_name'] !== 'Standard' ? $l['variant_name'] : null,
                    'sku' => $v->sku ?: $v->product->sku,
                    'image_path' => $v->product->images->first()?->thumb_path ?? $v->product->images->first()?->path,
                    'unit_price' => $l['unit_price'],
                    'quantity' => $l['quantity'],
                    'line_total' => $l['line_total'],
                ]);
            }
            $this->stock->reserveForOrder($order, $variants, $quantities);

            Payment::create([
                'order_id' => $order->id, 'method' => $method->code, 'provider' => $method->provider,
                'amount' => $q['total'], 'currency' => $q['currency'], 'status' => $isCod ? 'pending' : 'pending',
            ]);
            $this->event($order, 'status', null, 'pending', 'Order placed.', true);

            $customer->forceFill([
                'orders_count' => $customer->orders()->count(),
                'last_order_at' => now(),
            ])->save();

            return $order;
        }, 3);

        $this->notifyNewOrder($order);

        return $order;
    }

    public function event(Order $order, string $type, ?string $from, ?string $to, ?string $message, bool $visible = false, ?User $user = null): OrderEvent
    {
        return $order->events()->create([
            'type' => $type, 'from_value' => $from, 'to_value' => $to, 'message' => $message,
            'is_customer_visible' => $visible, 'user_id' => $user?->id,
        ]);
    }

    /** Admin status change. Releases stock on cancel / return; marks COD paid on delivery. */
    public function changeStatus(Order $order, string $to, ?User $user, ?string $note = null, bool $notify = true): Order
    {
        $from = $order->status;
        if ($from === $to) {
            return $order;
        }
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "An order that is '$from' cannot be changed to '$to'."]);
        }
        if (in_array($to, ['shipped', 'delivered'], true) && $order->payment_method !== 'cod' && $order->payment_status !== 'paid') {
            throw ValidationException::withMessages(['status' => 'This card order is not paid yet, so it cannot be shipped.']);
        }

        DB::transaction(function () use ($order, $to, $from, $user, $note) {
            $order->status = $to;
            if ($to === 'delivered' && $order->payment_method === 'cod' && $order->payment_status === 'cod_pending') {
                $order->payment_status = 'paid';
                $order->paid_at = now();
                $order->payments()->where('status', 'pending')->update(['status' => 'paid']);
                $this->event($order, 'payment', 'cod_pending', 'paid', 'Cash collected on delivery.', false, $user);
            }
            $order->save();
            $this->event($order, 'status', $from, $to, $note, true, $user);
            if (in_array($to, ['cancelled', 'returned'], true)) {
                if ($this->stock->releaseForOrder($order, $to === 'cancelled' ? 'order_cancelled' : 'order_returned', $user?->id)) {
                    $this->event($order, 'stock', null, null, 'Stock returned to inventory.', false, $user);
                }
            }
            $this->refreshCustomerTotals($order);
        });

        if ($notify) {
            $this->mailCustomer($order, 'status');
        }

        return $order->refresh();
    }

    /** Called from the verified webhook / verification endpoint. Idempotent. */
    public function markPaid(Order $order, string $reference, array $details = []): void
    {
        DB::transaction(function () use ($order, $reference, $details) {
            $o = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($o->payment_status === 'paid' || $o->payment_status === 'refunded') {
                return;
            }
            $from = $o->payment_status;
            $o->payment_status = 'paid';
            $o->paid_at = now();
            if ($o->status === 'pending') {
                $o->status = 'confirmed';
                $this->event($o, 'status', 'pending', 'confirmed', 'Payment received.', true);
            }
            if ($o->stock_released_at !== null && $o->status !== 'cancelled') {
                $this->event($o, 'note', null, null, 'Payment arrived after the reserved stock was released. Check stock before shipping.', false);
            }
            $o->save();
            $o->payments()->where('provider_reference', $reference)->update(['status' => 'paid', 'details' => $details, 'failure_message' => null]);
            $this->event($o, 'payment', $from, 'paid', 'Payment confirmed by Stripe.', false);
            $this->refreshCustomerTotals($o);
            $order->setRawAttributes($o->getAttributes(), true);
        });
        $this->mailCustomer($order->refresh(), 'paid');
    }

    public function markFailed(Order $order, string $reference, ?string $message): void
    {
        DB::transaction(function () use ($order, $reference, $message) {
            $o = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (in_array($o->payment_status, ['paid', 'refunded'], true)) {
                return;
            }
            // The customer may retry with another card on the same PaymentIntent; stock stays reserved until it expires.
            $o->payment_status = 'failed';
            $o->save();
            $o->payments()->where('provider_reference', $reference)->update(['status' => 'failed', 'failure_message' => $message ? mb_substr($message, 0, 500) : null]);
            $this->event($o, 'payment', 'pending', 'failed', $message ?: 'Card payment failed.', false);
        });
    }

    public function markRefunded(Order $order, float $amount, ?User $user = null): void
    {
        DB::transaction(function () use ($order, $amount, $user) {
            $o = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($o->payment_status === 'refunded') {
                return;
            }
            $full = $amount + 0.009 >= (float) $o->total;
            if ($full) {
                $from = $o->payment_status;
                $o->payment_status = 'refunded';
                $o->save();
                $o->payments()->where('status', 'paid')->update(['status' => 'refunded']);
                $this->event($o, 'payment', $from, 'refunded', 'Refunded '.number_format($amount, 2).' '.$o->currency.'.', true, $user);
            } else {
                $this->event($o, 'payment', null, null, 'Partial refund of '.number_format($amount, 2).' '.$o->currency.'.', false, $user);
            }
            $this->refreshCustomerTotals($o);
        });
    }

    /**
     * Cancels unpaid card orders older than the timeout and returns their stock (run every 5 minutes by the scheduler).
     */
    public function expireUnpaidCardOrders(?StripeGateway $stripe = null): int
    {
        $minutes = max(15, (int) Setting::get('card_payment_timeout_minutes', 60));
        $orders = Order::query()
            ->where('payment_method', '!=', 'cod')
            ->whereIn('payment_status', ['pending', 'failed'])
            ->where('status', 'pending')
            ->whereNotNull('stock_reserved_at')->whereNull('stock_released_at')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->limit(100)->get();
        $n = 0;
        foreach ($orders as $order) {
            $ref = $order->payments()->whereNotNull('provider_reference')->latest('id')->value('provider_reference');
            if ($ref && ! $stripe) {
                continue; // cannot ask Stripe right now; never cancel an order that might be paid
            }
            if ($ref && $stripe) {
                try {
                    $pi = $stripe->retrieveIntent($ref);
                    if ($pi->status === 'succeeded') {
                        $this->markPaid($order, $ref, $this->intentDetails($pi));

                        continue;
                    }
                    if (in_array($pi->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                        $stripe->cancelIntent($ref);
                    }
                } catch (Throwable $e) {
                    Log::warning('Stripe check failed while expiring order '.$order->number.': '.$e->getMessage());

                    continue; // try again next run rather than cancelling a possibly-paid order
                }
            }
            DB::transaction(function () use ($order) {
                $order->status = 'cancelled';
                $order->save();
                $order->payments()->whereIn('status', ['pending', 'failed'])->update(['status' => 'cancelled']);
                $this->event($order, 'status', 'pending', 'cancelled', 'Cancelled: card payment was not completed in time.', true);
                $this->stock->releaseForOrder($order, 'payment_expired');
            });
            $n++;
        }

        return $n;
    }

    public function intentDetails(object $pi): array
    {
        $charge = is_object($pi->latest_charge ?? null) ? $pi->latest_charge : null;
        $card = $charge?->payment_method_details?->card ?? null;

        return array_filter([
            'type' => $charge?->payment_method_details?->type ?? null,
            'brand' => $card?->brand ?? null,
            'last4' => $card?->last4 ?? null,
            'wallet' => $card?->wallet?->type ?? null, // apple_pay, google_pay
        ]);
    }

    private function refreshCustomerTotals(Order $order): void
    {
        if (! $order->customer_id) {
            return;
        }
        $spent = Order::query()->where('customer_id', $order->customer_id)->where('payment_status', 'paid')->sum('total');
        Customer::query()->whereKey($order->customer_id)->update(['total_spent' => $spent]);
    }

    public function mailCustomer(Order $order, string $kind): void
    {
        try {
            Mail::to($order->customer_email)->send(new OrderMail($order->loadMissing('items'), $kind));
        } catch (Throwable $e) {
            Log::warning('Order email failed for '.$order->number.': '.$e->getMessage());
        }
    }

    private function notifyNewOrder(Order $order): void
    {
        $this->mailCustomer($order, 'placed');
        $to = Setting::get('order_notification_email') ?: Setting::get('contact_email');
        if ($to) {
            try {
                Mail::to($to)->send(new OrderMail($order->loadMissing('items'), 'admin_new'));
            } catch (Throwable $e) {
                Log::warning('Admin order email failed for '.$order->number.': '.$e->getMessage());
            }
        }
    }
}
