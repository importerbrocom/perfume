<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Support\Present;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Fixnote catalogue page: perfumes with a code (F01...), an "inspired by" brand and three sizes.
 *
 * Regions on the page: UAE and Other GCC order online (cart + checkout, store currency).
 * India and Saudi keep their own prices and send the selection on WhatsApp, so checkout
 * refuses Fixnote items for those countries.
 */
class Fixnote
{
    public const BRAND = 'fixnote';

    public const SIZES = ['30 ML', '50 ML', '100 ML'];

    public const REGIONS = ['UAE', 'GCC', 'INDIA', 'SAUDI'];

    /** Regions ordered on WhatsApp, with the country checkout refuses for Fixnote items. */
    public const WHATSAPP_REGIONS = ['INDIA' => 'IN', 'SAUDI' => 'SA'];

    public const CONCENTRATION = 'Extrait Perfume';

    /** Texts and lists on the page (all editable in Admin > Fixnote page). */
    public const PAGE_DEFAULTS = [
        'hero_eyebrow' => 'A PERFUMARATHI PRODUCTION',
        'hero_title' => 'Find Your',
        'hero_highlight' => 'Signature Scent.',
        'hero_text' => 'Premium inspired fragrances made for performance, projection and everyday confidence.',
        'collections_title' => 'Explore Our Collections',
        'collections_text' => 'Discover your fragrance style',
        'collections' => [
            ['title' => 'Fresh Collection', 'text' => 'CLEAN • BRIGHT • DAILY'],
            ['title' => 'Oud Collection', 'text' => 'RICH • ARABIC • DEEP'],
            ['title' => 'Sweet & Bold', 'text' => 'WARM • SWEET • STRONG'],
            ['title' => 'Luxury Inspired', 'text' => 'PREMIUM • ELEGANT'],
            ['title' => 'Unisex Collection', 'text' => 'MODERN • VERSATILE'],
        ],
        'regions_title' => 'CHOOSE YOUR REGION',
        'region_cards' => [
            'UAE' => ['title' => '🇦🇪 UAE', 'text' => 'Shop UAE prices'],
            'INDIA' => ['title' => '🇮🇳 INDIA / KERALA', 'text' => 'Kerala & All India'],
            'SAUDI' => ['title' => '🇸🇦 SAUDI', 'text' => 'Shop Saudi prices'],
            'GCC' => ['title' => '🌍 OTHER GCC / COUNTRIES', 'text' => 'Qatar • Bahrain • Oman • UK • Ireland • More'],
        ],
        'benefits' => [
            ['title' => '6–12 HRS', 'text' => 'LASTING PERFORMANCE'],
            ['title' => 'HIGH', 'text' => 'PROJECTION'],
            ['title' => '30 • 50 • 100 ML', 'text' => 'CHOOSE YOUR SIZE'],
        ],
        'reviews_title' => 'Loved by fragrance customers',
        'reviews' => [
            ['text' => 'Very good lasting and premium smell. Easy to choose from the catalogue.'],
            ['text' => 'Projection is strong and the fragrance feels much more premium than the price.'],
            ['text' => 'Good collection, simple ordering and fast response on WhatsApp.'],
        ],
        'whatsapp_title' => 'Orders & Enquiries',
        'whatsapp_text' => 'Need help choosing a fragrance or placing an order?',
        'whatsapp_button' => 'WHATSAPP — ORDERS & ENQUIRIES',
        'whatsapp_message' => "Welcome to FIXNOT by Perfumarathi! 👋\nHow can we help you today? I would like to know more about your perfumes / place an order.",
        'whatsapp_number' => '971528209716',
        'home_foot' => 'FIXNOT • UAE • INDIA • SAUDI • GCC   |   A Perfumarathi Production',
        'shop_sub' => 'NOTES, REIMAGINED. • SELECT YOUR FRAGRANCES',
        'shop_parent' => 'A FRAGRANCE BRAND BY PERFUMARATHI',
        'product_type' => 'EXTRAIT PERFUME',
        'offers' => [
            'UAE' => [
                ['tag' => '30 ML • 4 PIECES', 'title' => '29 AED × 4 = 116 AED', 'text' => 'Delivery 14 AED • Total 130 AED', 'highlight' => false],
                ['tag' => '50 ML • 4 PIECES', 'title' => '39 AED × 4 = 156 AED', 'text' => 'Delivery 9 AED • Total 165 AED', 'highlight' => false],
                ['tag' => '100 ML • 4 PIECES', 'title' => '59 AED × 4 = 236 AED', 'text' => 'Delivery 4 AED • Total 240 AED', 'highlight' => false],
                ['tag' => 'SPECIAL DELIVERY OFFER', 'title' => '5+ PIECES = FREE DELIVERY', 'text' => 'Add one more piece to any 4-piece order and delivery is FREE.', 'highlight' => true],
            ],
            'GCC' => [
                ['tag' => 'QATAR', 'title' => 'DELIVERY CHARGE EXTRA', 'text' => 'Product prices shown in AED', 'highlight' => false],
                ['tag' => 'BAHRAIN', 'title' => 'DELIVERY CHARGE EXTRA', 'text' => 'Product prices shown in AED', 'highlight' => false],
                ['tag' => 'OMAN', 'title' => 'DELIVERY CHARGE EXTRA', 'text' => 'Product prices shown in AED', 'highlight' => false],
                ['tag' => 'UK', 'title' => 'DELIVERY CHARGE EXTRA', 'text' => 'Product prices shown in AED', 'highlight' => false],
                ['tag' => 'IRELAND', 'title' => 'DELIVERY CHARGE EXTRA', 'text' => 'Product prices shown in AED', 'highlight' => false],
                ['tag' => 'OTHER COUNTRIES', 'title' => 'DELIVERY CHARGE EXTRA', 'text' => 'Product prices shown in AED', 'highlight' => false],
            ],
            'SAUDI' => [
                ['tag' => 'SAUDI DELIVERY', 'title' => 'DELIVERY CHARGE EXTRA', 'text' => 'Delivery charge will be added separately to your order.', 'highlight' => false],
                ['tag' => 'PAYMENT OPTIONS', 'title' => 'NO CASH ON DELIVERY', 'text' => 'Google Pay • Account Transfer • UPI / QR Payment', 'highlight' => true],
            ],
            'INDIA' => [
                ['tag' => '30 ML • 4 PIECES', 'title' => '₹1,199 × 4 = ₹4,796', 'text' => '+ ₹14 Delivery • TOTAL ₹4,810', 'highlight' => false],
                ['tag' => '50 ML • 4 PIECES', 'title' => '₹1,499 × 4 = ₹5,996', 'text' => '+ ₹9 Delivery • TOTAL ₹6,005', 'highlight' => false],
                ['tag' => '100 ML • 4 PIECES SPECIAL OFFER', 'title' => '₹1,999 × 4 = ~~₹7,996~~', 'text' => 'OFFER ₹7,499 • FREE DELIVERY • TOTAL ₹7,499', 'highlight' => true],
            ],
        ],
        'footer_title' => 'FIXNOT',
        'footer_sub' => 'A Perfumarathi Production',
        'footer_shipping' => 'India • Kerala • UAE • GCC • Worldwide',
        'footer_email' => 'fixnot.ae@gmail.com',
        'footer_phone' => '+971 52 820 9716',
    ];

