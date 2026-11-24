<?php

use App\Models\Brand;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;

/**
 * CI only: fires 12 checkouts at the same moment for a size with 3 bottles left (real MySQL/MariaDB, parallel PHP workers).
 * Exactly 3 must succeed, stock must end at 0 and never go negative.
 * Usage: php tests/ci/concurrency.php http://127.0.0.1:8099
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$p = Product::create(['name' => 'Race Test', 'slug' => 'race-'.uniqid(), 'is_active' => true, 'brand_id' => Brand::query()->value('id')]);
$v = $p->variants()->create(['name' => '100 ml', 'price' => 100, 'track_stock' => true, 'stock_quantity' => 3]);

$mh = curl_multi_init();
$handles = [];
for ($i = 0; $i < 12; $i++) {
    $ch = curl_init($base.'/api/checkout');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Forwarded-For: 10.0.0.'.$i],
        CURLOPT_POSTFIELDS => json_encode([
            'items' => [['variant_id' => $v->id, 'quantity' => 1]], 'name' => "Buyer $i", 'email' => "buyer$i@example.test",
            'phone' => '+971500000000', 'country' => 'AE', 'city' => 'Dubai', 'address' => 'Street 1, Dubai', 'payment_method' => 'cod', 'accept_terms' => true,
        ]),
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
$codes = array_map(fn ($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles);
$counts = array_count_values($codes);
$stock = $v->fresh()->stock_quantity;
$orders = OrderItem::query()->where('variant_id', $v->id)->sum('quantity');
echo 'Responses: '.json_encode($counts).", stock left: $stock, units ordered: $orders\n";
if (($counts[201] ?? 0) !== 3 || $stock !== 0 || (int) $orders !== 3) {
    fwrite(STDERR, "FAIL: oversold or lost orders\n");
    foreach ($handles as $ch) {
        if (! in_array(curl_getinfo($ch, CURLINFO_RESPONSE_CODE), [201, 422], true)) {
            fwrite(STDERR, substr((string) curl_multi_getcontent($ch), 0, 300)."\n");
        }
    }
    exit(1);
}
echo "OK: no overselling under concurrent checkouts\n";
