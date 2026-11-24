<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('city')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type', 10); // percent | fixed
            $table->decimal('value', 12, 2);
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('access_token', 64)->unique(); // lets the buyer's browser load / pay this order
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // pending, confirmed, processing, shipped, delivered, cancelled, returned
            $table->string('status', 20)->default('pending');
            // pending, paid, failed, refunded, cod_pending
            $table->string('payment_status', 20)->default('pending');
            $table->string('payment_method', 30);
            $table->string('currency', 3);
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 40);
            $table->string('ship_country', 2);
            $table->string('ship_state')->nullable();
            $table->string('ship_city');
            $table->string('ship_address', 500);
            $table->string('ship_postal_code', 20)->nullable();
            $table->text('customer_note')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->string('coupon_code')->nullable();
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('payment_fee', 12, 2)->default(0); // e.g. COD charge
            $table->decimal('total', 12, 2);
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->timestamp('stock_reserved_at')->nullable();
            $table->timestamp('stock_released_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['payment_status', 'created_at']);
            $table->index('customer_email');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30); // status, payment, note, tracking, stock
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->text('message')->nullable();
            $table->boolean('is_customer_visible')->default(false);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('method', 30);           // cod, card
            $table->string('provider', 30)->nullable(); // stripe
            $table->string('provider_reference')->nullable()->index(); // PaymentIntent id
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status', 20);           // pending, paid, failed, refunded, cancelled
            $table->string('failure_message', 500)->nullable();
            $table->json('details')->nullable();    // non-secret provider info (card brand, last4, wallet)
            $table->timestamps();
        });

        // Processed webhook events: makes webhook handling idempotent.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('change');
            $table->integer('quantity_after');
            $table->string('reason', 30); // order, order_cancelled, payment_failed, manual, import
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['variant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'webhook_events', 'payments', 'order_events', 'order_items', 'orders', 'coupons', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
