<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'value' => 'decimal:2', 'min_subtotal' => 'decimal:2', 'max_discount' => 'decimal:2',
        'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean',
    ];

    /** Returns null if the coupon can be used for this subtotal, otherwise the reason it cannot. */
    public function unusableReason(float $subtotal): ?string
    {
        $now = now();
        if (! $this->is_active || ($this->starts_at && $this->starts_at->isAfter($now)) || ($this->ends_at && $this->ends_at->isBefore($now))) {
            return 'This coupon is not active.';
        }
        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return 'This coupon has reached its usage limit.';
        }
        if ($this->min_subtotal !== null && $subtotal < (float) $this->min_subtotal) {
            return 'This coupon needs a minimum order of '.number_format((float) $this->min_subtotal, 2).'.';
        }

        return null;
    }

    public function discountFor(float $subtotal): float
    {
        $d = $this->type === 'percent' ? $subtotal * ((float) $this->value / 100) : (float) $this->value;
        if ($this->max_discount !== null) {
            $d = min($d, (float) $this->max_discount);
        }

        return round(max(0, min($d, $subtotal)), 2);
    }
}
