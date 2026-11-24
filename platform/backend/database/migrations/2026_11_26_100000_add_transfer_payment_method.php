<?php

use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;

/** "Pay after confirmation" (UPI / Google Pay / bank transfer) for Fixnote India and Saudi orders. */
return new class extends Migration
{
    public function up(): void
    {
        PaymentMethod::query()->firstOrCreate(['code' => 'transfer'], PaymentMethod::TRANSFER_DEFAULTS);
    }

    public function down(): void
    {
        // Kept: orders may use it.
    }
};
