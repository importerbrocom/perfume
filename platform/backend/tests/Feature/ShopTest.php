<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentMethod;
use Tests\TestCase;

class ShopTest extends TestCase
{
    public function test_store_lists_only_enabled_and_configured_payment_methods(): void
    {
        $codes = collect($this->getJson('/api/store')->assertOk()->json('payment_methods'))->pluck('code');
        $this->assertSame(['cod'], $codes->all()); // card is disabled until Stripe keys are entered

        $this->enableCards();
        $codes = collect($this->getJson('/api/store')->json('payment_methods'))->pluck('code');
        $this->assertSame(['cod', 'card'], $codes->all());

        PaymentMethod::query()->where('code', 'cod')->update(['is_enabled' => false]);
        $this->assertSame(['card'], collect($this->getJson('/api/store')->json('payment_methods'))->pluck('code')->all());
    }

    public function test_store_never_exposes_secrets(): void
    {
        $this->enableCards();
        $body = $this->getJson('/api/store')->getContent().$this->postJson('/api/cart/quote', ['items' => [['variant_id' => $this->variant($this->product())->id, 'quantity' => 1]]])->getContent();
        $this->assertStringNotContainsString('sk_test_abc', $body);
        $this->assertStringNotContainsString('whsec_', $body);
        $this->assertStringNotContainsString('credentials', $body);
    }

    public function test_product_list_hides_inactive_and_shows_price_on_request(): void
    {
        $this->product(['name' => 'Visible', 'slug' => 'visible']);
        $this->product(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);
        $this->product(['name' => 'Unpriced', 'slug' => 'unpriced'], ['price' => null]);

        $items = collect($this->getJson('/api/products?sort=name')->assertOk()->json('data'));
        $this->assertSame(['Unpriced', 'Visible'], $items->pluck('name')->all());
        $this->assertFalse($items->firstWhere('slug', 'unpriced')['purchasable']);
        $this->assertTrue($items->firstWhere('slug', 'visible')['purchasable']);
        $this->getJson('/api/products/hidden')->assertNotFound();
        $this->getJson('/api/products/visible')->assertOk()->assertJsonPath('product.variants.0.current_price', 150);
    }

    public function test_product_list_filters_by_ids_for_the_wishlist(): void
    {
        $a = $this->product(['name' => 'Wish A', 'slug' => 'wish-a']);
        $this->product(['name' => 'Wish B', 'slug' => 'wish-b']);
        $hidden = $this->product(['name' => 'Wish C', 'slug' => 'wish-c', 'is_active' => false]);

        $slugs = collect($this->getJson("/api/products?ids={$a->id},{$hidden->id}")->assertOk()->json('data'))->pluck('slug');
        $this->assertSame(['wish-a'], $slugs->all());
        $this->getJson('/api/products?ids=1;drop')->assertStatus(422);
    }

    public function test_quote_is_computed_on_the_server(): void
    {
        $p = $this->product([], ['price' => 200, 'sale_price' => 150]);
        $this->settings(['shipping_flat_rate' => 20, 'free_shipping_over' => 500]);
        PaymentMethod::query()->where('code', 'cod')->update(['fee' => 10]);
        Coupon::create(['code' => 'WELCOME10', 'type' => 'percent', 'value' => 10]);

        $q = $this->postJson('/api/cart/quote', [
            'items' => [['variant_id' => $this->variant($p)->id, 'quantity' => 2, 'price' => 1]], // client price is ignored
            'coupon_code' => 'welcome10', 'country' => 'AE', 'payment_method' => 'cod',
        ])->assertOk()->json();

        $this->assertEquals(300, $q['subtotal']);
        $this->assertEquals(30, $q['discount']);
        $this->assertEquals(20, $q['shipping']);
        $this->assertEquals(10, $q['payment_fee']);
        $this->assertEquals(300, $q['total']);

        $q2 = $this->postJson('/api/cart/quote', ['items' => [['variant_id' => $this->variant($p)->id, 'quantity' => 4]], 'country' => 'AE'])->json();
        $this->assertEquals(0, $q2['shipping']); // free over 500
    }

