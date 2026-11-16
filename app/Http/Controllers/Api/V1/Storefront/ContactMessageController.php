<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Inbox\Actions\ReceiveContactFormMessage;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\ContactMessageRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The Contact page's "Send us a message" form (owner decision 2026-11-16, docs/api/inbox.md).
 * Called only by the Next.js server with its storefront token, forwarding the shopper's IP.
 */
final class ContactMessageController extends ApiController
{
    /**
     * `POST /contact-messages` — opens or extends the sender's `web_form` conversation in the
     * admin Inbox (one thread per phone). Answers 202 `{status: "received"}`. Limited to 5 an
     * hour per phone and 10 an hour per client IP.
     *
     * @status 202
     *
     * @response array{status: 'received'}
     */
    #[OpenApiResponse(422, 'Invalid name, phone (rule 30), topic, order number or message (`validation-error`)')]
    #[OpenApiResponse(429, 'Too many messages from this phone or client (`too-many-requests`)')]
    public function store(ContactMessageRequest $request, ReceiveContactFormMessage $receive): JsonResponse
    {
        $receive->handle(
            $request->name(),
            $request->phoneE164(),
            $request->topic(),
            $request->orderNumber(),
            $request->message(),
        );

        return response()->json(['status' => 'received'], 202);
    }
}
