<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Inspired by" brand for Fixnote perfumes (e.g. F01 Imagination is inspired by Louis Vuitton). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('inspired_by', 100)->nullable()->after('tagline')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['inspired_by']);
            $table->dropColumn('inspired_by');
        });
    }
};
