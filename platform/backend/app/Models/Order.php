<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    public const PAYMENT_STATUSES = ['pending', 'paid', 'failed', 'refunded', 'cod_pending'];

    /** Customer-facing progress (cancelled / returned are shown separately). */
    public const TIMELINE = ['pending', 'confirmed', 'processing', 'shipped', 'delivered'];

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'ip'];

    protected $casts = [
        'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'shipping_total' => 'decimal:2',
        'payment_fee' => 'decimal:2', 'total' => 'decimal:2',
        'stock_reserved_at' => 'datetime', 'stock_released_at' => 'datetime', 'paid_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function holdsStock(): bool
    {
        return $this->stock_reserved_at !== null && $this->stock_released_at === null;
    }
}
