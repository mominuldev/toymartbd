<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * `product_images.processing_status` (P4-CAT-07). `processing` from upload until the
 * queued job has written every rendition; `failed` if it could not.
 */
enum ImageProcessingStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
