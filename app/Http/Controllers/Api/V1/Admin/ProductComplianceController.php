<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\AttachComplianceCertificate;
use App\Domain\Catalog\Actions\RemoveComplianceCertificate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\MediaUrls;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Product\StoreComplianceCertificateRequest;
use App\Http\Resources\Admin\ProductComplianceResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A product's BSTI certificate PDF. The certificate number and expiry date travel in
 * the product document; the file is uploaded here, kept private, and only ever served
 * as a download.
 */
final class ProductComplianceController extends ApiController
{
    /** `POST /products/{product}/compliance/certificate` — attach or replace; needs catalog.update. */
    #[OpenApiResponse(422, 'Not a PDF by content, over 10 MB, or carrying scripts, attachments or interactive content (`validation-error` on `file`)')]
    public function store(StoreComplianceCertificateRequest $request, Product $product, AttachComplianceCertificate $attach): ProductComplianceResource
    {
        return new ProductComplianceResource($attach->handle($product, $request->uploadedPath(), $request->clientName()));
    }

    /** `GET /products/{product}/compliance/certificate` — the PDF as an attachment; needs catalog.view. */
    #[OpenApiResponse(404, 'The product has no certificate (`not-found`)')]
    public function show(Product $product): StreamedResponse
    {
        $this->authorize('view', $product);

        $key = $product->bsti_certificate_path;
        $disk = Storage::disk(MediaUrls::originalsDisk());

        if ($key === null || ! $disk->exists($key)) {
            throw new NotFoundHttpException('This product has no certificate.');
        }

        return $disk->download($key, $product->bsti_certificate_name ?? 'certificate.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** `DELETE /products/{product}/compliance/certificate` — detach (idempotent); needs catalog.update. */
    public function destroy(Product $product, RemoveComplianceCertificate $remove): ProductComplianceResource
    {
        $this->authorize('update', $product);

        return new ProductComplianceResource($remove->handle($product));
    }
}