    /** Max length of each text field. */
    private const TEXTS = [
        'hero_eyebrow' => 80, 'hero_title' => 80, 'hero_highlight' => 80, 'hero_text' => 400,
        'collections_title' => 100, 'collections_text' => 200, 'regions_title' => 80, 'reviews_title' => 100,
        'whatsapp_title' => 100, 'whatsapp_text' => 300, 'whatsapp_button' => 80, 'whatsapp_message' => 1000,
        'home_foot' => 200, 'shop_sub' => 150, 'shop_parent' => 150, 'product_type' => 60,
        'footer_title' => 60, 'footer_sub' => 100, 'footer_shipping' => 200, 'footer_phone' => 40,
    ];

    /** Lists: [max rows, field => max length]. */
    private const LISTS = [
        'collections' => [12, ['title' => 60, 'text' => 80]],
        'benefits' => [6, ['title' => 40, 'text' => 60]],
        'reviews' => [12, ['text' => 400]],
    ];

    private const OFFER_FIELDS = ['tag' => 60, 'title' => 80, 'text' => 200];

    /** Image settings (uploaded in Admin > Fixnote page); null = the image from the original page. */
    public const IMAGES = ['page_logo', 'shop_logo', 'hero', 'bottle'];

    public function brand(): ?Brand
    {
        return Brand::query()->where('slug', self::BRAND)->first();
    }

