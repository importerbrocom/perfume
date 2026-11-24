<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductReview;
use App\Services\ImageStore;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Brands, categories / collections, customers, reviews and coupons. */
class CatalogController extends Controller
{
    public function __construct(private ImageStore $images) {}

    private function slug(string $model, string $source, ?int $ignore): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $i = 2;
        while ($model::query()->where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    // ---------- Brands ----------
    public function brands(): JsonResponse
    {
        return response()->json(Brand::query()->withCount('products')->orderBy('sort_order')->get()
            ->map(fn ($b) => $b->toArray() + ['logo_url' => Present::url($b->logo_path)]));
    }

    public function saveBrand(Request $request, ?int $id = null): JsonResponse
    {
        $b = $id ? Brand::findOrFail($id) : new Brand;
        $v = $request->validate([
            'name' => 'required|string|max:100', 'slug' => 'nullable|alpha_dash|max:100', 'tagline' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:5000', 'is_active' => 'boolean', 'sort_order' => 'nullable|integer|min:0',
            'logo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
        ]);
        $b->fill(collect($v)->except(['slug', 'logo'])->all());
        $b->slug = $this->slug(Brand::class, ($v['slug'] ?? null) ?: ($b->exists ? $b->slug : $v['name']), $b->id);
        if ($request->hasFile('logo')) {
            $this->images->delete($b->logo_path);
            $b->logo_path = $this->images->store($request->file('logo'), 'brands')['path'];
        }
        $b->save();

        return response()->json($b, $id ? 200 : 201);
    }

    public function deleteBrand(int $id): JsonResponse
    {
        $b = Brand::withCount('products')->findOrFail($id);
        abort_if($b->products_count > 0, 422, 'Move or delete this brand\'s products first.');
        $b->delete();

        return response()->json(['ok' => true]);
    }

    // ---------- Categories & collections ----------
    public function categories(): JsonResponse
    {
        return response()->json(Category::query()->withCount('products')->orderBy('kind')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($c) => $c->toArray() + ['image_url' => Present::url($c->image_path)]));
    }

    public function saveCategory(Request $request, ?int $id = null): JsonResponse
    {
        $c = $id ? Category::findOrFail($id) : new Category;
        $v = $request->validate([
            'name' => 'required|string|max:100', 'slug' => 'nullable|alpha_dash|max:100',
            'kind' => ['required', Rule::in(Category::KINDS)], 'parent_id' => ['nullable', 'integer', 'exists:categories,id', Rule::notIn([$id])],
            'description' => 'nullable|string|max:5000', 'is_active' => 'boolean', 'sort_order' => 'nullable|integer|min:0',
            'image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
        ]);
        $c->fill(collect($v)->except(['slug', 'image'])->all());
        $c->slug = $this->slug(Category::class, ($v['slug'] ?? null) ?: ($c->exists ? $c->slug : $v['name']), $c->id);
        if ($request->hasFile('image')) {
            $this->images->delete($c->image_path);
            $c->image_path = $this->images->store($request->file('image'), 'categories')['path'];
        }
        $c->save();

        return response()->json($c, $id ? 200 : 201);
    }

    public function deleteCategory(int $id): JsonResponse
    {
        $c = Category::findOrFail($id);
        Category::query()->where('parent_id', $c->id)->update(['parent_id' => null]);
        $this->images->delete($c->image_path);
        $c->delete(); // products stay, only the link is removed

        return response()->json(['ok' => true]);
    }

    // ---------- Customers ----------
    public function customers(Request $request): JsonResponse
    {
        $v = $request->validate(['search' => 'nullable|string|max:100', 'sort' => 'nullable|in:latest,orders,spent,name', 'page' => 'nullable|integer|min:1']);
        $q = Customer::query();
        if (! empty($v['search'])) {
            $t = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']).'%';
            $q->where(fn ($w) => $w->where('name', 'like', $t)->orWhere('email', 'like', $t)->orWhere('phone', 'like', $t));
        }
        match ($v['sort'] ?? 'latest') {
            'orders' => $q->orderByDesc('orders_count'), 'spent' => $q->orderByDesc('total_spent'),
            'name' => $q->orderBy('name'), default => $q->orderByDesc('last_order_at'),
        };
        $page = $q->paginate(25);

        return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function customer(int $id): JsonResponse
    {
        $c = Customer::findOrFail($id);

        return response()->json($c->toArray() + [
            'orders' => Order::query()->where('customer_id', $c->id)->latest('id')->get(['id', 'number', 'status', 'payment_status', 'total', 'currency', 'created_at']),
        ]);
    }

    public function updateCustomer(Request $request, int $id): JsonResponse
    {
        $c = Customer::findOrFail($id);
        $c->fill($request->validate(['name' => 'sometimes|string|max:120', 'phone' => 'nullable|string|max:40', 'admin_note' => 'nullable|string|max:2000', 'is_blocked' => 'sometimes|boolean']))->save();

        return $this->customer($c->id);
    }

    // ---------- Reviews ----------
    public function reviews(Request $request): JsonResponse
    {
        $v = $request->validate(['status' => 'nullable|in:pending,approved', 'page' => 'nullable|integer|min:1']);
        $page = ProductReview::query()->with('product:id,name,slug')
            ->when(($v['status'] ?? null) === 'pending', fn ($q) => $q->where('is_approved', false))
            ->when(($v['status'] ?? null) === 'approved', fn ($q) => $q->where('is_approved', true))
            ->latest('id')->paginate(25);

        return response()->json(['data' => collect($page->items())->map(fn ($r) => $r->makeVisible('author_email')), 'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function updateReview(Request $request, int $id): JsonResponse
    {
        $r = ProductReview::findOrFail($id);
        $r->fill($request->validate(['is_approved' => 'required|boolean']))->save();

        return response()->json($r);
    }

    public function deleteReview(int $id): JsonResponse
    {
        ProductReview::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    // ---------- Coupons ----------
    public function coupons(): JsonResponse
    {
        return response()->json(Coupon::query()->latest('id')->get());
    }

    public function saveCoupon(Request $request, ?int $id = null): JsonResponse
    {
        $c = $id ? Coupon::findOrFail($id) : new Coupon;
        $v = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($id)],
            'type' => 'required|in:percent,fixed',
            'value' => ['required', 'numeric', 'min:0.01', $request->input('type') === 'percent' ? 'max:100' : 'max:9999999'],
            'min_subtotal' => 'nullable|numeric|min:0', 'max_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'is_active' => 'boolean',
        ]);
        $v['code'] = strtoupper($v['code']);
        $c->fill($v)->save();

        return response()->json($c, $id ? 200 : 201);
    }

    public function deleteCoupon(int $id): JsonResponse
    {
        Coupon::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}
