<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'total_sales'];

    protected $casts = [
        'is_active' => 'boolean', 'is_featured' => 'boolean', 'is_new_arrival' => 'boolean',
        'is_best_seller' => 'boolean', 'is_combo' => 'boolean', 'total_sales' => 'integer',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function related(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_related', 'product_id', 'related_id')
            ->withPivot('sort_order')->orderByPivot('sort_order');
    }

    /** Products customers can see: active, with an active brand (or none). */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('products.is_active', true)
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhereHas('brand', fn ($b) => $b->where('is_active', true)));
    }
}
