<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\HomeSection;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class StoreController extends Controller
{
    /** Everything the header, footer and checkout need: public settings only. */
    public function store(): JsonResponse
    {
        $s = array_intersect_key(Setting::allValues(), array_flip(Setting::PUBLIC_KEYS));
        $s['logo_url'] = Present::url($s['logo_path'] ?? null);
        unset($s['logo_path']);
        foreach (['return_policy', 'privacy_policy', 'terms', 'about_us'] as $k) {
            $s['has_'.$k] = ! empty($s[$k]);
            unset($s[$k]);
        }

        return response()->json([
            'settings' => $s,
            'announcements' => Announcement::query()->live()->get(['id', 'text', 'link_url']),
            'brands' => Brand::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug', 'tagline']),
            'categories' => Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug', 'kind', 'parent_id']),
            'payment_methods' => PaymentMethod::query()->where('is_enabled', true)->orderBy('sort_order')->get()
                ->filter(fn ($m) => $m->isConfigured())
                ->map(fn ($m) => ['code' => $m->code, 'title' => $m->title, 'description' => $m->description, 'fee' => (float) $m->fee])->values(),
        ])->header('Cache-Control', 'public, max-age=60');
    }

    public function home(): JsonResponse
    {
        $sections = HomeSection::query()->where('is_active', true)->orderBy('sort_order')->get()->map(function (HomeSection $s) {
            $q = Product::query()->visible()->with(['variants', 'images', 'brand'])
                ->withAvg(['reviews' => fn ($r) => $r->where('is_approved', true)], 'rating')
                ->withCount(['reviews' => fn ($r) => $r->where('is_approved', true)]);
            switch ($s->source) {
                case 'featured': $q->where('is_featured', true)->orderBy('sort_order');
                    break;
                case 'best_sellers': $q->where(fn ($w) => $w->where('is_best_seller', true)->orWhere('total_sales', '>', 0))->orderByDesc('is_best_seller')->orderByDesc('total_sales');
                    break;
                case 'new_arrivals': $q->where('is_new_arrival', true)->orderByDesc('created_at');
                    break;
                case 'on_sale': $q->whereHas('variants', fn ($v) => $v->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'price'));
                    break;
                case 'category': $q->whereHas('categories', fn ($c) => $c->whereKey($s->source_id));
                    break;
                case 'brand': $q->where('brand_id', $s->source_id);
                    break;
                case 'manual': $ids = array_map('intval', (array) $s->product_ids);
                    $q->whereIn('id', $ids ?: [0]);
                    break;
            }
            $products = $q->limit(max(1, min(24, $s->limit)))->get();
            if ($s->source === 'manual') {
                $order = array_flip(array_map('intval', (array) $s->product_ids));
                $products = $products->sortBy(fn ($p) => $order[$p->id] ?? 999)->values();
            }

            return ['id' => $s->id, 'title' => $s->title, 'subtitle' => $s->subtitle, 'source' => $s->source,
                'products' => $products->map(fn ($p) => Present::card($p))->values()];
        })->filter(fn ($s) => count($s['products']) > 0)->values();

        return response()->json([
            'banners' => Banner::query()->live()->where('placement', 'home')->get(),
            'promos' => Banner::query()->live()->where('placement', 'promo')->get(),
            'sections' => $sections,
        ])->header('Cache-Control', 'public, max-age=60');
    }

    public function page(string $key): JsonResponse
    {
        $map = ['about' => 'about_us', 'returns' => 'return_policy', 'privacy' => 'privacy_policy', 'terms' => 'terms', 'shipping' => 'shipping_info'];
        abort_unless(isset($map[$key]), 404);
        $body = Setting::get($map[$key]);
        abort_if(empty($body), 404);
        $titles = ['about' => 'About Us', 'returns' => 'Returns & Refunds', 'privacy' => 'Privacy Policy', 'terms' => 'Terms & Conditions', 'shipping' => 'Shipping & Delivery'];

        return response()->json(['title' => $titles[$key], 'body' => $body]);
    }

    /** Apple Pay domain verification file (content pasted by the admin from Stripe). */
    public function applePay(): Response
    {
        $content = Setting::get('apple_pay_domain_association');
        abort_if(empty($content), 404);

        return response((string) $content, 200, ['Content-Type' => 'text/plain']);
    }
}
