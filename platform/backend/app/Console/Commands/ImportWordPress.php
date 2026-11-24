<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Services\ImageStore;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * php artisan shop:import-wp /path/to/export   (folder made by server/export-wp.php)
 * Creates products that are not in the shop yet (matched by slug). Existing products are left alone
 * unless --update is given. Prices and stock come across exactly as they were in WooCommerce
 * (empty price = "price on request").
 */
class ImportWordPress extends Command
{
    protected $signature = 'shop:import-wp {dir} {--update : also update products that already exist (keeps their images)}';

    protected $description = 'Import products, images, notes, categories and reviews exported from WordPress';

    public function handle(ImageStore $images, StockService $stock): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/');
        $file = $dir.'/catalog.json';
        if (! is_file($file)) {
            $this->error("No catalog.json in $dir");

            return 1;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data) || ! isset($data['products'])) {
            $this->error('catalog.json is not a WordPress export.');

            return 1;
        }

        $created = $updated = $skipped = $imgs = $reviews = 0;
        $wpToId = [];
        $comboCat = Category::query()->firstOrCreate(['slug' => 'combos'], ['name' => 'Combos', 'kind' => 'collection']);

        foreach ($data['products'] as $row) {
            $slug = Str::slug($row['slug'] ?? $row['name']);
            $existing = Product::withTrashed()->where('slug', $slug)->first();
            if ($existing && ! $this->option('update')) {
                $wpToId[$row['wp_id']] = $existing->id;
                $skipped++;
                $this->line("  exists: {$row['name']}");

                continue;
            }

            DB::transaction(function () use ($row, $slug, $existing, $images, $stock, $dir, $comboCat, &$created, &$updated, &$imgs, &$reviews, &$wpToId) {
                $brand = null;
                if (! empty($row['brand'][0])) {
                    $brand = Brand::query()->firstOrCreate(['slug' => Str::slug($row['brand'][0]['slug'])], ['name' => $row['brand'][0]['name']]);
                }
                $catIds = [];
                $isCombo = false;
                foreach ($row['categories'] ?? [] as $c) {
                    $cslug = Str::slug($c['slug']);
                    if ($cslug === 'uncategorized') {
                        continue;
                    }
                    if ($cslug === 'combos') {
                        $isCombo = true;
                        $catIds[] = $comboCat->id;

                        continue;
                    }
                    $kind = str_contains($cslug, 'collection') || in_array($cslug, ['special-offers', 'best-sellers', 'new-arrivals'], true) ? 'collection' : 'category';
                    $catIds[] = Category::query()->firstOrCreate(['slug' => $cslug], ['name' => $c['name'], 'kind' => $kind])->id;
                }
                $catSlugs = collect($row['categories'] ?? [])->pluck('slug')->map(fn ($s) => Str::slug($s));
                $gender = $catSlugs->contains('mens-perfumes') ? 'men' : ($catSlugs->contains('womens-perfumes') ? 'women' : ($catSlugs->contains('unisex-perfumes') ? 'unisex' : null));

                $p = $existing ?? new Product;
                $p->fill([
                    'brand_id' => $brand?->id,
                    'name' => $row['name'],
                    'sku' => $row['sku'] ?? null ?: ($p->sku ?? null),
                    'tagline' => $row['tagline'] ?: null,
                    'short_description' => $row['short_description'] ?: null,
                    'description' => $row['description'] ?: null,
                    'notes_top' => $row['notes']['top'] ?? null ?: null,
                    'notes_middle' => $row['notes']['middle'] ?? null ?: null,
                    'notes_base' => $row['notes']['base'] ?? null ?: null,
                    'fragrance_family' => $row['family'][0]['name'] ?? null,
                    'traits' => $row['traits'] ?: null,
                    'gender' => $gender,
                    'perfume_type' => str_contains(strtolower(strip_tags((string) $row['description'])), 'pure parfume') ? 'Pure Parfume' : null,
                    'is_active' => ($row['status'] ?? 'publish') === 'publish',
                    'is_featured' => (bool) ($row['featured'] ?? false),
                    'is_combo' => $isCombo,
                    'sort_order' => (int) ($row['menu_order'] ?? 0),
                ]);
                $p->slug = $slug;
                if ($p->trashed()) {
                    $p->restore();
                }
                $p->save();
                $p->forceFill(['total_sales' => max((int) $p->total_sales, (int) ($row['total_sales'] ?? 0))])->save();
                $p->categories()->syncWithoutDetaching($catIds);

                if (! $existing) {
                    foreach (array_values($row['variants'] ?? []) as $i => $v) {
                        $variant = $p->variants()->create([
                            'name' => $v['name'] ?: 'Standard',
                            'sku' => ! empty($v['sku']) && ! ProductVariant::query()->where('sku', $v['sku'])->exists() ? $v['sku'] : null,
                            'price' => $v['price'],
                            'sale_price' => $v['sale_price'] !== null && $v['price'] !== null && $v['sale_price'] < $v['price'] ? $v['sale_price'] : null,
                            'track_stock' => (bool) $v['manage_stock'],
                            'stock_quantity' => $v['manage_stock'] ? max(0, (int) $v['quantity']) : 0,
                            'stock_status' => $v['status'] === 'outofstock' ? 'out_of_stock' : 'in_stock',
                            'is_active' => (bool) ($v['active'] ?? true),
                            'sort_order' => $i,
                        ]);
                        if ($variant->track_stock && $variant->stock_quantity > 0) {
                            $stock->log($variant, $variant->stock_quantity, 'import', null, null, 'Imported from WordPress');
                        }
                    }
                    if ($p->variants()->doesntExist()) {
                        $p->variants()->create(['name' => 'Standard', 'track_stock' => false, 'stock_status' => 'in_stock']);
                    }
                    foreach (array_values($row['images'] ?? []) as $i => $img) {
                        $path = $dir.'/images/'.basename($img['file']);
                        if (! is_file($path)) {
                            $this->warn("  missing image file {$img['file']}");

                            continue;
                        }
                        try {
                            $saved = $images->storeBytes((string) file_get_contents($path), 'products');
                            $p->images()->create($saved + ['alt' => $img['alt'] ?: $p->name, 'sort_order' => $i]);
                            $imgs++;
                        } catch (\Throwable $e) {
                            $this->warn("  image {$img['file']} skipped: ".$e->getMessage());
                        }
                    }
                    foreach ($row['reviews'] ?? [] as $r) {
                        if (trim((string) $r['body']) === '') {
                            continue;
                        }
                        ProductReview::create([
                            'product_id' => $p->id, 'author_name' => mb_substr($r['author'] ?: 'Customer', 0, 80),
                            'rating' => max(1, min(5, (int) $r['rating'] ?: 5)), 'body' => strip_tags($r['body']),
                            'is_approved' => true, 'source' => ! empty($r['imported']) ? 'imported' : 'customer',
                            'created_at' => ! empty($r['date']) ? Carbon::parse($r['date']) : now(),
                        ]);
                        $reviews++;
                    }
                    $created++;
                    $this->line("  created: {$p->name}");
                } else {
                    $updated++;
                    $this->line("  updated: {$p->name}");
                }
                $wpToId[$row['wp_id']] = $p->id;
            });
        }

        // Combo contents / related products (needs all products first).
        foreach ($data['products'] as $row) {
            $pid = $wpToId[$row['wp_id']] ?? null;
            if (! $pid || empty($row['related_wp_ids'])) {
                continue;
            }
            $p = Product::find($pid);
            if ($p && $p->related()->doesntExist()) {
                $sync = [];
                foreach (array_values($row['related_wp_ids']) as $i => $wid) {
                    if (isset($wpToId[$wid]) && $wpToId[$wid] !== $pid) {
                        $sync[$wpToId[$wid]] = ['sort_order' => $i];
                    }
                }
                $p->related()->sync($sync);
            }
        }

        $this->info("Done: $created created, $updated updated, $skipped already there, $imgs images, $reviews reviews.");

        return 0;
    }
}
