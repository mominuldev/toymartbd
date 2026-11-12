<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\ProductWriteContext;
use App\Domain\Catalog\DTOs\VariantData;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Writes a whole product document — fields, categories, variant matrix and curated
 * relations — for CreateProduct and UpdateProduct. No transaction of its own; the
 * Action owns it (rule 3), so a rule that fails after the first write rolls
 * everything back.
 */
final readonly class ProductWriter
{
    public function __construct(
        private ProductDocumentValidator $validator,
        private ProductSlugs $slugs,
        private VariantMatrix $matrix,
        private ProductRelationsWriter $relations,
    ) {}

    /**
     * @return bool whether anything changed
     *
     * @throws ValidationException
     * @throws AuthorizationException when the document needs an ability the context lacks
     */
    public function write(Product $product, ProductData $data, ProductWriteContext $context, bool $isNew): bool
    {
        if (! $context->canSetCost && self::setsCost($data)) {
            throw new AuthorizationException('Setting a cost price needs the catalog.view_cost permission.');
        }

        $options = $this->validator->validate($data, $product->exists ? $product : null);

        $product->fill([
            'name' => $data->name,
            'name_bn' => $data->nameBn,
            'type' => $data->type,
            'brand_id' => $data->brandId,
            'primary_category_id' => $data->primaryCategoryId,
            'short_description' => $data->shortDescription,
            'short_description_bn' => $data->shortDescriptionBn,
            'description' => ProductDescription::sanitize($data->description),
            'description_bn' => ProductDescription::sanitize($data->descriptionBn),
            'specifications' => $data->specifications,
            'age_min_months' => $data->ageMinMonths,
            'age_max_months' => $data->ageMaxMonths,
            'gender' => $data->gender,
            'is_educational' => $data->isEducational,
            'safety_note' => $data->safetyNote,
            'safety_note_bn' => $data->safetyNoteBn,
            'warranty_months' => $data->warrantyMonths,
            'status' => $data->status,
            'is_featured' => $data->isFeatured,
        ]);

        if ($data->salesChannels !== null) {
            $product->fill([
                'channel_online_store' => $data->salesChannels->onlineStore,
                'channel_pos' => $data->salesChannels->pos,
                'channel_social' => $data->salesChannels->facebookInstagram,
                'channel_google_merchant' => $data->salesChannels->googleMerchant,
            ]);
        }

        if ($data->vatRate !== null) {
            $product->vat_rate = $data->vatRate;
        }

        if ($data->compliance !== null) {
            $product->bsti_certificate_no = $data->compliance->bstiCertificateNo;
            $product->bsti_expires_on = $data->compliance->bstiExpiresOn === null ? null : Carbon::instance($data->compliance->bstiExpiresOn)->startOfDay();
        }

        if ($data->slug !== null) {
            $product->slug = $data->slug;
        } elseif (! $product->exists) {
            $product->slug = $this->slugs->generate($data->name, $data->nameBn);
        }

        if ($data->publishedAt !== null) {
            $product->published_at = Carbon::instance($data->publishedAt);
        } elseif ($data->status === ProductStatus::Active && $product->published_at === null) {
            $product->published_at = now();
        }

        $changed = $product->isDirty() || ! $product->exists;
        $product->save();

        $synced = $product->categories()->sync(array_values(array_unique($data->categoryIds)));
        $changed = $changed || array_filter($synced) !== [];

        $changed = $this->matrix->sync($product, $data, $options, $context->actorId, $isNew || $context->canChangePrices) || $changed;

        if ($data->relations !== null) {
            $changed = $this->relations->sync($product, $data->relations, $context->actorId) || $changed;
        }

        return $changed;
    }

    private static function setsCost(ProductData $data): bool
    {
        return $data->variantDefaults?->costPrice !== null
            || array_filter($data->variants, static fn (VariantData $row): bool => $row->costPrice !== null) !== [];
    }
}
