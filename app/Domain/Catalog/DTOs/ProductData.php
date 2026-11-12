<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\ProductType;
use DateTimeInterface;

/**
 * Input for CreateProduct and UpdateProduct: the whole product document, variant
 * matrix included (an update replaces the document; it is not a partial patch).
 *
 * - `slug` null: generate one on create, keep the current one on update.
 * - `options`: for a variable product, the selected values of each variant-defining
 *   attribute; the variant matrix is their cartesian product. Empty for simple.
 * - `variants`: per-row overrides. Simple: at most one row (the default variant).
 *   Variable: rows are matched to matrix combinations by their attribute values; a
 *   combination with no row takes `variantDefaults`.
 * - `relations` null: leave hand-curated relations as they are.
 * - `publishedAt` null: keep the current value, or stamp "now" on first activation.
 * - `salesChannels`, `vatRate`, `compliance` null: keep the current values (the
 *   column defaults on create), so older callers that do not send them change nothing.
 */
final readonly class ProductData
{
    /**
     * @param  list<int>  $categoryIds
     * @param  array<string, mixed>|null  $specifications
     * @param  list<VariantOptionData>  $options
     * @param  list<VariantData>  $variants
     * @param  list<RelationData>|null  $relations
     */
    public function __construct(
        public string $name,
        public ProductType $type,
        public int $primaryCategoryId,
        public array $categoryIds,
        public ?string $nameBn = null,
        public ?string $slug = null,
        public ?int $brandId = null,
        public ?string $shortDescription = null,
        public ?string $shortDescriptionBn = null,
        public ?string $description = null,
        public ?string $descriptionBn = null,
        public ?array $specifications = null,
        public ?int $ageMinMonths = null,
        public ?int $ageMaxMonths = null,
        public ?Gender $gender = null,
        public bool $isEducational = false,
        public ?string $safetyNote = null,
        public ?string $safetyNoteBn = null,
        public ?int $warrantyMonths = null,
        public ProductStatus $status = ProductStatus::Draft,
        public bool $isFeatured = false,
        public ?DateTimeInterface $publishedAt = null,
        public array $options = [],
        public array $variants = [],
        public ?VariantDefaultsData $variantDefaults = null,
        public ?array $relations = null,
        public ?SalesChannelsData $salesChannels = null,
        public ?string $vatRate = null,
        public ?ComplianceData $compliance = null,
    ) {}
}
