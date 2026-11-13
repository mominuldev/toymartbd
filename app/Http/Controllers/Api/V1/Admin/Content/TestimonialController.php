<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Domain\Content\Actions\DeleteTestimonial;
use App\Domain\Content\Actions\ReorderTestimonials;
use App\Domain\Content\Actions\SaveTestimonial;
use App\Domain\Content\Models\Testimonial;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Content\ReorderTestimonialsRequest;
use App\Http\Requests\Admin\Content\TestimonialRequest;
use App\Http\Resources\Admin\TestimonialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Testimonials (Online store → Testimonials), read by the homepage testimonials block. */
final class TestimonialController extends ApiController
{
    public const int MAX = 500;

    /** `GET /content/testimonials` — in display order; needs content.view. */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Testimonial::class);

        return TestimonialResource::collection(Testimonial::query()->with('media')->orderBy('position')->orderBy('id')->limit(self::MAX)->get());
    }

    /** `POST /content/testimonials` — appended at the end; needs content.manage. */
    public function store(TestimonialRequest $request, SaveTestimonial $save): JsonResponse
    {
        return (new TestimonialResource($save->handle(null, $request->toData())->load('media')))->response()->setStatusCode(201);
    }

    /** `GET /content/testimonials/{testimonial}` — needs content.view. */
    public function show(Testimonial $testimonial): TestimonialResource
    {
        $this->authorize('view', $testimonial);

        return new TestimonialResource($testimonial->load('media'));
    }

    /** `PUT /content/testimonials/{testimonial}` — needs content.manage. */
    public function update(TestimonialRequest $request, Testimonial $testimonial, SaveTestimonial $save): TestimonialResource
    {
        return new TestimonialResource($save->handle($testimonial, $request->toData())->load('media'));
    }

    /** `DELETE /content/testimonials/{testimonial}` — needs content.manage. */
    public function destroy(Testimonial $testimonial, DeleteTestimonial $delete): Response
    {
        $this->authorize('delete', $testimonial);
        $delete->handle($testimonial);

        return response()->noContent();
    }

    /** `POST /content/testimonials/reorder` — every id once, in the new order; needs content.manage. */
    public function reorder(ReorderTestimonialsRequest $request, ReorderTestimonials $reorder): AnonymousResourceCollection
    {
        $reorder->handle($request->ids());

        return $this->index();
    }
}
