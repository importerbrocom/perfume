<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A size of a product (e.g. 100 ml). Holds price and stock.
 * price = null means "price on request": shown, but not purchasable.
 */
class ProductVariant extends Model
{
    public const STATUSES = ['in_stock', 'out_of_stock'];

    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'decimal:2', 'sale_price' => 'decimal:2', 'track_stock' => 'boolean',
        'stock_quantity' => 'integer', 'is_active' => 'boolean', 'low_stock_threshold' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    public function onSale(): bool
    {
        return $this->price !== null && $this->sale_price !== null && (float) $this->sale_price < (float) $this->price;
    }

    /** Price a customer pays now. */
    public function currentPrice(): ?float
    {
        if ($this->price === null) {
            return null;
        }

        return $this->onSale() ? (float) $this->sale_price : (float) $this->price;
    }

    public function inStock(): bool
    {
        return $this->track_stock ? $this->stock_quantity > 0 : $this->stock_status === 'in_stock';
    }

    /** How many a customer may order now (null = no limit). */
    public function availableQuantity(): ?int
    {
        if (! $this->track_stock) {
            return $this->stock_status === 'in_stock' ? null : 0;
        }

        return max(0, $this->stock_quantity);
    }

    public function purchasable(): bool
    {
        return $this->is_active && $this->hasPrice() && $this->inStock();
    }

    public function lowStockThreshold(): int
    {
        return $this->low_stock_threshold ?? (int) Setting::get('low_stock_threshold', 3);
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->stock_quantity > 0 && $this->stock_quantity <= $this->lowStockThreshold();
    }
}
