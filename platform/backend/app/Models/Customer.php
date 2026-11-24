<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['total_spent' => 'decimal:2', 'last_order_at' => 'datetime', 'is_blocked' => 'boolean'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
