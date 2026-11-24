<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Stock & prices for every size on one screen, manual adjustments and the stock history. */
class InventoryController extends Controller
{
    public function __construct(private StockService $stock) {}

    private function row(ProductVariant $v): array
    {
        return [
            'id' => $v->id,
            'product_id' => $v->product_id,
            'product_name' => $v->product?->name,
            'product_active' => (bool) $v->product?->is_active,
            'image' => $v->product?->images->first()?->thumb_url,
            'name' => $v->name,
            'sku' => $v->sku,
            'price' => $v->price !== null ? (float) $v->price : null,
            'sale_price' => $v->sale_price !== null ? (float) $v->sale_price : null,
            'track_stock' => $v->track_stock,
            'stock_quantity' => $v->stock_quantity,
            'stock_status' => $v->stock_status,
            'low_stock_threshold' => $v->low_stock_threshold,
            'effective_threshold' => $v->lowStockThreshold(),
            'is_active' => $v->is_active,
            'in_stock' => $v->inStock(),
            'low_stock' => $v->isLowStock(),
        ];
    }

    /** GET /api/admin/inventory?filter=low|out|unpriced&search= */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['filter' => 'nullable|in:low,out,unpriced,all', 'search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $threshold = (int) Setting::get('low_stock_threshold', 3);
        $q = ProductVariant::query()->with(['product.images'])
            ->whereHas('product')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select('product_variants.*')
            ->orderBy('products.name')->orderBy('product_variants.sort_order');
        if (! empty($v['search'])) {
            $t = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']).'%';
            $q->where(fn ($w) => $w->where('products.name', 'like', $t)->orWhere('product_variants.sku', 'like', $t)->orWhere('products.sku', 'like', $t));
        }
        match ($v['filter'] ?? 'all') {
            'low' => $q->where('product_variants.track_stock', true)->where('product_variants.stock_quantity', '>', 0)
                ->whereRaw('product_variants.stock_quantity <= coalesce(product_variants.low_stock_threshold, ?)', [$threshold]),
            'out' => $q->where(fn ($s) => $s->where(fn ($t) => $t->where('product_variants.track_stock', true)->where('product_variants.stock_quantity', '<=', 0))
                ->orWhere(fn ($t) => $t->where('product_variants.track_stock', false)->where('product_variants.stock_status', 'out_of_stock'))),
            'unpriced' => $q->whereNull('product_variants.price'),
            default => null,
        };
        $page = $q->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => $this->row($x))->values(),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * PUT /api/admin/inventory: save many rows at once (price, sale price, stock, status).
     * Body: { rows: [{ id, price, sale_price, stock_quantity, track_stock, stock_status, is_active }] }
     */
    public function bulk(Request $request): JsonResponse
    {
        $v = $request->validate([
            'rows' => 'required|array|min:1|max:300',
            'rows.*.id' => 'required|integer|exists:product_variants,id',
            'rows.*.price' => 'nullable|numeric|min:0|max:9999999',
            'rows.*.sale_price' => 'nullable|numeric|min:0|max:9999999',
            'rows.*.stock_quantity' => 'nullable|integer|min:0|max:1000000',
            'rows.*.track_stock' => 'nullable|boolean',
            'rows.*.stock_status' => 'nullable|in:in_stock,out_of_stock',
            'rows.*.low_stock_threshold' => 'nullable|integer|min:0|max:100000',
            'rows.*.is_active' => 'nullable|boolean',
        ]);
        $errors = [];
        foreach ($v['rows'] as $i => $r) {
            $price = array_key_exists('price', $r) ? $r['price'] : ProductVariant::find($r['id'])->price;
            if (isset($r['sale_price']) && $r['sale_price'] !== null && ($price === null || (float) $r['sale_price'] >= (float) $price)) {
                $errors["rows.$i.sale_price"] = 'Sale price must be lower than the regular price.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $updated = 0;
        DB::transaction(function () use ($v, $request, &$updated) {
            foreach ($v['rows'] as $r) {
                $var = ProductVariant::query()->whereKey($r['id'])->lockForUpdate()->first();
                $before = $var->stock_quantity;
                foreach (['price', 'sale_price', 'track_stock', 'stock_status', 'low_stock_threshold', 'is_active'] as $f) {
                    if (array_key_exists($f, $r)) {
                        $var->{$f} = $r[$f];
                    }
                }
                if (array_key_exists('stock_quantity', $r) && $r['stock_quantity'] !== null) {
                    $var->stock_quantity = (int) $r['stock_quantity'];
                    $var->track_stock = true;
                }
                if ($var->isDirty()) {
                    $var->save();
                    $updated++;
                    if ($var->stock_quantity !== $before) {
                        $this->stock->log($var, $var->stock_quantity - $before, 'manual', null, $request->user()->id, 'Stock & Prices screen');
                    }
                }
            }
        });

        return response()->json(['updated' => $updated]);
    }

    /** POST /api/admin/inventory/{variant}/adjust { set_to | change, note } */
    public function adjust(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'set_to' => 'nullable|integer|min:0|max:1000000|required_without:change',
            'change' => 'nullable|integer|min:-1000000|max:1000000|required_without:set_to',
            'note' => 'nullable|string|max:300',
        ]);
        $variant = $this->stock->adjust(ProductVariant::findOrFail($id), $v['set_to'] ?? null, $v['change'] ?? null, $v['note'] ?? null, $request->user()->id);

        return response()->json($this->row($variant->load('product.images')));
    }

    public function movements(Request $request): JsonResponse
    {
        $v = $request->validate(['variant_id' => 'nullable|integer', 'page' => 'nullable|integer|min:1']);
        $page = StockMovement::query()->with(['variant.product', 'user:id,name', 'order:id,number'])
            ->when($v['variant_id'] ?? null, fn ($q, $id) => $q->where('variant_id', $id))
            ->latest('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn ($m) => [
                'id' => $m->id, 'at' => $m->created_at?->toIso8601String(), 'product' => $m->variant?->product?->name,
                'variant' => $m->variant?->name, 'change' => $m->change, 'quantity_after' => $m->quantity_after,
                'reason' => $m->reason, 'note' => $m->note, 'user' => $m->user?->name, 'order' => $m->order?->number,
            ]),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