    /** "30 ml", "30ML" and "30 ML" are the same size. */
    public static function sizeKey(?string $name): ?string
    {
        $n = preg_replace('/\s+/', '', mb_strtoupper((string) $name));
        foreach (self::SIZES as $s) {
            if (str_replace(' ', '', $s) === $n) {
                return $s;
            }
        }

        return null;
    }

    // ---------- Settings ----------

    /** Online prices per size: ['30 ML' => ['price' => 29.0, 'sale_price' => null], ...]. */
    public function prices(): array
    {
        $raw = (array) (Setting::get('fixnote_prices') ?? Setting::DEFAULTS['fixnote_prices']);
        $out = [];
        foreach (self::SIZES as $s) {
            $row = $raw[$s] ?? null;
            $row = is_array($row) ? $row : ['price' => $row];
            $out[$s] = [
                'price' => isset($row['price']) && $row['price'] !== '' ? (float) $row['price'] : null,
                'sale_price' => isset($row['sale_price']) && $row['sale_price'] !== '' ? (float) $row['sale_price'] : null,
            ];
        }

        return $out;
    }

    /** UAE delivery rule: a charge per size, free from N pieces. */
    public function delivery(): array
    {
        $d = (array) (Setting::get('fixnote_delivery') ?? Setting::DEFAULTS['fixnote_delivery']);
        $fees = [];
        foreach (self::SIZES as $s) {
            $fees[$s] = (float) ($d['fees'][$s] ?? 0);
        }

        return [
            'enabled' => (bool) ($d['enabled'] ?? true),
            'countries' => array_values(array_map('strtoupper', (array) ($d['countries'] ?? ['AE']))),
            'fees' => $fees,
            'free_from_pieces' => isset($d['free_from_pieces']) && $d['free_from_pieces'] !== '' ? (int) $d['free_from_pieces'] : null,
        ];
    }

    /** India / Saudi WhatsApp price lists. */
    public function regions(): array
    {
        $r = (array) (Setting::get('fixnote_regions') ?? Setting::DEFAULTS['fixnote_regions']);
        $out = [];
        foreach (array_keys(self::WHATSAPP_REGIONS) as $region) {
            $out[$region] = ['currency' => strtoupper((string) ($r[$region]['currency'] ?? 'AED')), 'prices' => []];
            foreach (self::SIZES as $s) {
                $p = $r[$region]['prices'][$s] ?? null;
                $out[$region]['prices'][$s] = $p !== null && $p !== '' ? (float) $p : null;
            }
        }

        return $out;
    }

    public function page(): array
    {
        $stored = (array) (Setting::get('fixnote_page') ?? []);

        return $this->cleanPage($stored + self::PAGE_DEFAULTS);
    }

