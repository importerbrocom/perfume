<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\Fixnote;
use App\Services\OrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FixnoteTest extends TestCase
{
    private function fixnote(): Fixnote
    {
        return app(Fixnote::class);
    }

    /** @return array<string,int> size => variant id of one imported perfume */
    private function sizes(string $code = 'F01'): array
    {
        return Product::query()->where('sku', $code)->firstOrFail()->variants->mapWithKeys(fn ($v) => [$v->name => $v->id])->all();
    }

    private function quote(array $items, string $country = 'AE'): array
    {
        return $this->postJson('/api/cart/quote', ['items' => $items, 'country' => $country])->assertOk()->json();
    }

    public function test_catalogue_import_adds_all_perfumes_once_with_three_sizes(): void
    {
        $this->artisan('shop:import-fixnote')->expectsOutputToContain('Fixnote perfumes added: 378')->assertSuccessful();
        $this->artisan('shop:import-fixnote')->expectsOutputToContain('added: 0 (already in the shop: 378)')->assertSuccessful();

        $p = Product::query()->where('sku', 'F01')->with('variants', 'brand', 'categories')->firstOrFail();
        $this->assertSame(['Imagination', 'Louis Vuitton', 'fixnote', 'Extrait Perfume'], [$p->name, $p->inspired_by, $p->brand->slug, $p->concentration]);
        $this->assertStringStartsWith('Ambrox ■ Black Tea', $p->accords);
        $this->assertSame(['30 ML', '50 ML', '100 ML'], $p->variants->pluck('name')->all());
        $this->assertSame(['F01-30', 'F01-50', 'F01-100'], $p->variants->pluck('sku')->all());
        $this->assertEquals([29, 39, 59], $p->variants->pluck('price')->map(fn ($x) => (float) $x)->all());
        $this->assertTrue($p->variants->every(fn ($v) => ! $v->track_stock && $v->inStock()));
        $this->assertContains('inspired-fragrances', $p->categories->pluck('slug')->all());

        // A perfume the admin deleted is not brought back
        $p->delete();
        $this->artisan('shop:import-fixnote')->expectsOutputToContain('added: 0')->assertSuccessful();
        $this->assertSame(0, Product::query()->where('sku', 'F01')->count());
    }

    public function test_public_catalogue_groups_by_inspired_brand_and_hides_hidden_perfumes(): void
    {
        $this->fixnote()->import();
        Product::query()->where('sku', 'F02')->update(['is_active' => false]);
        $this->product(['name' => 'Perfumarathi Oud']); // other brand: not on the Fixnote page

        $r = $this->getJson('/api/fixnote')->assertOk();
        $codes = collect($r->json('items'))->pluck('code');
        $this->assertCount(377, $codes);
        $this->assertFalse($codes->contains('F02'));
        $this->assertSame('Louis Vuitton', $r->json('groups.0'));
        $this->assertCount(28, $r->json('groups'));
        $this->assertSame('A PERFUMARATHI PRODUCTION', $r->json('page.hero_eyebrow'));
        $this->assertEquals(['30 ML' => 1199, '50 ML' => 1499, '100 ML' => 1999], $r->json('regions.INDIA.prices'));
        $this->assertSame(['INR', 'IN', true, false], [$r->json('regions.INDIA.currency'), $r->json('regions.INDIA.country'), $r->json('regions.INDIA.cod'), $r->json('regions.SAUDI.cod')]);
        $this->assertSame('100 ML', $r->json('items.0.variants.2.size'));
    }

    public function test_uae_delivery_depends_on_sizes_and_is_free_from_five_pieces(): void
    {
        $this->fixnote()->import();
        $a = $this->sizes('F01');
        $b = $this->sizes('F02');

        $q = $this->quote([['variant_id' => $a['30 ML'], 'quantity' => 4]]);
        $this->assertSame([116.0, 14.0, 130.0], [(float) $q['subtotal'], (float) $q['shipping'], (float) $q['total']]);
        $q = $this->quote([['variant_id' => $a['50 ML'], 'quantity' => 4]]);
        $this->assertSame(165.0, (float) $q['total']);
        $q = $this->quote([['variant_id' => $a['100 ML'], 'quantity' => 4]]);
        $this->assertSame(240.0, (float) $q['total']);
        $q = $this->quote([['variant_id' => $a['100 ML'], 'quantity' => 3], ['variant_id' => $b['30 ML'], 'quantity' => 1]]);
        $this->assertSame(14.0, (float) $q['shipping']); // the highest size charge applies
        $q = $this->quote([['variant_id' => $a['30 ML'], 'quantity' => 4], ['variant_id' => $b['100 ML'], 'quantity' => 1]]);
        $this->assertSame([0.0, 5, 5], [(float) $q['shipping'], $q['fixnote_delivery']['pieces'], $q['fixnote_delivery']['free_from']]);

        // Other countries use the normal delivery charge; with other products the higher charge applies
        $this->settings(['shipping_flat_rate' => 25]);
        $q = $this->quote([['variant_id' => $a['30 ML'], 'quantity' => 1]], 'QA');
        $this->assertSame([25.0, null], [(float) $q['shipping'], $q['fixnote_delivery']]);
        $other = $this->variant($this->product());
        $q = $this->quote([['variant_id' => $a['100 ML'], 'quantity' => 1], ['variant_id' => $other->id, 'quantity' => 1]]);
        $this->assertSame(25.0, (float) $q['shipping']);
    }

    public function test_india_and_saudi_order_fixnote_online_in_their_own_currency(): void
    {
        $this->fixnote()->import();
        $s = $this->sizes();
        $other = $this->variant($this->product());

        // At UAE prices, Fixnote cannot be sent to India / Saudi
        $q = $this->quote([['variant_id' => $s['100 ML'], 'quantity' => 1]], 'IN');
        $this->assertStringContainsString('choosing your region', $q['issues'][0]['message']);

        // India bag: INR prices, India delivery (30 ML 14, 50 ML 9, 100 ML 0), COD + pay after confirmation, no card
        $this->enableCards();
        $india = fn (array $items, array $extra = []) => $this->postJson('/api/cart/quote', ['items' => $items, 'country' => 'IN', 'region' => 'INDIA'] + $extra)->assertOk()->json();
        $q = $india([['variant_id' => $s['30 ML'], 'quantity' => 2], ['variant_id' => $s['100 ML'], 'quantity' => 1]]);
        $this->assertSame(['INR', 'INDIA', []], [$q['currency'], $q['region'], $q['issues']]);
        $this->assertEquals([1199 * 2 + 1999, 14, 1199 * 2 + 1999 + 14], [$q['subtotal'], $q['shipping'], $q['total']]);
        $this->assertSame(['cod', 'transfer'], array_column($q['payment_methods'], 'code'));
        $q = $india([['variant_id' => $s['100 ML'], 'quantity' => 4]], ['coupon_code' => 'ANY']);
        $this->assertEquals([7996, 0], [$q['total'], $q['shipping']]);
        $this->assertStringContainsString('Coupons cannot be used', $q['coupon_message']);
        $q = $india([['variant_id' => $other->id, 'quantity' => 1]]);
        $this->assertStringContainsString('Only Fixnote perfumes', $q['issues'][0]['message']);
        $q = $this->postJson('/api/cart/quote', ['items' => [['variant_id' => $s['30 ML'], 'quantity' => 1]], 'country' => 'AE', 'region' => 'INDIA'])->json();
        $this->assertFalse($q['ships_to_country']);

        // Saudi: SAR, no cash on delivery
        $q = $this->postJson('/api/cart/quote', ['items' => [['variant_id' => $s['50 ML'], 'quantity' => 1]], 'country' => 'SA', 'region' => 'SAUDI'])->json();
        $this->assertEquals(['SAR', 39], [$q['currency'], $q['total']]);
        $this->assertSame(['transfer'], array_column($q['payment_methods'], 'code'));
        $this->postJson('/api/checkout', $this->checkoutData($s['50 ML'], 1, 'cod', ['country' => 'SA', 'region' => 'SAUDI']))->assertStatus(422)->assertJsonValidationErrors('payment_method');

        // Order placed in INR, pay after confirmation; admin marks it paid, then it can ship
        $r = $this->postJson('/api/checkout', $this->checkoutData($s['50 ML'], 2, 'transfer', ['country' => 'IN', 'region' => 'INDIA']))->assertCreated()
            ->assertJsonPath('currency', 'INR')->assertJsonPath('requires_payment', false);
        $order = Order::query()->where('number', $r->json('number'))->firstOrFail();
        $this->assertEquals([2998 + 9, 'pending', 'transfer'], [$order->total, $order->payment_status, $order->payment_method]);
        $this->getJson("/api/orders/{$order->number}?token={$order->access_token}")->assertOk()->assertJsonPath('items.0.unit_price', 1499);

        $this->actingAs($this->admin(), 'sanctum');
        $this->putJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->putJson("/api/admin/orders/{$order->id}/status", ['status' => 'shipped'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/admin/orders/{$order->id}/paid", ['note' => 'UPI ref 123'])->assertOk()->assertJsonPath('payment_status', 'paid');
        $this->putJson("/api/admin/orders/{$order->id}/status", ['status' => 'shipped'])->assertOk();
        $this->assertSame(0.0, (float) $this->getJson('/api/admin/dashboard')->json('sales_total')); // INR is not added to AED sales

        // Unpaid transfer orders are not cancelled by the card-payment timeout
        $late = $this->postJson('/api/checkout', $this->checkoutData($s['30 ML'], 1, 'transfer', ['country' => 'IN', 'region' => 'INDIA']))->assertCreated()->json('number');
        Order::query()->where('number', $late)->update(['created_at' => now()->subDays(2)]);
        app(OrderService::class)->expireUnpaidCardOrders();
        $this->assertSame('pending', Order::query()->where('number', $late)->value('status'));

        // Other brands still ship to India at store prices; UAE Fixnote still works
        $this->postJson('/api/checkout', $this->checkoutData($other->id, 1, 'cod', ['country' => 'IN']))->assertCreated();
        $this->postJson('/api/checkout', $this->checkoutData($s['100 ML']))->assertCreated()->assertJsonPath('currency', 'AED');
        $this->postJson('/api/checkout', $this->checkoutData($s['100 ML'], 1, 'transfer'))->assertStatus(422); // UAE: no transfer
    }

    public function test_admin_sets_prices_for_all_sizes_and_adds_a_perfume(): void
    {
        $this->fixnote()->import();
        $this->actingAs($this->admin('manager'), 'sanctum');
        $this->getJson('/api/admin/fixnote')->assertOk()->assertJsonPath('counts.products', 378);

        $prices = ['30 ML' => ['price' => 31, 'sale_price' => null], '50 ML' => ['price' => 41, 'sale_price' => 40], '100 ML' => ['price' => 61, 'sale_price' => null]];
        $this->putJson('/api/admin/fixnote/prices', ['apply_to_all' => true, 'prices' => ['30 ML' => ['price' => 10, 'sale_price' => 12]]])
            ->assertStatus(422)->assertJsonValidationErrors('prices.30 ML.sale_price');
        $this->putJson('/api/admin/fixnote/prices', ['apply_to_all' => true, 'prices' => $prices])->assertOk()->assertJsonPath('updated', 378 * 3);
        $v = Product::query()->where('sku', 'F300')->firstOrFail()->variants->keyBy('name');
        $this->assertSame([31.0, 40.0, 61.0], [$v['30 ML']->currentPrice(), $v['50 ML']->currentPrice(), $v['100 ML']->currentPrice()]);

        $this->postJson('/api/admin/fixnote/products', ['code' => 'f01', 'name' => 'Copy'])->assertStatus(422)->assertJsonValidationErrors('code');
        $new = $this->postJson('/api/admin/fixnote/products', ['code' => 'F500', 'name' => 'Desert Rose', 'inspired_by' => 'Amouage', 'notes' => 'Rose ■ Oud'])
            ->assertCreated()->assertJsonPath('inspired_by', 'Amouage')->json();
        $this->assertSame(['30 ML', '50 ML', '100 ML'], array_column($new['variants'], 'name'));
        $this->assertEquals([31, 41, 61], array_column($new['variants'], 'price'));
        $this->assertEquals(40, $new['variants'][1]['current_price']);

        // Only the size whose price changed is updated everywhere
        Product::query()->where('sku', 'F02')->first()->variants->firstWhere('name', '100 ML')->update(['price' => 75]);
        $prices['30 ML']['price'] = 33;
        $this->putJson('/api/admin/fixnote/prices', ['apply_to_all' => true, 'prices' => $prices])->assertOk()->assertJsonPath('updated', 379);
        $f02 = Product::query()->where('sku', 'F02')->first()->variants->keyBy('name');
        $this->assertEquals([33, 75], [$f02['30 ML']->price, $f02['100 ML']->price]);

        // Prices for new perfumes only
        $this->putJson('/api/admin/fixnote/prices', ['apply_to_all' => false, 'prices' => ['30 ML' => ['price' => 99]]])->assertOk()->assertJsonPath('updated', 0);
        $this->assertSame(33.0, Product::query()->where('sku', 'F01')->first()->variants->first()->currentPrice()); // unchanged by the last save
    }

    public function test_admin_edits_page_texts_regions_delivery_and_pictures(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin(), 'sanctum');
        $page = $this->getJson('/api/admin/fixnote')->json('page');
        $page['hero_title'] = 'Discover';
        $page['reviews'] = [['text' => 'Lovely!'], ['text' => '']];
        $page['offers']['UAE'] = [['tag' => 'NEW', 'title' => '3 for 99 AED', 'text' => 'This week', 'highlight' => true]];
        $body = [
            'page' => $page,
            'delivery' => ['enabled' => true, 'countries' => ['ae', 'qa'], 'fees' => ['30 ML' => 10, '50 ML' => 8, '100 ML' => 0], 'free_from_pieces' => 3],
            'regions' => ['INDIA' => ['currency' => 'inr', 'prices' => ['30 ML' => 999, '50 ML' => 1299, '100 ML' => 1799]], 'SAUDI' => ['currency' => 'SAR', 'prices' => ['30 ML' => 30, '50 ML' => 40, '100 ML' => null]]],
        ];
        $this->putJson('/api/admin/fixnote', ['page' => ['footer_email' => 'not-an-email']] + $body)->assertStatus(422)->assertJsonValidationErrors('page.footer_email');
        $this->putJson('/api/admin/fixnote', $body)->assertOk()
            ->assertJsonPath('page.hero_title', 'Discover')
            ->assertJsonPath('page.reviews', [['text' => 'Lovely!']]) // empty rows are dropped
            ->assertJsonPath('delivery.countries', ['AE', 'QA'])
            ->assertJsonPath('regions.INDIA.currency', 'INR');

        $this->post('/api/admin/fixnote/images', ['hero' => UploadedFile::fake()->image('hero.jpg', 800, 400)], ['Accept' => 'application/json'])->assertOk();
        $pub = $this->getJson('/api/fixnote')->assertOk();
        $this->assertSame('Discover', $pub->json('page.hero_title'));
        $this->assertTrue($pub->json('page.offers.UAE.0.highlight'));
        $this->assertEquals(1799, $pub->json('regions.INDIA.prices.100 ML'));
        $this->assertNotNull($pub->json('images.hero'));
        $this->assertNull($pub->json('images.bottle'));
        $this->post('/api/admin/fixnote/images', ['reset_hero' => true], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('images.hero', null);
    }

    public function test_fixnote_orders_filter_and_inspired_by_search(): void
    {
        $this->fixnote()->import();
        $this->actingAs($this->admin(), 'sanctum');
        $fx = $this->postJson('/api/checkout', $this->checkoutData($this->sizes()['50 ML']))->assertCreated()->json('number');
        $this->postJson('/api/checkout', $this->checkoutData($this->variant($this->product())->id))->assertCreated();
        $this->assertSame(2, Order::query()->count());

        $r = $this->getJson('/api/admin/orders?brand=fixnote')->assertOk();
        $this->assertSame([$fx], collect($r->json('data'))->pluck('number')->all());
        $this->assertEquals(['pending' => 1], $r->json('counts'));

        $names = collect($this->getJson('/api/admin/products?brand=fixnote&search=Xerjoff&per_page=100')->assertOk()->json('data'))->pluck('inspired_by')->unique();
        $this->assertSame(['Xerjoff'], $names->values()->all());
        $this->assertSame(378, $this->getJson('/api/admin/products?brand=fixnote')->json('meta.total'));
        $this->assertSame('Louis Vuitton', $this->getJson('/api/products/f01-imagination')->assertOk()->json('product.inspired_by'));
    }
}
