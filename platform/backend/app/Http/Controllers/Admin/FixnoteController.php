<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Fixnote;
use App\Services\ImageStore;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Admin > Fixnote page: page texts and images, prices for all sizes, UAE delivery, India / Saudi prices. */
class FixnoteController extends Controller
{
    public function __construct(private Fixnote $fixnote, private ImageStore $images) {}

    public function show(): JsonResponse
    {
        $brand = $this->fixnote->brand();
        $products = $brand ? Product::query()->where('brand_id', $brand->id) : null;

        return response()->json([
            'page' => $this->fixnote->page(),
            'defaults' => Fixnote::PAGE_DEFAULTS,
            'images' => $this->fixnote->images(),
            'prices' => $this->fixnote->prices(),
            'delivery' => $this->fixnote->delivery(),
            'regions' => $this->fixnote->regions(),
            'sizes' => Fixnote::SIZES,
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name, 'is_active' => $brand->is_active] : null,
            'counts' => [
                'products' => $products ? (clone $products)->count() : 0,
                'visible' => $products ? (clone $products)->where('is_active', true)->count() : 0,
                'orders' => $brand ? Order::query()->whereHas('items.product', fn ($p) => $p->withTrashed()->where('brand_id', $brand->id))->count() : 0,
            ],
        ]);
    }

    /** PUT /api/admin/fixnote: page texts, UAE delivery rule and the India / Saudi WhatsApp price lists. */
    public function update(Request $request): JsonResponse
    {
        $sizes = Fixnote::SIZES;
        $rules = Fixnote::pageRules() + [
            'delivery' => 'required|array',
            'delivery.enabled' => 'boolean',
            'delivery.countries' => 'array', 'delivery.countries.*' => 'string|size:2|alpha',
            'delivery.free_from_pieces' => 'nullable|integer|min:1|max:1000',
            'regions' => 'required|array',
        ];
        foreach ($sizes as $s) {
            $rules["delivery.fees.$s"] = 'nullable|numeric|min:0|max:100000';
        }
        foreach (array_keys(Fixnote::WHATSAPP_REGIONS) as $r) {
            $rules["regions.$r.currency"] = 'required|string|size:3|alpha';
            foreach ($sizes as $s) {
                $rules["regions.$r.prices.$s"] = 'nullable|numeric|min:0|max:10000000';
            }
        }
        $v = $request->validate($rules);

        $fees = [];
        foreach ($sizes as $s) {
            $fees[$s] = (float) ($v['delivery']['fees'][$s] ?? 0);
        }
        $regions = [];
        foreach (array_keys(Fixnote::WHATSAPP_REGIONS) as $r) {
            $regions[$r] = ['currency' => strtoupper($v['regions'][$r]['currency']), 'prices' => []];
            foreach ($sizes as $s) {
                $p = $v['regions'][$r]['prices'][$s] ?? null;
                $regions[$r]['prices'][$s] = $p === null || $p === '' ? null : (float) $p;
            }
        }
        Setting::put([
            'fixnote_page' => $this->fixnote->cleanPage($v['page']),
            'fixnote_delivery' => [
                'enabled' => (bool) ($v['delivery']['enabled'] ?? true),
                'countries' => array_values(array_unique(array_map('strtoupper', $v['delivery']['countries'] ?? []))),
                'fees' => $fees,
                'free_from_pieces' => $v['delivery']['free_from_pieces'] ?? null,
            ],
            'fixnote_regions' => $regions,
        ]);

        return $this->show();
    }

    /** PUT /api/admin/fixnote/prices: one price per size for every Fixnote perfume. */
    public function prices(Request $request): JsonResponse
    {
        $rules = ['apply_to_all' => 'boolean'];
        foreach (Fixnote::SIZES as $s) {
            $rules["prices.$s.price"] = 'nullable|numeric|min:0|max:9999999';
            $rules["prices.$s.sale_price"] = 'nullable|numeric|min:0|max:9999999';
        }
        $v = $request->validate($rules);
        $errors = [];
        foreach (Fixnote::SIZES as $s) {
            $price = $v['prices'][$s]['price'] ?? null;
            $sale = $v['prices'][$s]['sale_price'] ?? null;
            if ($sale !== null && $sale !== '' && ($price === null || $price === '' || (float) $sale >= (float) $price)) {
                $errors["prices.$s.sale_price"] = 'Sale price must be lower than the price.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $updated = DB::transaction(fn () => $this->fixnote->applyPrices($v['prices'] ?? [], (bool) ($v['apply_to_all'] ?? true)));

        return response()->json(['updated' => $updated] + json_decode($this->show()->getContent(), true));
    }

    /** POST /api/admin/fixnote/images (multipart): page logo, shop logo, hero picture, bottle picture. */
    public function images(Request $request): JsonResponse
    {
        $rules = [];
        foreach (Fixnote::IMAGES as $k) {
            $rules[$k] = 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192';
            $rules["reset_$k"] = 'nullable|boolean';
        }
        $request->validate($rules);
        $put = [];
        foreach (Fixnote::IMAGES as $k) {
            $key = "fixnote_{$k}_path";
            if ($request->hasFile($k)) {
                $this->images->delete(Setting::get($key));
                $put[$key] = $this->images->store($request->file($k), 'branding')['path'];
            } elseif ($request->boolean("reset_$k")) {
                $this->images->delete(Setting::get($key));
                $put[$key] = null;
            }
        }
        if ($put) {
            Setting::put($put);
        }

        return $this->show();
    }

    /** POST /api/admin/fixnote/products: a new perfume with the three sizes at the Fixnote prices. */
    public function storeProduct(Request $request): JsonResponse
    {
        $v = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'name' => 'required|string|max:200',
            'inspired_by' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);
        if (! $this->fixnote->brand()) {
            throw ValidationException::withMessages(['code' => 'The Fixnote brand is missing (Catalogue > Brands).']);
        }
        if (Product::withTrashed()->whereRaw('UPPER(sku) = ?', [mb_strtoupper($v['code'])])->exists()) {
            throw ValidationException::withMessages(['code' => 'A product with this code already exists (check Deleted too).']);
        }
        $p = DB::transaction(fn () => $this->fixnote->create($v));

        return response()->json(Present::adminProduct($p->load(['brand', 'categories', 'related', 'images', 'variants'])), 201);
    }
}
