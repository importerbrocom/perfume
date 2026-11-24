<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Storage;

/** Converts models into the JSON shapes the React shop and admin panel use. */
class Present
{
    public static function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    public static function variant(ProductVariant $v, bool $admin = false): array
    {
        $a = [
            'id' => $v->id,
            'name' => $v->name,
            'sku' => $v->sku,
            'price' => $v->price !== null ? (float) $v->price : null,
            'sale_price' => $v->onSale() ? (float) $v->sale_price : null,
            'current_price' => $v->currentPrice(),
            'in_stock' => $v->inStock(),
            'available' => $v->availableQuantity(),
            'purchasable' => $v->purchasable(),
            'low_stock' => $v->isLowStock(),
        ];
        if ($admin) {
            $a += [
                'raw_sale_price' => $v->sale_price !== null ? (float) $v->sale_price : null,
                'track_stock' => $v->track_stock,
                'stock_quantity' => $v->stock_quantity,
                'stock_status' => $v->stock_status,
                'low_stock_threshold' => $v->low_stock_threshold,
                'is_active' => $v->is_active,
                'sort_order' => $v->sort_order,
            ];
        }

        return $a;
    }

    /** Product card for lists. */
    public static function card(Product $p): array
    {
        $variants = $p->variants->where('is_active', true)->values();
        $priced = $variants->filter(fn ($v) => $v->hasPrice());
        $min = $priced->map(fn ($v) => $v->currentPrice())->min();
        $max = $priced->map(fn ($v) => $v->currentPrice())->max();
        $img = $p->images->first();
        $second = $p->images->get(1);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'tagline' => $p->tagline,
            'brand' => $p->brand ? ['name' => $p->brand->name, 'slug' => $p->brand->slug] : null,
            'image' => $img?->thumb_url,
            'image_large' => $img?->url,
            'image_hover' => $second?->thumb_url,
            'price_min' => $min,
            'price_max' => $max,
            'regular_min' => $priced->map(fn ($v) => (float) $v->price)->min(),
            'on_sale' => $variants->contains(fn ($v) => $v->onSale()),
            'priced' => $priced->isNotEmpty(),
            'in_stock' => $variants->contains(fn ($v) => $v->inStock()),
            'purchasable' => $variants->contains(fn ($v) => $v->purchasable()),
            'single_variant_id' => $variants->count() === 1 ? $variants->first()->id : null,
            'variant_count' => $variants->count(),
            'rating' => $p->reviews_avg_rating !== null ? round((float) $p->reviews_avg_rating, 1) : null,
            'reviews' => (int) ($p->reviews_count ?? 0),
            'is_featured' => $p->is_featured,
            'is_new_arrival' => $p->is_new_arrival,
            'is_best_seller' => $p->is_best_seller,
            'is_combo' => $p->is_combo,
        ];
    }

    public static function detail(Product $p): array
    {
        return self::card($p) + [
            'short_description' => $p->short_description,
            'description' => $p->description,
            'notes' => array_filter(['top' => $p->notes_top, 'middle' => $p->notes_middle, 'base' => $p->notes_base]),
            'accords' => $p->accords,
            'fragrance_family' => $p->fragrance_family,
            'perfume_type' => $p->perfume_type,
            'concentration' => $p->concentration,
            'gender' => $p->gender,
            'traits' => $p->traits,
            'longevity' => $p->longevity,
            'images' => $p->images->map(fn ($i) => ['url' => $i->url, 'thumb' => $i->thumb_url, 'alt' => $i->alt ?: $p->name])->values(),
            'variants' => $p->variants->where('is_active', true)->values()->map(fn ($v) => self::variant($v)),
            'categories' => $p->categories->where('is_active', true)->values()->map(fn ($c) => ['name' => $c->name, 'slug' => $c->slug, 'kind' => $c->kind]),
            'meta_title' => $p->meta_title ?: $p->name,
            'meta_description' => $p->meta_description ?: ($p->short_description ? mb_substr(strip_tags($p->short_description), 0, 160) : null),
        ];
    }

    public static function adminProduct(Product $p): array
    {
        return [
            'id' => $p->id,
            'brand_id' => $p->brand_id,
            'brand' => $p->brand?->name,
            'name' => $p->name,
            'slug' => $p->slug,
            'sku' => $p->sku,
            'tagline' => $p->tagline,
            'short_description' => $p->short_description,
            'description' => $p->description,
            'notes_top' => $p->notes_top,
            'notes_middle' => $p->notes_middle,
            'notes_base' => $p->notes_base,
            'accords' => $p->accords,
            'fragrance_family' => $p->fragrance_family,
            'perfume_type' => $p->perfume_type,
            'concentration' => $p->concentration,
            'gender' => $p->gender,
            'traits' => $p->traits,
            'longevity' => $p->longevity,
            'is_active' => $p->is_active,
            'is_featured' => $p->is_featured,
            'is_new_arrival' => $p->is_new_arrival,
            'is_best_seller' => $p->is_best_seller,
            'is_combo' => $p->is_combo,
            'sort_order' => $p->sort_order,
            'total_sales' => $p->total_sales,
            'meta_title' => $p->meta_title,
            'meta_description' => $p->meta_description,
            'category_ids' => $p->categories->pluck('id')->values(),
            'related_ids' => $p->related->pluck('id')->values(),
            'images' => $p->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url, 'thumb' => $i->thumb_url, 'alt' => $i->alt, 'sort_order' => $i->sort_order])->values(),
            'variants' => $p->variants->map(fn ($v) => self::variant($v, true))->values(),
            'stock_total' => $p->variants->where('track_stock', true)->sum('stock_quantity'),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    /** What a customer can see about an order (tracking / thank-you page). */
    public static function customerOrder(Order $o): array
    {
        $timeline = [];
        $reachedIdx = array_search($o->status, Order::TIMELINE, true);
        foreach (Order::TIMELINE as $i => $s) {
            $timeline[] = ['status' => $s, 'done' => $reachedIdx !== false && $i <= $reachedIdx];
        }

        return [
            'number' => $o->number,
            'status' => $o->status,
            'payment_status' => $o->payment_status,
            'payment_method' => $o->payment_method,
            'currency' => $o->currency,
            'subtotal' => (float) $o->subtotal,
            'discount' => (float) $o->discount_total,
            'shipping' => (float) $o->shipping_total,
            'payment_fee' => (float) $o->payment_fee,
            'total' => (float) $o->total,
            'placed_at' => $o->created_at?->toIso8601String(),
            'customer_name' => $o->customer_name,
            'ship_city' => $o->ship_city,
            'ship_country' => $o->ship_country,
            'carrier' => $o->carrier,
            'tracking_number' => $o->tracking_number,
            'tracking_url' => $o->tracking_url,
            'timeline' => $timeline,
            'ended' => in_array($o->status, ['cancelled', 'returned'], true) ? $o->status : null,
            'items' => $o->items->map(fn ($i) => [
                'name' => $i->product_name, 'variant' => $i->variant_name, 'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price, 'line_total' => (float) $i->line_total, 'image' => self::url($i->image_path),
            ])->values(),
            'history' => $o->events->where('is_customer_visible', true)->values()->map(fn ($e) => [
                'to' => $e->to_value, 'message' => $e->message, 'at' => $e->created_at?->toIso8601String(),
            ]),
        ];
    }
}