    public function test_cod_checkout_creates_order_and_deducts_stock(): void
    {
        $p = $this->product();
        $v = $this->variant($p);
        $res = $this->postJson('/api/checkout', $this->checkoutData($v->id, 2))->assertCreated()->json();

        $order = Order::query()->where('number', $res['number'])->firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertSame('cod_pending', $order->payment_status);
        $this->assertSame('aisha@example.test', $order->customer_email);
        $this->assertEquals(300, (float) $order->total);
        $this->assertSame(3, $v->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $v->id, 'change' => -2, 'reason' => 'order']);
        $this->assertDatabaseHas('customers', ['email' => 'aisha@example.test', 'orders_count' => 1]);
        $this->assertFalse($res['requires_payment']);
    }

    public function test_cannot_order_more_than_in_stock_or_unpriced_or_disabled_method(): void
    {
        $p = $this->product([], ['stock_quantity' => 2]);
        $v = $this->variant($p);
        $this->postJson('/api/checkout', $this->checkoutData($v->id, 3))->assertStatus(422)->assertJsonFragment(['Only 2 of Kausar 100 ml left in stock.']);
        $this->assertSame(2, $v->fresh()->stock_quantity);

        $this->postJson('/api/checkout', $this->checkoutData($v->id, 2))->assertCreated();
        $this->postJson('/api/checkout', $this->checkoutData($v->id, 1))->assertStatus(422); // sold out now
        $this->assertSame(0, $v->fresh()->stock_quantity);

        $u = $this->product(['slug' => 'no-price'], ['price' => null]);
        $this->postJson('/api/checkout', $this->checkoutData($this->variant($u)->id))->assertStatus(422);

        $ok = $this->product(['slug' => 'ok']);
        $this->postJson('/api/checkout', $this->checkoutData($this->variant($ok)->id, 1, 'card'))->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_checkout_validates_input(): void
    {
        $p = $this->product();
        $this->postJson('/api/checkout', ['items' => [['variant_id' => $this->variant($p)->id, 'quantity' => 1]], 'payment_method' => 'cod'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'phone', 'country', 'city', 'address', 'accept_terms']);
        $this->postJson('/api/checkout', $this->checkoutData($this->variant($p)->id, 1, 'cod', ['phone' => 'call me']))->assertJsonValidationErrors('phone');
        $this->settings(['shipping_countries' => ['AE']]);
        $this->postJson('/api/checkout', $this->checkoutData($this->variant($p)->id, 1, 'cod', ['country' => 'US']))->assertJsonValidationErrors('country');
    }

    public function test_coupon_usage_limit(): void
    {
        $p = $this->product([], ['stock_quantity' => 10]);
        Coupon::create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 20, 'usage_limit' => 1]);
        $this->postJson('/api/checkout', $this->checkoutData($this->variant($p)->id, 1, 'cod', ['coupon_code' => 'ONCE']))->assertCreated();
        $this->postJson('/api/checkout', $this->checkoutData($this->variant($p)->id, 1, 'cod', ['coupon_code' => 'ONCE']))
            ->assertStatus(422)->assertJsonValidationErrors('coupon_code');
    }

    public function test_tracking_needs_number_and_matching_email(): void
    {
        $p = $this->product();
        $num = $this->postJson('/api/checkout', $this->checkoutData($this->variant($p)->id))->json('number');
        $this->postJson('/api/track', ['number' => $num, 'email' => 'someone@else.test'])->assertNotFound();
        $this->postJson('/api/track', ['number' => strtolower($num), 'email' => 'AISHA@example.test'])->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('timeline.0.done', true)->assertJsonMissingPath('customer_email');
    }

    public function test_order_page_needs_the_access_token(): void
    {
        $p = $this->product();
        $res = $this->postJson('/api/checkout', $this->checkoutData($this->variant($p)->id))->json();
        $this->getJson('/api/orders/'.$res['number'].'?token=wrong')->assertNotFound();
        $this->getJson('/api/orders/'.$res['number'].'?token='.$res['token'])->assertOk()->assertJsonPath('number', $res['number']);
    }

    public function test_preview_gate_locks_the_shop_but_not_webhooks(): void
    {
        config(['shop.gate_key' => 'preview-123']);
        $this->getJson('/api/store')->assertStatus(401)->assertJsonPath('gate', true);
        $this->getJson('/api/store', ['X-Shop-Gate' => 'preview-123'])->assertOk();
        $this->postJson('/api/webhooks/stripe', [])->assertStatus(400); // reaches the controller (bad signature), not the gate
        $this->get('/')->assertStatus(401)->assertSee('PRIVATE PREVIEW');
    }

    public function test_security_headers(): void
    {
        $this->getJson('/api/store')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