    /** Keeps only known fields, trimmed to their limits; missing lists fall back to the defaults. */
    public function cleanPage(array $in): array
    {
        $str = fn ($v, $max) => mb_substr(trim((string) ($v ?? '')), 0, $max);
        $out = [];
        foreach (self::TEXTS as $k => $max) {
            $out[$k] = $str($in[$k] ?? self::PAGE_DEFAULTS[$k], $max);
        }
        $out['footer_email'] = filter_var($in['footer_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
        $out['whatsapp_number'] = preg_replace('/\D/', '', (string) ($in['whatsapp_number'] ?? '')) ?: self::PAGE_DEFAULTS['whatsapp_number'];
        foreach (self::LISTS as $k => [$maxRows, $fields]) {
            $rows = is_array($in[$k] ?? null) ? $in[$k] : self::PAGE_DEFAULTS[$k];
            $out[$k] = $this->cleanRows($rows, $maxRows, $fields);
        }
        foreach (self::REGIONS as $r) {
            $card = $in['region_cards'][$r] ?? self::PAGE_DEFAULTS['region_cards'][$r];
            $out['region_cards'][$r] = ['title' => $str($card['title'] ?? '', 60), 'text' => $str($card['text'] ?? '', 100)];
            $offers = $in['offers'][$r] ?? self::PAGE_DEFAULTS['offers'][$r];
            $out['offers'][$r] = array_map(
                fn ($row) => $row + ['highlight' => false],
                $this->cleanRows(is_array($offers) ? $offers : [], 12, self::OFFER_FIELDS, true),
            );
        }

        return $out;
    }

    private function cleanRows(array $rows, int $maxRows, array $fields, bool $highlight = false): array
    {
        $out = [];
        foreach (array_slice(array_values($rows), 0, $maxRows) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($fields as $f => $max) {
                $clean[$f] = mb_substr(trim((string) ($row[$f] ?? '')), 0, $max);
            }
            if (implode('', $clean) === '') {
                continue; // empty row
            }
            if ($highlight) {
                $clean['highlight'] = filter_var($row['highlight'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
            $out[] = $clean;
        }

        return $out;
    }

    /** Validation rules for the admin "page" form (sizes are capped again by cleanPage). */
    public static function pageRules(): array
    {
        $rules = ['page' => 'required|array'];
        foreach (self::TEXTS as $k => $max) {
            $rules["page.$k"] = "nullable|string|max:$max";
        }
        $rules['page.footer_email'] = 'nullable|email|max:150';
        $rules['page.whatsapp_number'] = ['nullable', 'string', 'regex:/^[0-9]{7,20}$/'];
        foreach (self::LISTS as $k => [$maxRows, $fields]) {
            $rules["page.$k"] = "nullable|array|max:$maxRows";
            foreach ($fields as $f => $max) {
                $rules["page.$k.*.$f"] = "nullable|string|max:$max";
            }
        }
        foreach (self::REGIONS as $r) {
            $rules["page.region_cards.$r.title"] = 'nullable|string|max:60';
            $rules["page.region_cards.$r.text"] = 'nullable|string|max:100';
            $rules["page.offers.$r"] = 'nullable|array|max:12';
            foreach (self::OFFER_FIELDS as $f => $max) {
                $rules["page.offers.$r.*.$f"] = "nullable|string|max:$max";
            }
            $rules["page.offers.$r.*.highlight"] = 'nullable|boolean';
        }

        return $rules;
    }

    public function images(): array
    {
        $out = [];
        foreach (self::IMAGES as $k) {
            $out[$k] = Present::url(Setting::get("fixnote_{$k}_path"));
        }

        return $out;
    }

    // ---------- Catalogue ----------

    /**
     * Adds the perfumes from the catalogue file that are not in the shop yet (matched by code = product SKU,
     * deleted ones included, so a perfume the admin removed is not brought back).
     *
     * @return array{created:int, skipped:int}
     */
    public function import(?string $file = null): array
    {
        $brand = $this->brand();
        if (! $brand) {
            throw new \RuntimeException('The Fixnote brand is missing. Run: php artisan db:seed');
        }
        $file ??= database_path('data/fixnote-catalogue.json');
        $data = json_decode((string) file_get_contents($file), true);
        $items = $data['items'] ?? null;
        if (! is_array($items)) {
            throw new \RuntimeException("No perfumes found in $file.");
        }
        $existing = array_flip(Product::withTrashed()->whereNotNull('sku')->pluck('sku')->map(fn ($s) => mb_strtoupper($s))->all());
        $category = Category::query()->where('slug', 'inspired-fragrances')->first();
        $order = (int) Product::withTrashed()->where('brand_id', $brand->id)->max('sort_order');
        $created = 0;
        $skipped = 0;
        DB::transaction(function () use ($items, $existing, $brand, $category, &$order, &$created, &$skipped) {
            foreach ($items as $it) {
                $code = mb_strtoupper(trim((string) ($it['code'] ?? '')));
                $name = trim((string) ($it['name'] ?? ''));
                if ($code === '' || $name === '' || isset($existing[$code])) {
                    $skipped++;

                    continue;
                }
                $this->create([
                    'code' => $code, 'name' => $name, 'inspired_by' => $it['inspired_by'] ?? null, 'notes' => $it['notes'] ?? null,
                    'sort_order' => ++$order,
                ], $brand, $category);
                $existing[$code] = true;
                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** One Fixnote perfume with the three standard sizes at the current Fixnote prices (always in stock). */
    public function create(array $v, ?Brand $brand = null, ?Category $category = null): Product
    {
        $brand ??= $this->brand();
        $code = mb_strtoupper(trim($v['code']));
        $p = Product::create([
            'brand_id' => $brand?->id,
            'name' => $v['name'],
            'slug' => $this->uniqueSlug("$code {$v['name']}"),
            'sku' => $code,
            'inspired_by' => $v['inspired_by'] ?: null,
            'accords' => isset($v['notes']) && $v['notes'] !== '' ? mb_substr($v['notes'], 0, 500) : null,
            'concentration' => self::CONCENTRATION,
            'is_active' => $v['is_active'] ?? true,
            'sort_order' => $v['sort_order'] ?? ((int) Product::withTrashed()->where('brand_id', $brand?->id)->max('sort_order') + 1),
        ]);
        foreach ($this->prices() as $size => $price) {
            $p->variants()->create([
                'name' => $size,
                'sku' => $this->variantSku($code, $size),
                'price' => $price['price'],
                'sale_price' => $price['sale_price'] !== null && $price['price'] !== null && $price['sale_price'] < $price['price'] ? $price['sale_price'] : null,
                'track_stock' => false,
                'stock_quantity' => 0,
                'stock_status' => 'in_stock',
                'is_active' => true,
                'sort_order' => array_search($size, self::SIZES, true),
            ]);
        }
        $category ??= Category::query()->where('slug', 'inspired-fragrances')->first();
        if ($category) {
            $p->categories()->syncWithoutDetaching([$category->id]);
        }

        return $p;
    }

    private function variantSku(string $code, string $size): ?string
    {
        $sku = $code.'-'.(int) $size;

        return ProductVariant::query()->where('sku', $sku)->exists() ? null : $sku;
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'fixnote';
        $slug = $base;
        $i = 2;
        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Sets the price of every Fixnote size (e.g. all 30 ML) and saves it as the price for new perfumes.
     *
     * @param  array<string,array{price:?float,sale_price:?float}>  $prices
     * @return int sizes updated
     */
    public function applyPrices(array $prices, bool $toAll = true): int
    {
        $before = $this->prices();
        $clean = [];
        foreach (self::SIZES as $s) {
            $price = $prices[$s]['price'] ?? null;
            $sale = $prices[$s]['sale_price'] ?? null;
            $price = $price === null || $price === '' ? null : round((float) $price, 2);
            $sale = $sale === null || $sale === '' || $price === null || (float) $sale >= $price ? null : round((float) $sale, 2);
            $clean[$s] = ['price' => $price, 'sale_price' => $sale];
        }
        Setting::put(['fixnote_prices' => $clean]);
        // Only sizes whose price changed: a new 30 ML price keeps special prices set on single 50 / 100 ML perfumes.
        $changed = array_keys(array_filter($clean, fn ($p, $s) => $p != $before[$s], ARRAY_FILTER_USE_BOTH));
        if (! $toAll || ! $changed || ! ($brand = $this->brand())) {
            return 0;
        }
        $count = 0;
        $variants = ProductVariant::query()->whereIn('product_id', Product::withTrashed()->where('brand_id', $brand->id)->select('id'))->get();
        foreach ($variants as $v) {
            $size = self::sizeKey($v->name);
            if ($size === null || ! in_array($size, $changed, true)) {
                continue;
            }
            $v->update($clean[$size]);
            $count++;
        }

        return $count;
    }

    /** Everything the Fixnote page needs, in one response. */
    public function catalogue(): array
    {
        $brand = $this->brand();
        $products = $brand && $brand->is_active
            ? Product::query()->visible()->where('brand_id', $brand->id)
                ->with(['variants' => fn ($q) => $q->where('is_active', true), 'images'])
                ->orderBy('sort_order')->orderBy('id')->get()
            : collect();
        $inspired = [];
        $items = [];
        foreach ($products as $p) {
            $variants = $p->variants->sortBy(fn ($v) => array_search(self::sizeKey($v->name), self::SIZES, true) === false ? 99 : array_search(self::sizeKey($v->name), self::SIZES, true))->values();
            if ($variants->isEmpty()) {
                continue;
            }
            $group = $p->inspired_by ?: 'More Fixnote';
            $inspired[$group] = true;
            $img = $p->images->first();
            $items[] = [
                'id' => $p->id,
                'code' => $p->sku,
                'name' => $p->name,
                'slug' => $p->slug,
                'inspired_by' => $group,
                'notes' => $p->accords,
                'image' => $img?->thumb_url,
                'variants' => $variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'size' => self::sizeKey($v->name),
                    'price' => $v->price !== null ? (float) $v->price : null,
                    'current_price' => $v->currentPrice(),
                    'on_sale' => $v->onSale(),
                    'in_stock' => $v->inStock(),
                    'purchasable' => $v->purchasable(),
                ])->all(),
            ];
        }

        return [
            'page' => $this->page(),
            'images' => $this->images(),
            'currency' => Setting::get('currency', 'AED'),
            'prices' => $this->prices(),
            'regions' => $this->regions(),
            'delivery' => $this->delivery(),
            // Perfumes without an "inspired by" brand come last.
            'groups' => array_values(array_merge(array_diff(array_keys($inspired), ['More Fixnote']), isset($inspired['More Fixnote']) ? ['More Fixnote'] : [])),
            'items' => $items,
        ];
    }

    // ---------- Checkout rules ----------

    public static function isFixnote(ProductVariant $v): bool
    {
        return $v->product?->brand?->slug === self::BRAND;
    }

    /** Why a Fixnote item cannot be ordered online for this country (India / Saudi order on WhatsApp). */
    public static function blockedFor(?string $country): ?string
    {
        if ($country && in_array(strtoupper($country), self::WHATSAPP_REGIONS, true)) {
            return 'Fixnote orders for India and Saudi Arabia are taken on WhatsApp. Open the Fixnote page, choose your region and send your selection.';
        }

        return null;
    }

    /**
     * Fixnote delivery charge for this country, or null when the normal delivery rate applies.
     *
     * @param  array<string,int>  $pieces  size => quantity (Fixnote items only)
     * @return array{fee:float, pieces:int, free_from:?int}|null
     */
    public function deliveryFor(array $pieces, ?string $country): ?array
    {
        $total = array_sum($pieces);
        $d = $this->delivery();
        if ($total <= 0 || ! $d['enabled'] || ! in_array(strtoupper($country ?: 'AE'), $d['countries'], true)) {
            return null;
        }
        $fee = 0.0;
        foreach ($pieces as $size => $qty) {
            if ($qty > 0) {
                $fee = max($fee, (float) ($d['fees'][$size] ?? max($d['fees'] ?: [0])));
            }
        }
        if ($d['free_from_pieces'] !== null && $total >= $d['free_from_pieces']) {
            $fee = 0.0;
        }

        return ['fee' => round($fee, 2), 'pieces' => $total, 'free_from' => $d['free_from_pieces']];
    }
}
