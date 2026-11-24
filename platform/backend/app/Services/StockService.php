<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All stock changes go through here: row locks (SELECT ... FOR UPDATE) keep concurrent orders consistent,
 * and every change is written to stock_movements.
 */
class StockService
{
    /**
     * Reserve stock for an order's items. Must run inside a DB transaction.
     * Throws ValidationException (422) if a size is no longer available in the requested quantity.
     *
     * @param  array<int,int>  $quantities  variant_id => quantity
     * @return array<int,ProductVariant> locked variants keyed by id
     */
    public function lockAndCheck(array $quantities): array
    {
        $variants = ProductVariant::query()
            ->whereIn('id', array_keys($quantities))
            ->with('product')
            ->orderBy('id') // consistent lock order avoids deadlocks
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $errors = [];
        foreach ($quantities as $id => $qty) {
            $v = $variants->get($id);
            $label = $v ? trim($v->product->name.' '.($v->name !== 'Standard' ? $v->name : '')) : 'An item';
            if (! $v || ! $v->is_active || ! $v->product || ! $v->product->is_active || $v->product->trashed()) {
                $errors["items.$id"] = "$label is no longer available.";
            } elseif (! $v->hasPrice()) {
                $errors["items.$id"] = "$label has no price yet and cannot be ordered online.";
            } elseif (! $v->inStock()) {
                $errors["items.$id"] = "$label is out of stock.";
            } elseif (($avail = $v->availableQuantity()) !== null && $qty > $avail) {
                $errors["items.$id"] = "Only $avail of $label left in stock.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $variants->all();
    }

    /** Deduct stock for a new order (inside the same transaction as lockAndCheck). */
    public function reserveForOrder(Order $order, array $variants, array $quantities): void
    {
        foreach ($quantities as $id => $qty) {
            $v = $variants[$id];
            if ($v->track_stock) {
                $v->stock_quantity -= $qty;
                $v->save();
                $this->log($v, -$qty, 'order', $order->id, null, 'Order '.$order->number);
            }
            $v->product->increment('total_sales', $qty);
        }
        $order->forceFill(['stock_reserved_at' => now()])->save();
    }

    /** Put an order's stock back (cancelled, returned, card payment failed / expired). Idempotent. */
    public function releaseForOrder(Order $order, string $reason, ?int $userId = null): bool
    {
        return DB::transaction(function () use ($order, $reason, $userId) {
            $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $fresh || ! $fresh->holdsStock()) {
                return false;
            }
            $items = $fresh->items()->get();
            $ids = $items->pluck('variant_id')->filter()->unique()->all();
            $variants = ProductVariant::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $v = $variants->get($item->variant_id);
                if (! $v) {
                    continue;
                }
                if ($v->track_stock) {
                    $v->stock_quantity += $item->quantity;
                    $v->save();
                    $this->log($v, $item->quantity, $reason, $fresh->id, $userId, 'Order '.$fresh->number);
                }
                if ($v->product) {
                    $v->product->forceFill(['total_sales' => max(0, $v->product->total_sales - $item->quantity)])->save();
                }
            }
            $fresh->forceFill(['stock_released_at' => now()])->save();
            $order->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    /** Manual adjustment by staff: either set an exact quantity or add/remove an amount. */
    public function adjust(ProductVariant $variant, ?int $setTo, ?int $change, ?string $note, ?int $userId): ProductVariant
    {
        return DB::transaction(function () use ($variant, $setTo, $change, $note, $userId) {
            $v = ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $before = $v->stock_quantity;
            $after = $setTo !== null ? $setTo : $before + (int) $change;
            if ($after < 0) {
                throw ValidationException::withMessages(['quantity' => 'Stock cannot go below 0 (currently '.$before.').']);
            }
            $v->track_stock = true;
            $v->stock_quantity = $after;
            $v->save();
            if ($after !== $before) {
                $this->log($v, $after - $before, 'manual', null, $userId, $note);
            }

            return $v;
        });
    }

    public function log(ProductVariant $v, int $change, string $reason, ?int $orderId, ?int $userId, ?string $note): void
    {
        StockMovement::create([
            'variant_id' => $v->id,
            'order_id' => $orderId,
            'user_id' => $userId,
            'change' => $change,
            'quantity_after' => $v->stock_quantity,
            'reason' => $reason,
            'note' => $note ? mb_substr($note, 0, 500) : null,
        ]);
    }
}
