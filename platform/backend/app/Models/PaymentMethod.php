<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A checkout payment option the admin can switch on/off (Cash on Delivery, Card / Apple Pay / Google Pay via Stripe...).
 * credentials: encrypted with APP_KEY in the database and never serialized (hidden).
 */
class PaymentMethod extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'fee' => 'decimal:2',
        'min_total' => 'decimal:2',
        'max_total' => 'decimal:2',
        'countries' => 'array',
        'options' => 'array',
        'credentials' => 'encrypted:array',
    ];

    public function credential(string $key): ?string
    {
        $c = $this->credentials ?? [];

        return isset($c[$key]) && $c[$key] !== '' ? (string) $c[$key] : null;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    /** Can this method be offered for an order of $total shipping to $country? */
    public function availableFor(float $total, string $country): bool
    {
        if (! $this->is_enabled) {
            return false;
        }
        if ($this->min_total !== null && $total < (float) $this->min_total) {
            return false;
        }
        if ($this->max_total !== null && $total > (float) $this->max_total) {
            return false;
        }
        if (! empty($this->countries) && ! in_array(strtoupper($country), array_map('strtoupper', $this->countries), true)) {
            return false;
        }

        return $this->isConfigured();
    }

    /** Has everything it needs to take payments (Stripe: both keys). */
    public function isConfigured(): bool
    {
        if ($this->provider === 'stripe') {
            return $this->credential('secret_key') !== null && (string) $this->option('publishable_key', '') !== '';
        }

        return true;
    }
}
