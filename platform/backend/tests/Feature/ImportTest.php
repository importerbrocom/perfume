<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportTest extends TestCase
{
    private function export(): string
    {
        $dir = sys_get_temp_dir().'/pm-export-'.uniqid();
        mkdir($dir.'/images', 0777, true);
        $img = imagecreatetruecolor(900, 900);
        imagefill($img, 0, 0, imagecolorallocate($img, 120, 40, 20));
        imagejpeg($img, $dir.'/images/31-kausar.jpg');
        imagepng($img, $dir.'/images/32-combo.png');
        file_put_contents($dir.'/catalog.json', json_encode(['currency' => 'AED', 'products' => [
            [
                'wp_id' => 31, 'name' => 'Kausar', 'slug' => 'kausar', 'sku' => 'PM-KAUSAR', 'status' => 'publish', 'featured' => false,
                'short_description' => 'A rich, warm fragrance.', 'description' => '<p>A rich fragrance.</p><p><strong>Pure Parfume</strong> · 100 ml</p>',
                'tagline' => 'A Fragrance of Divine Elegance', 'traits' => 'Luxurious | Elegant',
                'notes' => ['top' => 'Saffron, Jasmine', 'middle' => 'Amberwood', 'base' => 'Fir Resin'],
                'brand' => [['name' => 'Perfumarathi', 'slug' => 'perfumarathi']],
                'categories' => [['name' => 'Perfumarathi Collection', 'slug' => 'perfumarathi-collection'], ['name' => "Men's Perfumes", 'slug' => 'mens-perfumes']],
                'family' => [], 'related_wp_ids' => [], 'images' => [['file' => '31-kausar.jpg', 'alt' => 'Kausar']],
                'variants' => [['name' => '100 ml', 'sku' => null, 'price' => null, 'sale_price' => null, 'active' => true, 'manage_stock' => false, 'quantity' => null, 'status' => 'instock']],
                'reviews' => [['author' => 'Rohit Verma', 'rating' => 5, 'body' => 'Fresh & classy', 'date' => '2026-11-01T10:00:00+00:00', 'imported' => true]],
                'total_sales' => 0, 'menu_order' => 0,
            ],
            [
                'wp_id' => 55, 'name' => '3 PCS Combo', 'slug' => '3-pcs-combo', 'sku' => 'PM-3-PCS-COMBO', 'status' => 'publish', 'featured' => false,
                'short_description' => 'Three signature scents.', 'description' => '', 'tagline' => 'Premium Collection', 'traits' => '',
                'notes' => ['top' => '', 'middle' => '', 'base' => ''], 'brand' => [['name' => 'Perfumarathi', 'slug' => 'perfumarathi']],
                'categories' => [['name' => 'Combos', 'slug' => 'combos']], 'family' => [], 'related_wp_ids' => [31],
                'images' => [['file' => '32-combo.png', 'alt' => ''], ['file' => 'missing.jpg', 'alt' => '']],
                'variants' => [['name' => 'Standard', 'sku' => null, 'price' => 450.0, 'sale_price' => 399.0, 'active' => true, 'manage_stock' => true, 'quantity' => 6, 'status' => 'instock']],
                'reviews' => [], 'total_sales' => 2, 'menu_order' => 3,
            ],
        ]]));

        return $dir;
    }

    public function test_import_from_wordpress_export(): void
    {
        Storage::fake('public');
        $dir = $this->export();
        $this->artisan('shop:import-wp', ['dir' => $dir])->expectsOutputToContain('2 created')->assertSuccessful();

        $k = Product::query()->where('slug', 'kausar')->with(['variants', 'images', 'categories', 'reviews', 'brand'])->first();
        $this->assertSame('Perfumarathi', $k->brand->name);
        $this->assertSame('Saffron, Jasmine', $k->notes_top);
        $this->assertSame('men', $k->gender);
        $this->assertSame('Pure Parfume', $k->perfume_type);
        $this->assertNull($k->variants[0]->price); // price on request until the admin sets it
        $this->assertSame('100 ml', $k->variants[0]->name);
        $this->assertCount(1, $k->images);
        Storage::disk('public')->assertExists($k->images[0]->path);
        $this->assertSame('imported', $k->reviews[0]->source);
        $this->assertTrue($k->reviews[0]->is_approved);

        $c = Product::query()->where('slug', '3-pcs-combo')->with(['variants', 'related'])->first();
        $this->assertTrue($c->is_combo);
        $this->assertEquals(399, (float) $c->variants[0]->sale_price);
        $this->assertSame(6, $c->variants[0]->stock_quantity);
        $this->assertSame([$k->id], $c->related->pluck('id')->all());

        // Shop API shows them
        $this->getJson('/api/products/3-pcs-combo')->assertOk()->assertJsonPath('bundle.0.slug', 'kausar')->assertJsonPath('product.purchasable', true);
        $this->getJson('/api/products/kausar')->assertJsonPath('product.priced', false)->assertJsonPath('product.reviews', 1);

        // Second run changes nothing.
        $this->artisan('shop:import-wp', ['dir' => $dir])->expectsOutputToContain('0 created')->assertSuccessful();
        $this->assertSame(2, Product::query()->count());
        $this->assertSame(1, $k->images()->count());
    }

    public function test_import_rejects_a_folder_without_export(): void
    {
        $this->artisan('shop:import-wp', ['dir' => sys_get_temp_dir()])->assertFailed();
    }
}
