<?php

return [
    // While set, every page and API call (except webhooks, the admin login and the Apple Pay file) asks for this key.
    // Same idea as the WordPress staging lock. Remove it from .env when the shop goes public.
    'gate_key' => env('SHOP_GATE_KEY'),

    // Stripe live keys are refused until this is true (keeps test sites from taking real money).
    'allow_live_payments' => (bool) env('PAYMENTS_ALLOW_LIVE', false),

    // React app shell (kept outside public/ so the web server cannot serve it around the gate).
    'spa_index' => resource_path('spa/index.html'),

    // Multiplies all rate limits. Leave at 1 on real sites; only the browser tests raise it.
    'rate_limit_scale' => (int) env('RATE_LIMIT_SCALE', 1),

];
