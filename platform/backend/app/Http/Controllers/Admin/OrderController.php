<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\StripeGateway;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    private function filtered(array $v)
    {
        $q = Order::query()->withCount('items');
        if (! empty($v['search'])) {
            $t = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']).'%';
            $q->where(fn ($w) => $w->where('number', 'like', $t)->orWhere('customer_name', 'like', $t)
                ->orWhere('customer_email', 'like', $t)->orWhere('customer_phone', 'like', $t)->orWhere('tracking_number', 'like', $t));
        }

        return $q->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($v['payment_status'] ?? null, fn ($q, $s) => $q->where('payment_status', $s))
            ->when($v['payment_method'] ?? null, fn ($q, $s) => $q->where('payment_method', $s))
            ->when($v['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($v['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'payment_status' => ['nullable', Rule::in(Order::PAYMENT_STATUSES)],
            'payment_method' => 'nullable|string|max:30',
            'from' => 'nullable|date', 'to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->filtered($this->filters($request))->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Order $o) => [
                'id' => $o->id, 'number' => $o->number, 'created_at' => $o->created_at?->toIso8601String(),
                'customer_name' => $o->customer_name, 'customer_email' => $o->customer_email, 'customer_phone' => $o->customer_phone,
                'ship_city' => $o->ship_city, 'ship_country' => $o->ship_country,
                'status' => $o->status, 'payment_status' => $o->payment_status, 'payment_method' => $o->payment_method,
                'total' => (float) $o->total, 'currency' => $o->currency, 'items_count' => $o->items_count,
            ]),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
            'counts' => Order::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $o = Order::query()->with(['items', 'events.user:id,name', 'payments', 'customer'])->findOrFail($id);
        $data = $o->toArray();
        $data['items'] = $o->items->map(fn ($i) => $i->toArray() + ['image_url' => Present::url($i->image_path)]);
        $data['allowed_statuses'] = OrderService::TRANSITIONS[$o->status] ?? [];
        $data['customer_orders'] = $o->customer_id ? Order::query()->where('customer_id', $o->customer_id)->count() : 1;
        $data['can_refund'] = $o->payment_status === 'paid' && $o->payments->contains(fn ($p) => $p->provider === 'stripe' && $p->status === 'paid');

        return response()->json($data);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['status' => ['required', Rule::in(Order::STATUSES)], 'note' => 'nullable|string|max:1000', 'notify' => 'nullable|boolean']);
        $o = $this->orders->changeStatus(Order::findOrFail($id), $v['status'], $request->user(), $v['note'] ?? null, $v['notify'] ?? true);

        return $this->show($o->id);
    }

    public function tracking(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['carrier' => 'nullable|string|max:100', 'tracking_number' => 'nullable|string|max:100', 'tracking_url' => 'nullable|url:https,http|max:500']);
        $o = Order::findOrFail($id);
        $o->fill($v)->save();
        $this->orders->event($o, 'tracking', null, $v['tracking_number'] ?? null, 'Tracking: '.trim(($v['carrier'] ?? '').' '.($v['tracking_number'] ?? '')), false, $request->user());

        return $this->show($o->id);
    }

    public function note(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['message' => 'required|string|max:2000', 'customer_visible' => 'nullable|boolean']);
        $o = Order::findOrFail($id);
        $this->orders->event($o, 'note', null, null, strip_tags($v['message']), (bool) ($v['customer_visible'] ?? false), $request->user());

        return $this->show($o->id);
    }

    /** Full refund through Stripe (admin role only). The webhook confirms it as well. */
    public function refund(Request $request, int $id, StripeGateway $stripe): JsonResponse
    {
        $o = Order::query()->with('payments')->findOrFail($id);
        $p = $o->payments->first(fn ($x) => $x->provider === 'stripe' && $x->status === 'paid' && $x->provider_reference);
        abort_unless($o->payment_status === 'paid' && $p, 422, 'Only paid card orders can be refunded here.');
        try {
            $stripe->refund($p->provider_reference);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Stripe refused the refund: '.$e->getMessage()], 502);
        }
        $this->orders->markRefunded($o, (float) $o->total, $request->user());

        return $this->show($o->id);
    }

    /** CSV export of the current filter (opens in Excel). */
    public function export(Request $request): StreamedResponse
    {
        $q = $this->filtered($this->filters($request))->with('items');

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Order', 'Date', 'Status', 'Payment', 'Payment status', 'Customer', 'Email', 'Phone', 'Country', 'City', 'Address', 'Items', 'Subtotal', 'Discount', 'Shipping', 'Fee', 'Total', 'Currency', 'Tracking']);
            $safe = fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
            $q->chunk(200, function ($orders) use ($out, $safe) {
                foreach ($orders as $o) {
                    fputcsv($out, array_map($safe, [
                        $o->number, $o->created_at?->format('Y-m-d H:i'), $o->status, $o->payment_method, $o->payment_status,
                        $o->customer_name, $o->customer_email, $o->customer_phone, $o->ship_country, $o->ship_city, $o->ship_address,
                        $o->items->map(fn ($i) => $i->quantity.'x '.$i->product_name.($i->variant_name ? ' '.$i->variant_name : ''))->implode('; '),
                        $o->subtotal, $o->discount_total, $o->shipping_total, $o->payment_fee, $o->total, $o->currency, $o->tracking_number,
                    ]));
                }
            });
            fclose($out);
        }, 'orders-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
