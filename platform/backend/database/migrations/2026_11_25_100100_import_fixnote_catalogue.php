<?php

use App\Models\Brand;
use App\Services\Fixnote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Adds the Fixnote catalogue (database/data/fixnote-catalogue.json) to shops that are already installed,
 * so `platform.sh update` brings the perfumes in. Fresh installs have no brands yet at this point;
 * the installer runs `shop:import-fixnote` after seeding instead. Existing perfumes (same code) are never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Settings cached by the previous version do not have the Fixnote prices yet.
        Cache::forget('settings.all');
        if (Brand::query()->where('slug', 'fixnote')->exists()) {
            app(Fixnote::class)->import();
        }
    }

    public function down(): void
    {
        // Products are kept: they may have orders by now.
    }
};
