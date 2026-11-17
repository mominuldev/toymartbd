<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\GenerateVariantBarcodes;
use App\Domain\Catalog\Enums\LabelTemplate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Product\PrintLabelsRequest;
use App\Http\Resources\Admin\LabelTemplateResource;
use App\Support\Locale\Formatter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Barcode labels (owner request 2026-11-16): one synchronous PDF, capped by the request
 * (≤ 300 variants, ≤ 5,000 labels). Each label can carry the store name, the product name
 * (EN or BN, falling back to English), the variant's options, the selling price formatted by
 * the server (`৳1,250`), the barcode (EAN-13 when it is a valid one, else Code 128) with or
 * without its digits, and the SKU. What shows follows the request, else the saved Barcode
 * labels settings (Settings › Invoices, receipts & labels).
 */
final class ProductLabelController extends ApiController
{
    /** `GET /products/labels/templates` — the label stocks with their size in mm; needs catalog.view. */
    public function templates(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        return LabelTemplateResource::collection(LabelTemplate::cases());
    }

    /**
     * `POST /products/labels` — the labels as `application/pdf`, in the order of `items`, each
     * repeated `copies` times. Needs catalog.view; `generate_missing: true` also needs
     * catalog.update and first gives the listed variants without a barcode an in-store EAN-13.
     * Omitted fields (template too) take the saved Barcode labels settings.
     */
    #[OpenApiResponse(200, 'The labels PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    #[OpenApiResponse(403, 'Missing catalog.view, or `generate_missing` without catalog.update (`forbidden`)')]
    #[OpenApiResponse(422, 'A variant has no barcode (`items.N.variant_id`), or a cap is exceeded (`validation-error`)')]
    public function print(PrintLabelsRequest $request, ProductLabelPdf $pdf, GenerateVariantBarcodes $generate, CompanyDetails $company): Response
    {
        $items = $request->items();
        $ids = array_column($items, 'variant_id');

        if ($request->generateMissing()) {
            $missing = ProductVariant::query()->whereKey($ids)->whereNull('barcode')->get(['id', 'product_id']);
            if ($missing->isNotEmpty()) {
                $generate->handle(array_values(array_unique($missing->pluck('product_id')->all())), $missing->modelKeys());
            }
        }

        $variants = ProductVariant::query()->with('product')->whereKey($ids)->get()->keyBy('id');
        $locale = $request->labelLocale();
        $labels = [];

        foreach ($items as $item) {
            $variant = $variants->get($item['variant_id']);
            if (! $variant instanceof ProductVariant || $variant->barcode === null) {
                continue;
            }

            $label = [
                'name' => $locale === 'bn' ? ($variant->product->name_bn ?? $variant->product->name) : $variant->product->name,
                'variant' => $locale === 'bn' ? ($variant->name_bn ?? $variant->name) : $variant->name,
                'price' => Formatter::money($variant->price, $locale),
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
            ];
            for ($i = 0; $i < $item['copies']; $i++) {
                $labels[] = $label;
            }
        }

        $store = null;
        if ($request->showStore()) {
            $field = $locale === 'bn' ? 'name_bn' : 'name_en';
            $saved = $company->saved()[$field];
            $store = is_string($saved) ? $saved : CompanyDetails::DEFAULT_NAMES[$field];
        }

        $bytes = $pdf->render($request->template(), $labels, $locale, $request->showPrice(), $request->showName(), $request->showOptions(), $store, $request->showDigits());

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="labels-'.now()->format('Ymd-His').'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
