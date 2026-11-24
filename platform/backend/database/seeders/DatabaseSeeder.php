<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\HomeSection;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Store defaults. Safe to run again: existing rows are kept as they are. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['Perfumarathi', 'perfumarathi', 'Secret of Attractiveness'], ['Fixnote', 'fixnote', 'Inspired Fragrances by Perfumarathi']] as $i => [$name, $slug, $tag]) {
            Brand::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'tagline' => $tag, 'sort_order' => $i]);
        }

        $cats = ["Men's Perfumes", "Women's Perfumes", 'Unisex Perfumes', 'Oud Collection', 'Arabic Perfumes', 'Inspired Fragrances'];
        foreach ($cats as $i => $name) {
            Category::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'kind' => 'category', 'sort_order' => $i]);
        }
        foreach (['Combos', 'Luxury Collection', 'Special Offers'] as $i => $name) {
            Category::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'kind' => 'collection', 'sort_order' => $i]);
        }

        PaymentMethod::query()->firstOrCreate(['code' => 'cod'], [
            'title' => 'Cash on Delivery', 'description' => 'Pay in cash when your order arrives.',
            'is_enabled' => true, 'sort_order' => 1, 'fee' => 0,
        ]);
        PaymentMethod::query()->firstOrCreate(['code' => 'card'], [
            'provider' => 'stripe', 'title' => 'Card, Apple Pay or Google Pay',
            'description' => 'Secure payment by Stripe. Visa, Mastercard, Apple Pay and Google Pay.',
            'is_enabled' => false, 'sort_order' => 2, 'fee' => 0, 'options' => ['mode' => 'test'],
        ]);

        PaymentMethod::query()->firstOrCreate(['code' => 'transfer'], PaymentMethod::TRANSFER_DEFAULTS);

        if (HomeSection::query()->doesntExist()) {
            foreach ([['Featured Perfumes', 'featured'], ['Best Sellers', 'best_sellers'], ['New Arrivals', 'new_arrivals'], ['Special Offers', 'on_sale']] as $i => [$title, $source]) {
                HomeSection::create(['title' => $title, 'source' => $source, 'limit' => 8, 'sort_order' => $i]);
            }
        }
    }
}
