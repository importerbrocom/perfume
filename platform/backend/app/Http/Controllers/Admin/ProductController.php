<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\ImageStore;
use App\Services\StockService;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(private ImageStore $images, private StockService $stock) {}

    private function load(Product $p): Product
    {
        return $p->load(['brand', 'categories', 'related', 'images', 'variants']);
    }

    /** GET /api/admin/products?ids=1,2&search=&brand_id=&category_id=&status=active|hidden|deleted&stock=low|out|in&sort=&page= */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'search' => 'nullable|string|max:100', 'brand_id' => 'nullable|integer', 'brand' => 'nullable|string|max:100', 'category_id' => 'nullable|integer',
            'status' => 'nullable|in:active,hidden,deleted,unpriced', 'stock' => 'nullable|in:low,out,in',
            'sort' => 'nullable|in:name,latest,stock,sales', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
            'ids' => ['nullable', 'string', 'max:2000', 'regex:/^\d+(,\d+)*$/'],
        ]);
        $q = Product::query()->with(['brand', 'categories', 'related', 'images', 'variants']);
        if (($v['status'] ?? null) === 'deleted') {
            $q->onlyTrashed();
        }
        if (! empty($v['ids'])) {
            $q->whereIn('products.id', array_slice(array_map('intval', explode(',', $v['ids'])), 0, 100));
        }
        if (! empty($v['search'])) {
            $t = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']).'%';
            $q->where(fn ($w) => $w->where('name', 'like', $t)->orWhere('sku', 'like', $t)->orWhere('inspired_by', 'like', $t)->orWhereHas('variants', fn ($x) => $x->where('sku', 'like', $t)));
        }
        $q->when($v['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id))
            ->when($v['brand'] ?? null, fn ($q, $slug) => $q->whereHas('brand', fn ($b) => $b->where('slug', $slug)))
            ->when($v['category_id'] ?? null, fn ($q, $id) => $q->whereHas('categories', fn ($c) => $c->whereKey($id)));
        match ($v['status'] ?? null) {
            'active' => $q->where('is_active', true),
            'hidden' => $q->where('is_active', false),
            'unpriced' => $q->whereHas('variants', fn ($x) => $x->whereNull('price')),
            default => null,
        };
        $threshold = (int) Setting::get('low_stock_threshold', 3);
        match ($v['stock'] ?? null) {
            'out' => $q->whereHas('variants', fn ($x) => $x->where('is_active', true)->where(fn ($s) => $s->where(fn ($t) => $t->where('track_stock', true)->where('stock_quantity', '<=', 0))->orWhere(fn ($t) => $t->where('track_stock', false)->where('stock_status', 'out_of_stock')))),
            'low' => $q->whereHas('variants', fn ($x) => $x->where('is_active', true)->where('track_stock', true)->where('stock_quantity', '>', 0)->whereRaw('stock_quantity <= coalesce(low_stock_threshold, ?)', [$threshold])),
            'in' => $q->whereHas('variants', fn ($x) => $x->where('is_active', true)->where(fn ($s) => $s->where(fn ($t) => $t->where('track_stock', true)->where('stock_quantity', '>', 0))->orWhere(fn ($t) => $t->where('track_stock', false)->where('stock_status', 'in_stock')))),
            default => null,
        };
        match ($v['sort'] ?? 'name') {
            'latest' => $q->orderByDesc('created_at'),
            'sales' => $q->orderByDesc('total_sales'),
            'stock' => $q->orderByRaw('(select coalesce(sum(stock_quantity),0) from product_variants where product_variants.product_id = products.id) asc'),
            default => $q->orderBy('sort_order')->orderBy('name'),
        };
        $page = $q->paginate($v['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn ($p) => Present::adminProduct($p))->values(),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Present::adminProduct($this->load(Product::withTrashed()->findOrFail($id))));
    }

    private function rules(?Product $p = null): array
    {
        return [
            'name' => 'required|string|max:200',
            'slug' => ['nullable', 'string', 'max:200', 'alpha_dash', Rule::unique('products', 'slug')->ignore($p?->id)],
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($p?->id)],
            'brand_id' => 'nullable|integer|exists:brands,id',
            'tagline' => 'nullable|string|max:200',
            'inspired_by' => 'nullable|string|max:100',
            'short_description' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:20000',
            'notes_top' => 'nullable|string|max:500', 'notes_middle' => 'nullable|string|max:500', 'notes_base' => 'nullable|string|max:500',
            'accords' => 'nullable|string|max:500',
            'fragrance_family' => 'nullable|string|max:100', 'perfume_type' => 'nullable|string|max:100',
            'concentration' => 'nullable|string|max:100', 'gender' => 'nullable|in:men,women,unisex',
            'traits' => 'nullable|string|max:200', 'longevity' => 'nullable|string|max:100',
            'is_active' => 'boolean', 'is_featured' => 'boolean', 'is_new_arrival' => 'boolean',
            'is_best_seller' => 'boolean', 'is_combo' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'meta_title' => 'nullable|string|max:200', 'meta_description' => 'nullable|string|max:500',
            'category_ids' => 'array', 'category_ids.*' => 'integer|exists:categories,id',
            'related_ids' => 'array|max:12', 'related_ids.*' => 'integer|exists:products,id',
            'variants' => 'required|array|min:1|max:20',
            'variants.*.id' => 'nullable|integer',
            'variants.*.name' => 'required|string|max:100',
            'variants.*.sku' => 'nullable|string|max:64',
            'variants.*.price' => 'nullable|numeric|min:0|max:9999999',
            'variants.*.sale_price' => 'nullable|numeric|min:0|max:9999999',
            'variants.*.track_stock' => 'boolean',
            'variants.*.stock_quantity' => 'nullable|integer|min:0|max:1000000',
            'variants.*.stock_status' => 'nullable|in:in_stock,out_of_stock',
            'variants.*.low_stock_threshold' => 'nullable|integer|min:0|max:100000',
            'variants.*.is_active' => 'boolean',
        ];
    }

    private function validateVariants(array $variants): void
    {
        $errors = [];
        $skus = [];
        foreach ($variants as $i => $v) {
            if (isset($v['sale_price']) && $v['sale_price'] !== null && $v['sale_price'] !== '') {
                if (! isset($v['price']) || $v['price'] === null || $v['price'] === '' || (float) $v['sale_price'] >= (float) $v['price']) {
                    $errors["variants.$i.sale_price"] = 'Sale price must be lower than the regular price.';
                }
            }
            if (! empty($v['sku'])) {
                if (isset($skus[$v['sku']])) {
                    $errors["variants.$i.sku"] = 'Two sizes cannot share a SKU.';
                }
                $skus[$v['sku']] = true;
                $taken = ProductVariant::query()->where('sku', $v['sku'])->when(! empty($v['id']), fn ($q) => $q->whereKeyNot($v['id']))->exists();
                if ($taken) {
                    $errors["variants.$i.sku"] = 'This SKU is already used by another size.';
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules());
        $this->validateVariants($v['variants']);
        $p = DB::transaction(fn () => $this->save(new Product, $v, $request->user()->id));

        return response()->json(Present::adminProduct($this->load($p)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $p = Product::findOrFail($id);
        $v = $request->validate($this->rules($p));
        $this->validateVariants($v['variants']);
        DB::transaction(fn () => $this->save($p, $v, $request->user()->id));

        return response()->json(Present::adminProduct($this->load($p->refresh())));
    }

    private function save(Product $p, array $v, int $userId): Product
    {
        $fields = collect($v)->except(['category_ids', 'related_ids', 'variants', 'slug'])->all();
        $p->fill($fields);
        // Keep the existing URL when the slug is not changed; new products get one from the name.
        $p->slug = $this->uniqueSlug(($v['slug'] ?? null) ?: ($p->exists ? $p->slug : $v['name']), $p->id);
        $p->save();
        if (array_key_exists('category_ids', $v)) {
            $p->categories()->sync($v['category_ids']);
        }
        if (array_key_exists('related_ids', $v)) {
            $sync = [];
            foreach (array_values(array_diff($v['related_ids'], [$p->id])) as $i => $rid) {
                $sync[$rid] = ['sort_order' => $i];
            }
            $p->related()->sync($sync);
        }

        // Variants: update existing, create new, deactivate removed ones that have orders, delete the rest.
        $keep = [];
        foreach ($v['variants'] as $i => $row) {
            $variant = ! empty($row['id']) ? $p->variants()->whereKey($row['id'])->first() : null;
            $variant ??= new ProductVariant(['product_id' => $p->id]);
            $isNew = ! $variant->exists;
            $before = $variant->stock_quantity ?? 0;
            $variant->fill([
                'name' => $row['name'],
                'sku' => $row['sku'] ?? null ?: null,
                'price' => isset($row['price']) && $row['price'] !== '' ? $row['price'] : null,
                'sale_price' => isset($row['sale_price']) && $row['sale_price'] !== '' ? $row['sale_price'] : null,
                'track_stock' => (bool) ($row['track_stock'] ?? true),
                'stock_status' => $row['stock_status'] ?? 'in_stock',
                'low_stock_threshold' => $row['low_stock_threshold'] ?? null,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => $i,
            ]);
            if (array_key_exists('stock_quantity', $row) && $row['stock_quantity'] !== null) {
                $variant->stock_quantity = (int) $row['stock_quantity'];
            }
            $variant->product_id = $p->id;
            $variant->save();
            if ($variant->track_stock && ($isNew ? $variant->stock_quantity !== 0 : $variant->stock_quantity !== $before)) {
                $this->stock->log($variant, $variant->stock_quantity - ($isNew ? 0 : $before), 'manual', null, $userId, 'Edited on product page');
            }
            $keep[] = $variant->id;
        }
        foreach ($p->variants()->whereNotIn('id', $keep)->get() as $old) {
            if (DB::table('order_items')->where('variant_id', $old->id)->exists()) {
                $old->update(['is_active' => false]);
            } else {
                $old->delete();
            }
        }

        return $p;
    }

    private function uniqueSlug(string $source, ?int $ignoreId): string
    {
        $base = Str::slug($source) ?: 'product';
        $slug = $base;
        $i = 2;
        while (Product::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Soft delete: hidden from the shop, kept for past orders. */
    public function destroy(int $id): JsonResponse
    {
        Product::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function restore(int $id): JsonResponse
    {
        $p = Product::onlyTrashed()->findOrFail($id);
        $p->restore();

        return response()->json(Present::adminProduct($this->load($p)));
    }

    public function duplicate(int $id): JsonResponse
    {
        $src = $this->load(Product::findOrFail($id));
        $p = DB::transaction(function () use ($src) {
            $copy = $src->replicate(['total_sales']);
            $copy->name = $src->name.' (copy)';
            $copy->slug = $this->uniqueSlug($copy->name, null);
            $copy->sku = null;
            $copy->is_active = false;
            $copy->save();
            $copy->categories()->sync($src->categories->pluck('id'));
            foreach ($src->variants as $v) {
                $copy->variants()->create(collect($v->getAttributes())->except(['id', 'product_id', 'sku', 'created_at', 'updated_at'])->merge(['stock_quantity' => 0])->all());
            }

            return $copy;
        });

        return response()->json(Present::adminProduct($this->load($p)), 201);
    }

    /** POST /api/admin/products/{id}/images (multipart: images[]) */
    public function uploadImages(Request $request, int $id): JsonResponse
    {
        $p = Product::findOrFail($id);
        $request->validate([
            'images' => 'required|array|min:1|max:10',
            'images.*' => 'required|file|mimes:jpg,jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|max:8192|dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000',
        ]);
        if ($p->images()->count() + count($request->file('images')) > 15) {
            throw ValidationException::withMessages(['images' => 'A product can have up to 15 images.']);
        }
        $next = (int) $p->images()->max('sort_order') + 1;
        foreach ($request->file('images') as $file) {
            try {
                $saved = $this->images->store($file, 'products');
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['images' => $e->getMessage()]);
            }
            $p->images()->create($saved + ['alt' => $p->name, 'sort_order' => $next++]);
        }
        $p->touch();

        return response()->json(Present::adminProduct($this->load($p)), 201);
    }

    /** PUT /api/admin/products/{id}/images: reorder (first = main image) and alt texts. */
    public function updateImages(Request $request, int $id): JsonResponse
    {
        $p = Product::findOrFail($id);
        $v = $request->validate(['images' => 'required|array', 'images.*.id' => 'required|integer', 'images.*.alt' => 'nullable|string|max:200']);
        foreach ($v['images'] as $i => $row) {
            $p->images()->whereKey($row['id'])->update(['sort_order' => $i, 'alt' => $row['alt'] ?? null]);
        }
        $p->touch();

        return response()->json(Present::adminProduct($this->load($p)));
    }

    public function deleteImage(int $id, int $imageId): JsonResponse
    {
        $img = ProductImage::query()->where('product_id', $id)->findOrFail($imageId);
        $used = DB::table('order_items')->where('image_path', $img->thumb_path ?? $img->path)->orWhere('image_path', $img->path)->exists();
        if (! $used) {
            $this->images->delete($img->path, $img->thumb_path);
        }
        $img->delete();
        Product::find($id)?->touch();

        return response()->json(['ok' => true]);
    }
}
