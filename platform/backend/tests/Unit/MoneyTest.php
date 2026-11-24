<?php

namespace Tests\Unit;

use App\Models\Coupon;
use App\Services\StripeGateway;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_stripe_amounts_use_the_currency_minor_unit(): void
    {
        $this->assertSame(15000, StripeGateway::toMinor(150.00, 'AED'));
        $this->assertSame(1999, StripeGateway::toMinor(19.99, 'aed'));
        $this->assertSame(1000, StripeGateway::toMinor(1000, 'JPY'));
        $this->assertSame(150.0, StripeGateway::fromMinor(15000, 'AED'));
    }

    public function test_coupon_discount_is_capped(): void
    {
        $percent = new Coupon(['type' => 'percent', 'value' => 50, 'max_discount' => 30]);
        $this->assertSame(30.0, $percent->discountFor(200));
        $fixed = new Coupon(['type' => 'fixed', 'value' => 80]);
        $this->assertSame(50.0, $fixed->discountFor(50)); // never more than the subtotal
    }
}
