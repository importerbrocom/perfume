<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Store settings (name, logo, contacts, socials, shipping, policies, homepage texts).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();      // cod, card
            $table->string('provider', 30)->nullable(); // stripe
            $table->string('title');
            $table->string('description', 500)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('fee', 12, 2)->default(0);         // e.g. COD charge
            $table->decimal('min_total', 12, 2)->nullable();
            $table->decimal('max_total', 12, 2)->nullable();
            $table->json('countries')->nullable();             // null = all countries
            $table->json('options')->nullable();               // public options (test mode, wallets...)
            $table->text('credentials')->nullable();           // encrypted JSON, never sent to the browser
            $table->timestamps();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('placement', 30)->default('home'); // home, promo
            $table->string('title')->nullable();
            $table->string('subtitle', 500)->nullable();
            $table->string('button_text')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('image_path')->nullable();
            $table->string('mobile_image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('text', 300);
            $table->string('link_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        // Homepage product sections (Featured, Best Sellers, New Arrivals, a collection...).
        Schema::create('home_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle', 500)->nullable();
            // featured, best_sellers, new_arrivals, on_sale, category, brand, manual
            $table->string('source', 30);
            $table->unsignedBigInteger('source_id')->nullable(); // category or brand id
            $table->json('product_ids')->nullable();            // for source = manual
            $table->unsignedSmallInteger('limit')->default(8);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['home_sections', 'announcements', 'banners', 'payment_methods', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
