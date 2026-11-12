<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Seo\Services\SerpMeasure;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Product\SeoMeasureRequest;
use Illuminate\Http\JsonResponse;

/** The SeoPanel's live meters for unsaved text; the saved state comes on `Product.seo`. */
final class ProductSeoController extends ApiController
{
    /**
     * `POST /products/seo-measure` — estimated rendered width of a meta title (20 px)
     * and description (14 px) at Google's desktop metric, with the truncation limits.
     *
     * @response array{title: array{px: int, chars: int, max: int, fits: bool}, description: array{px: int, chars: int, max: int, fits: bool, fits_mobile: bool}}
     */
    public function measure(SeoMeasureRequest $request, SerpMeasure $measure): JsonResponse
    {
        return response()->json([
            'title' => $measure->title($request->text('title')),
            'description' => $measure->description($request->text('description')),
        ]);
    }
}
