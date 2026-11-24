<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin = everything; manager = products, stock, orders, content (no payments, settings or staff).
            $table->string('role', 20)->default('manager')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            // category = Men's, Women's, Oud...; collection = curated groups (Combos, Luxury, Offers...).
            $table->string('kind', 20)->default('category');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['kind', 'is_active']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable()->unique();
            $table->string('tagline')->nullable();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('notes_top', 500)->nullable();
            $table->string('notes_middle', 500)->nullable();
            $table->string('notes_base', 500)->nullable();
            $table->string('accords', 500)->nullable();
            $table->string('fragrance_family')->nullable();
            $table->string('perfume_type')->nullable();     // e.g. Pure Parfume, Eau de Parfum
            $table->string('concentration')->nullable();    // e.g. 20-25%
            $table->string('gender', 20)->nullable();       // men, women, unisex
            $table->string('traits')->nullable();           // "Bold | Elegant | Long Lasting"
            $table->string('longevity')->nullable();        // "12-24h"
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new_arrival')->default(false);
            $table->boolean('is_best_seller')->default(false);
            $table->boolean('is_combo')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('total_sales')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
        });

        // Combo contents / "frequently bought together".
        Schema::create('product_related', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['product_id', 'related_id']);
        });

        // Every product has at least one variant (size). Prices and stock live here.
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Standard'); // "100 ml"
            $table->string('sku')->nullable()->unique();
            $table->decimal('price', 12, 2)->nullable();      // null = price on request (not purchasable)
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->boolean('track_stock')->default(true);
            $table->integer('stock_quantity')->default(0);
            $table->string('stock_status', 20)->default('in_stock'); // used when track_stock = false
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'is_active']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('author_name');
            $table->string('author_email')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->boolean('is_approved')->default(false);
            $table->string('source', 30)->default('customer'); // customer | imported
            $table->timestamps();
            $table->index(['product_id', 'is_approved']);
        });
    }

    public function down(): void
    {
        foreach (['product_reviews', 'product_images', 'product_variants', 'product_related', 'category_product', 'products', 'categories', 'brands'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'last_login_at']);
        });
    }
};
