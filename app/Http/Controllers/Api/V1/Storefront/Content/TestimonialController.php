<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Content;

use App\Domain\Content\Services\PublishedTestimonials;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StorefrontTestimonialResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Published testimonials in display order. Cache tag `content:testimonials`. */
final class TestimonialController extends ApiController
{
    #[QueryParameter('limit', 'How many (1–24).', type: 'int', default: 12)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function index(Request $request, PublishedTestimonials $testimonials): AnonymousResourceCollection
    {
        return StorefrontTestimonialResource::collection($testimonials->list($request->integer('limit', 12)));
    }
}
