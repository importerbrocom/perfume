<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Sample products for local development and the browser (E2E) tests.
 * Not used on the real store: there the catalogue comes from `php artisan shop:import-wp`.
 * Run with: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);
        $perf = Brand::query()->where('slug', 'perfumarathi')->value('id');
        $fix = Brand::query()->where('slug', 'fixnote')->value('id');
        $men = Category::query()->where('slug', 'mens-perfumes')->value('id');
        $oud = Category::query()->where('slug', 'oud-collection')->value('id');

        $rows = [
            ['Kausar', 'kausar', $perf, [$men, $oud], [['50 ml', 95, null, 20], ['100 ml', 150, 129, 12]], true],
            ['Amber Night', 'amber-night', $perf, [$men], [['100 ml', 180, null, 8]], true],
            ['Royal Oud', 'royal-oud', $perf, [$oud], [['100 ml', 220, null, 0]], false],
            ['Fixnote Aqua', 'fixnote-aqua', $fix, [$men], [['50 ml', 60, null, 30], ['100 ml', 99, null, 15]], true],
            ['Fixnote Rose', 'fixnote-rose', $fix, [], [['100 ml', null, null, 5]], false],
        ];
        foreach ($rows as $i => [$name, $slug, $brand, $cats, $variants, $featured]) {
            if (Product::query()->where('slug', $slug)->exists()) {
                continue;
            }
            $p = Product::query()->create([
                'name' => $name, 'slug' => $slug, 'brand_id' => $brand, 'is_active' => true, 'is_featured' => $featured,
                'sort_order' => $i, 'tagline' => 'Long-lasting eau de parfum',
                'short_description' => "$name is a rich, long-lasting fragrance made in the UAE.",
                'description' => "<p>$name opens with fresh citrus and settles into warm amber and oud.</p>",
                'notes_top' => 'Bergamot, Saffron', 'notes_middle' => 'Rose, Amber', 'notes_base' => 'Oud, Musk',
            ]);
            $p->categories()->sync(array_filter($cats));
            foreach ($variants as $n => [$vName, $price, $sale, $stock]) {
                $p->variants()->create([
                    'name' => $vName, 'price' => $price, 'sale_price' => $sale, 'sku' => strtoupper($slug).'-'.($n + 1),
                    'track_stock' => true, 'stock_quantity' => $stock, 'sort_order' => $n,
                ]);
            }
        }
    }
}
