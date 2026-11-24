<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\Present;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    private function base(): Builder
    {
        return Product::query()->visible()
            ->with(['variants', 'images', 'brand'])
            ->withAvg(['reviews' => fn ($r) => $r->where('is_approved', true)], 'rating')
            ->withCount(['reviews' => fn ($r) => $r->where('is_approved', true)]);
    }

    /** GET /api/products?ids=1,2&brand=&category=&search=&sort=&flag=&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'brand' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|in:featured,latest,price_asc,price_desc,popular,name',
            'flag' => 'nullable|in:featured,new,best,sale,combo',
            'in_stock' => 'nullable|boolean',
            'ids' => ['nullable', 'string', 'max:2000', 'regex:/^\d+(,\d+)*$/'],
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:48',
        ]);
        $q = $this->base();
        if (! empty($v['ids'])) {
            $q->whereIn('products.id', array_slice(array_map('intval', explode(',', $v['ids'])), 0, 200));
        }
        if (! empty($v['brand'])) {
            $q->whereHas('brand', fn ($b) => $b->where('slug', $v['brand']));
        }
        if (! empty($v['category'])) {
            $q->whereHas('categories', fn ($c) => $c->where('slug', $v['category'])->where('is_active', true));
        }
        if (! empty($v['search'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']).'%';
            $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('tagline', 'like', $term)
                ->orWhere('notes_top', 'like', $term)->orWhere('notes_middle', 'like', $term)->orWhere('notes_base', 'like', $term)
                ->orWhere('sku', 'like', $term)->orWhere('inspired_by', 'like', $term)->orWhere('accords', 'like', $term));
        }
        match ($v['flag'] ?? null) {
            'featured' => $q->where('is_featured', true),
            'new' => $q->where('is_new_arrival', true),
            'best' => $q->where('is_best_seller', true),
            'combo' => $q->where('is_combo', true),
            'sale' => $q->whereHas('variants', fn ($x) => $x->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'price')),
            default => null,
        };
        if (! empty($v['in_stock'])) {
            $q->whereHas('variants', fn ($x) => $x->where('is_active', true)->where(fn ($s) => $s->where(fn ($t) => $t->where('track_stock', true)->where('stock_quantity', '>', 0))
                ->orWhere(fn ($t) => $t->where('track_stock', false)->where('stock_status', 'in_stock'))));
        }
        $priceSub = '(select min(coalesce(case when sale_price is not null and sale_price < price then sale_price else price end, 999999999)) from product_variants where product_variants.product_id = products.id and product_variants.is_active = 1)';
        match ($v['sort'] ?? 'featured') {
            'latest' => $q->orderByDesc('products.created_at'),
            'price_asc' => $q->orderByRaw("$priceSub asc"),
            'price_desc' => $q->orderByRaw("$priceSub desc"),
            'popular' => $q->orderByDesc('total_sales'),
            'name' => $q->orderBy('name'),
            default => $q->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('name'),
        };
        $page = $q->paginate($v['per_page'] ?? 12);

        return response()->json([
            'data' => collect($page->items())->map(fn ($p) => Present::card($p))->values(),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ])->header('Cache-Control', 'public, max-age=30');
    }

    public function show(string $slug): JsonResponse
    {
        $p = $this->base()->with(['categories', 'related' => fn ($r) => $r->visible()->with(['variants', 'images', 'brand'])])
            ->where('slug', $slug)->firstOrFail();
        $reviews = $p->reviews()->where('is_approved', true)->latest()->limit(20)->get(['id', 'author_name', 'rating', 'body', 'created_at']);
        $related = $p->related;
        if ($related->isEmpty()) {
            $catIds = $p->categories->pluck('id');
            $related = $this->base()->whereKeyNot($p->id)
                ->when($catIds->isNotEmpty(), fn ($q) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $catIds)))
                ->when($p->brand_id, fn ($q) => $q->orderByRaw('case when brand_id = ? then 0 else 1 end', [$p->brand_id]))
                ->limit(4)->get();
        }

        return response()->json([
            'product' => Present::detail($p),
            'reviews' => $reviews,
            'related' => $related->map(fn ($r) => Present::card($r))->values(),
            'bundle' => $p->is_combo ? $p->related->map(fn ($r) => Present::card($r))->values() : [],
        ])->header('Cache-Control', 'public, max-age=30');
    }

    /** Customers can leave a review; it is published after an admin approves it. */
    public function review(Request $request, string $slug): JsonResponse
    {
        $p = Product::query()->visible()->where('slug', $slug)->firstOrFail();
        $v = $request->validate([
            'name' => 'required|string|max:80',
            'email' => 'required|email|max:150',
            'rating' => 'required|integer|min:1|max:5',
            'body' => 'required|string|min:3|max:2000',
            'website' => 'nullable|max:0', // honeypot
        ]);
        ProductReview::create([
            'product_id' => $p->id, 'author_name' => strip_tags($v['name']), 'author_email' => $v['email'],
            'rating' => $v['rating'], 'body' => strip_tags($v['body']), 'is_approved' => false, 'source' => 'customer',
        ]);

        return response()->json(['message' => 'Thank you! Your review will appear after it is approved.'], 201);
    }
}
