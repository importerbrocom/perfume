<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $paid = fn () => Order::query()->where('payment_status', 'paid');
        $threshold = (int) Setting::get('low_stock_threshold', 3);
        $activeVariants = ProductVariant::query()->where('is_active', true)->whereHas('product', fn ($p) => $p->where('is_active', true));

        // Sales per day for the last 30 days (paid orders).
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[now()->subDays($i)->format('Y-m-d')] = 0.0;
        }
        $rows = $paid()->where('paid_at', '>=', now()->subDays(29)->startOfDay())->get(['paid_at', 'total']);
        foreach ($rows as $r) {
            $d = $r->paid_at?->format('Y-m-d');
            if ($d && isset($days[$d])) {
                $days[$d] += (float) $r->total;
            }
        }

        $lowStock = (clone $activeVariants)->with('product:id,name')->where('track_stock', true)->where('stock_quantity', '>', 0)
            ->whereRaw('stock_quantity <= coalesce(low_stock_threshold, ?)', [$threshold])->orderBy('stock_quantity')->limit(10)->get();

        $best = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', ['cancelled'])
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->select('order_items.product_id', 'order_items.product_name', DB::raw('sum(order_items.quantity) as qty'), DB::raw('sum(order_items.line_total) as revenue'))
            ->orderByDesc('qty')->limit(5)->get();

        return response()->json([
            'currency' => Setting::get('currency', 'AED'),
            'sales_total' => (float) $paid()->sum('total'),
            'sales_today' => (float) $paid()->whereDate('paid_at', today())->sum('total'),
            'sales_month' => (float) $paid()->where('paid_at', '>=', now()->startOfMonth())->sum('total'),
            'orders_total' => Order::query()->count(),
            'orders_pending' => Order::query()->whereIn('status', ['pending', 'confirmed', 'processing'])->count(),
            'orders_today' => Order::query()->whereDate('created_at', today())->count(),
            'orders_delivered' => Order::query()->where('status', 'delivered')->count(),
            'customers_total' => Customer::query()->count(),
            'products_total' => Product::query()->count(),
            'products_active' => Product::query()->where('is_active', true)->count(),
            'products_unpriced' => Product::query()->whereHas('variants', fn ($v) => $v->where('is_active', true)->whereNull('price'))->count(),
            'stock_units' => (int) (clone $activeVariants)->where('track_stock', true)->sum('stock_quantity'),
            'variants_low' => (clone $activeVariants)->where('track_stock', true)->where('stock_quantity', '>', 0)->whereRaw('stock_quantity <= coalesce(low_stock_threshold, ?)', [$threshold])->count(),
            'variants_out' => (clone $activeVariants)->where(fn ($s) => $s->where(fn ($t) => $t->where('track_stock', true)->where('stock_quantity', '<=', 0))->orWhere(fn ($t) => $t->where('track_stock', false)->where('stock_status', 'out_of_stock')))->count(),
            'reviews_pending' => ProductReview::query()->where('is_approved', false)->count(),
            'sales_by_day' => collect($days)->map(fn ($v, $d) => ['date' => $d, 'total' => round($v, 2)])->values(),
            'low_stock' => $lowStock->map(fn ($v) => ['id' => $v->id, 'product' => $v->product?->name, 'variant' => $v->name, 'stock' => $v->stock_quantity]),
            'best_sellers' => $best->map(fn ($b) => ['product_id' => $b->product_id, 'name' => $b->product_name, 'quantity' => (int) $b->qty, 'revenue' => (float) $b->revenue]),
            'recent_orders' => Order::query()->latest('id')->limit(8)->get(['id', 'number', 'customer_name', 'status', 'payment_status', 'payment_method', 'total', 'currency', 'created_at']),
        ]);
    }
}
