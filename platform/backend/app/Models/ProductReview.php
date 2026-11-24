<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_approved' => 'boolean', 'rating' => 'integer'];

    protected $hidden = ['author_email'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
