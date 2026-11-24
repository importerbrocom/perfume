<?php

namespace Tests;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\StripeGateway;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeStripe;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(DatabaseSeeder::class);
        $this->stripe = new FakeStripe;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    protected function admin(string $role = 'admin'): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $role.'@example.test', 'password' => 'Secret12345', 'role' => $role, 'is_active' => true]);
    }

    protected function product(array $attrs = [], array $variant = []): Product
    {
        $p = Product::create($attrs + ['name' => 'Kausar', 'slug' => 'kausar-'.uniqid(), 'is_active' => true, 'brand_id' => 1]);
        $p->variants()->create($variant + ['name' => '100 ml', 'price' => 150, 'track_stock' => true, 'stock_quantity' => 5]);

        return $p->load('variants');
    }

    protected function variant(Product $p): ProductVariant
    {
        return $p->variants()->first();
    }

    protected function enableCards(): PaymentMethod
    {
        $m = PaymentMethod::query()->where('code', 'card')->first();
        $m->options = ['publishable_key' => 'pk_test_abc', 'mode' => 'test'];
        $m->credentials = ['secret_key' => 'sk_test_abc', 'webhook_secret' => 'whsec_testsecret'];
        $m->is_enabled = true;
        $m->save();

        return $m;
    }

    protected function checkoutData(int $variantId, int $qty = 1, string $method = 'cod', array $extra = []): array
    {
        return $extra + [
            'items' => [['variant_id' => $variantId, 'quantity' => $qty]],
            'name' => 'Aisha Khan', 'email' => 'Aisha@example.test', 'phone' => '+971 50 123 4567',
            'country' => 'AE', 'city' => 'Dubai', 'address' => 'Villa 12, Al Barsha 1', 'payment_method' => $method,
            'accept_terms' => true,
        ];
    }

    protected function settings(array $values): void
    {
        Setting::put($values);
    }
}
