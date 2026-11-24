<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Testing\TestResponse;
use Stripe\Webhook;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    private function cardOrder(int $qty = 1): array
    {
        $this->enableCards();
        $p = $this->product();
        $res = $this->postJson('/api/checkout', $this->checkoutData($this->variant($p)->id, $qty, 'card'))->assertCreated()->json();
        $this->assertTrue($res['requires_payment']);

        return [$res, $p];
    }

    private function webhook(array $event, string $secret = 'whsec_testsecret'): TestResponse
    {
        $payload = json_encode($event);
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$payload, $secret);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t=$ts,v1=$sig", 'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    public function test_card_order_starts_pending_and_gets_a_payment_intent(): void
    {
        [$res] = $this->cardOrder();
        $order = Order::query()->where('number', $res['number'])->first();
        $this->assertSame('pending', $order->payment_status);

        $pay = $this->postJson("/api/orders/{$res['number']}/pay", ['token' => $res['token']])->assertOk()->json();
        $this->assertSame('pi_test_1_secret_x', $pay['client_secret']);
        $this->assertSame('pk_test_abc', $pay['publishable_key']);
        $this->assertSame(15000, $this->stripe->intents['pi_test_1']['amount']); // AED 150.00 in fils
        $this->assertArrayNotHasKey('secret_key', $pay);

        // Asking again reuses the same PaymentIntent.
        $this->postJson("/api/orders/{$res['number']}/pay", ['token' => $res['token']])->assertOk();
        $this->assertCount(1, $this->stripe->intents);
        $this->postJson("/api/orders/{$res['number']}/pay", ['token' => 'nope'])->assertNotFound();
    }

    public function test_signed_webhook_marks_the_order_paid_once(): void
    {
        [$res] = $this->cardOrder();
        $this->postJson("/api/orders/{$res['number']}/pay", ['token' => $res['token']]);
        $this->stripe->succeed('pi_test_1');
        $event = ['id' => 'evt_1', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test_1', 'object' => 'payment_intent']]];

        $this->webhook($event)->assertOk();
        $order = Order::query()->where('number', $res['number'])->first();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('apple_pay', $order->payments()->first()->details['wallet']);

        $this->webhook($event)->assertOk()->assertSee('Already processed');
        $this->assertSame(1, $order->events()->where('type', 'payment')->where('to_value', 'paid')->count());
    }

    public function test_webhook_with_bad_signature_is_rejected(): void
    {
        [$res] = $this->cardOrder();
        $this->postJson("/api/orders/{$res['number']}/pay", ['token' => $res['token']]);
        $this->stripe->succeed('pi_test_1');
        $event = ['id' => 'evt_2', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test_1']]];

        $this->webhook($event, 'whsec_wrong')->assertStatus(400);
        $this->assertSame('pending', Order::query()->where('number', $res['number'])->value('payment_status'));
    }

    public function test_webhook_cannot_mark_paid_if_stripe_says_not_paid(): void
    {
        [$res] = $this->cardOrder();
        $this->postJson("/api/orders/{$res['number']}/pay", ['token' => $res['token']]);
        // Signed event claims success, but the PaymentIntent (asked from Stripe) is not paid.
        $this->webhook(['id' => 'evt_3', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test_1']]])->assertOk();
        $this->assertSame('pending', Order::query()->where('number', $res['number'])->value('payment_status'));
    }

    public function test_thank_you_page_confirms_with_stripe_before_the_webhook(): void
    {
        [$res] = $this->cardOrder();
        $this->postJson("/api/orders/{$res['number']}/pay", ['token' => $res['token']]);
        $this->getJson("/api/orders/{$res['number']}?token={$res['token']}")->assertJsonPath('payment_status', 'pending');
        $this->stripe->succeed('pi_test_1', 'google_pay');
        $this->getJson("/api/orders/{$res['number']}?token={$res['token']}")->assertJsonPath('payment_status', 'paid')->assertJsonPath('status', 'confirmed');
    }

    public function test_failed_payment_then_expiry_returns_stock(): void
    {
        [$res, $p] = $this->cardOrder(2);
        $v = $this->variant($p);
        $this->assertSame(3, $v->fresh()->stock_quantity);
        $this->postJson("/api/orders/{$res['number']}/pay", ['token' => $res['token']]);
        $this->webhook(['id' => 'evt_f', 'object' => 'event', 'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => 'pi_test_1', 'last_payment_error' => ['message' => 'Your card was declined.']]]])->assertOk();
        $order = Order::query()->where('number', $res['number'])->first();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame(3, $v->fresh()->stock_quantity); // still reserved: the customer may retry

        $order->forceFill(['created_at' => now()->subHours(2)])->save();
        $this->assertSame(1, app(OrderService::class)->expireUnpaidCardOrders($this->stripe));
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, $v->fresh()->stock_quantity);
        $this->assertSame('canceled', $this->stripe->intents['pi_test_1']['status']);
        $this->assertSame(0, app(OrderService::class)->expireUnpaidCardOrders($this->stripe)); // idempotent
        $this->assertSame(5, $v->fresh()->stock_quantity);
    }

    public function test_expiry_does_not_cancel_an_order_that_stripe_says_is_paid(): void
    {
        [$res] = $this->cardOrder();
        $this->postJson("/api/orders/{$res['number']}/pay", ['token' => $res['token']]);
        $this->stripe->succeed('pi_test_1');
        Order::query()->where('number', $res['number'])->update(['created_at' => now()->subHours(2)]);
        app(OrderService::class)->expireUnpaidCardOrders($this->stripe);
        $this->assertSame('paid', Order::query()->where('number', $res['number'])->value('payment_status'));
    }

    public function test_admin_refund_goes_through_stripe(): void
    {
        [$res] = $this->cardOrder();
        $this->postJson("/api/orders/{$res['number']}/pay", ['token' => $res['token']]);
        $this->stripe->succeed('pi_test_1');
        $this->getJson("/api/orders/{$res['number']}?token={$res['token']}");
        $id = Order::query()->where('number', $res['number'])->value('id');

        $this->actingAs($this->admin('manager'), 'sanctum')->postJson("/api/admin/orders/$id/refund")->assertForbidden();
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/admin/orders/$id/refund")->assertOk()->assertJsonPath('payment_status', 'refunded');
        $this->assertSame(['pi_test_1'], $this->stripe->refunds);
    }

    public function test_stripe_signature_helper_matches_sdk(): void
    {
        $payload = '{"id":"evt_x"}';
        $ts = time();
        $sig = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$payload, 'whsec_testsecret');
        $this->assertSame('evt_x', Webhook::constructEvent($payload, $sig, 'whsec_testsecret')->id);
    }
}
