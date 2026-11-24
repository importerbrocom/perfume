<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\Fixnote;
use Illuminate\Http\JsonResponse;

class FixnoteController extends Controller
{
    /** GET /api/fixnote: page texts, prices, region price lists and every Fixnote perfume with its sizes. */
    public function show(Fixnote $fixnote): JsonResponse
    {
        return response()->json($fixnote->catalogue(), 200, [], JSON_UNESCAPED_UNICODE)->header('Cache-Control', 'public, max-age=30');
    }
}
