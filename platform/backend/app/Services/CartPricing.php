<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Setting;

/**
 * Server-side cart calculation. The browser only sends variant ids + quantities;
 * every price, discount, shipping charge and fee is computed here.
 */
class CartPricing
{
    /**
     * @param  array<int,array{variant_id:int,quantity:int}>  $items
     * @param  array<int,ProductVariant>|null  $lockedVariants  use these (already locked) instead of loading
     * @param  string|null  $region  INDIA / SAUDI: a Fixnote bag priced in that region's currency (see Fixnote::PRICE_REGIONS)
     */
    public function quote(array $items, ?string $couponCode, ?string $country, ?string $paymentCode, ?array $lockedVariants = null, ?string $region = null): array
    {
        $region = Fixnote::priceRegion($region);
        $regional = $region ? app(Fixnote::class)->regions()[$region] : null;
        $quantities = [];
        foreach ($items as $it) {
            $id = (int) ($it['variant_id'] ?? 0);
            $q = (int) ($it['quantity'] ?? 0);
            if ($id > 0 && $q > 0) {
                $quantities[$id] = min(99, ($quantities[$id] ?? 0) + $q);
            }
        }

        $variants = $lockedVariants ?? ProductVariant::query()->whereIn('id', array_keys($quantities))
            ->with(['product.images', 'product.brand'])->get()->keyBy('id')->all();

        $country = $country ? strtoupper($country) : null;
        $fixnoteBlocked = $regional ? null : Fixnote::blockedFor($country);
        $fixnotePieces = []; // size => quantity, Fixnote items that can be ordered
        $otherItems = false;

        $lines = [];
        $issues = [];
        $subtotal = 0.0;
        foreach ($quantities as $id => $qty) {
            $v = $variants[$id] ?? null;
            if (! $v || ! $v->product || ! $v->product->is_active || ! $v->is_active) {
                $issues[] = ['variant_id' => $id, 'message' => 'This item is no longer available.'];

                continue;
            }
            $isFixnote = Fixnote::isFixnote($v);
            $price = $v->currentPrice();
            $regular = $v->price !== null ? (float) $v->price : null;
            if ($regional) {
                $price = $isFixnote ? ($regional['prices'][Fixnote::sizeKey($v->name)] ?? null) : null;
                $regular = $price;
            }
            $available = $v->availableQuantity();
            $problem = null;
            if ($regional && ! $isFixnote) {
                $problem = "Only Fixnote perfumes can be ordered with {$regional['label']} prices. Remove this item to continue.";
            } elseif ($regional && $price === null) {
                $problem = "Not available for {$regional['label']}.";
            } elseif ($price === null) {
                $problem = 'No price yet: please contact us to order.';
            } elseif (! $v->inStock()) {
                $problem = 'Out of stock.';
            } elseif ($available !== null && $qty > $available) {
                $problem = "Only $available left in stock.";
            }
            if (! $problem && $isFixnote && $fixnoteBlocked) {
                $problem = $fixnoteBlocked;
            }
            $line = round(($price ?? 0) * $qty, 2);
            if (! $problem) {
                $subtotal += $line;
                if ($isFixnote) {
                    $size = Fixnote::sizeKey($v->name) ?? $v->name;
                    $fixnotePieces[$size] = ($fixnotePieces[$size] ?? 0) + $qty;
                } else {
                    $otherItems = true;
                }
            } else {
                $issues[] = ['variant_id' => $id, 'message' => $problem];
            }
            $img = $v->product->images->first();
            $lines[] = [
                'variant_id' => $v->id,
                'product_id' => $v->product_id,
                'product_name' => $v->product->name,
                'product_slug' => $v->product->slug,
                'variant_name' => $v->name,
                'image' => $img?->thumb_url,
                'unit_price' => $price,
                'regular_price' => $regular,
                'quantity' => $qty,
                'available' => $available,
                'line_total' => $problem ? 0.0 : $line,
                'problem' => $problem,
            ];
        }
        $subtotal = round($subtotal, 2);

        // Coupon
        $discount = 0.0;
        $couponMessage = null;
        $coupon = null;
        if ($couponCode !== null && trim($couponCode) !== '' && $regional) {
            $couponMessage = "Coupons cannot be used with {$regional['label']} prices.";
        } elseif ($couponCode !== null && trim($couponCode) !== '') {
            $coupon = Coupon::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($couponCode))])->first();
            if (! $coupon) {
                $couponMessage = 'Coupon code not found.';
            } elseif ($reason = $coupon->unusableReason($subtotal)) {
                $couponMessage = $reason;
                $coupon = null;
            } else {
                $discount = $coupon->discountFor($subtotal);
            }
        }

        // Shipping
        $allowed = (array) Setting::get('shipping_countries', []);
        $shipsHere = ! $country || empty($allowed) || in_array($country, $allowed, true);
        $flat = (float) Setting::get('shipping_flat_rate', 0);
        $freeOver = Setting::get('free_shipping_over');
        $afterDiscount = max(0, $subtotal - $discount);
        $shipping = ($freeOver !== null && $freeOver !== '' && $afterDiscount >= (float) $freeOver) ? 0.0 : $flat;
        // Fixnote delivery rule (UAE: a charge by size, free from 5 pieces). With other products in the
        // cart the higher of the two charges applies, so one parcel is never charged twice.
        $fixnoteDelivery = $regional
            ? Fixnote::charge($fixnotePieces, $regional['delivery']['fees'], $regional['delivery']['free_from_pieces'])
            : app(Fixnote::class)->deliveryFor($fixnotePieces, $country);
        if ($regional) {
            $shipsHere = ! $country || $country === $regional['country'];
            $shipping = $fixnoteDelivery['fee'] ?? 0.0;
        } elseif ($fixnoteDelivery) {
            $shipping = $otherItems ? max($shipping, $fixnoteDelivery['fee']) : $fixnoteDelivery['fee'];
        }
        if ($subtotal <= 0) {
            $shipping = 0.0;
        }

        // Payment methods available for this order, and the chosen method's fee
        $methods = PaymentMethod::query()->where('is_enabled', true)->orderBy('sort_order')->get();
        $base = round($afterDiscount + $shipping, 2);
        if ($regional) {
            // No card (store currency only); fees and min / max totals are in the store currency, so they do not apply.
            $available = $methods->filter(fn ($m) => $m->provider !== 'stripe' && ($m->code !== 'cod' || $regional['cod']) && $m->isConfigured()
                && (empty($m->countries) || in_array($regional['country'], array_map('strtoupper', $m->countries), true)))->values();
        } else {
            $available = $methods->filter(fn ($m) => $m->code !== 'transfer' && $m->availableFor($base, $country ?? 'AE'))->values();
        }
        $chosen = $paymentCode ? $available->firstWhere('code', $paymentCode) : null;
        $fee = $chosen && ! $regional ? (float) $chosen->fee : 0.0;

        return [
            'currency' => $regional['currency'] ?? Setting::get('currency', 'AED'),
            'region' => $region,
            'lines' => $lines,
            'issues' => $issues,
            'quantities' => $quantities,
            'subtotal' => $subtotal,
            'coupon' => $coupon ? ['code' => $coupon->code, 'id' => $coupon->id] : null,
            'coupon_message' => $couponMessage,
            'discount' => $discount,
            'ships_to_country' => $shipsHere,
            'shipping' => round($shipping, 2),
            'fixnote_delivery' => $fixnoteDelivery,
            'payment_fee' => round($fee, 2),
            'total' => round($base + $fee, 2),
            'payment_methods' => $available->map(fn ($m) => [
                'code' => $m->code, 'title' => $m->title, 'description' => $m->description, 'fee' => $regional ? 0.0 : (float) $m->fee,
            ])->all(),
            'payment_method_valid' => $paymentCode === null || $chosen !== null,
        ];
    }
}
