<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeSection extends Model
{
    public const SOURCES = ['featured', 'best_sellers', 'new_arrivals', 'on_sale', 'category', 'brand', 'manual'];

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'product_ids' => 'array', 'limit' => 'integer'];
}
