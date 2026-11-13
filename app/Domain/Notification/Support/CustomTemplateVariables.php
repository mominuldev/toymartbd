<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

/**
 * The safe, generic variables a staff-made (marketing / manual) template may use. A
 * custom template picks a subset when it is created; its body may name nothing else.
 * Every one of these is something the sender can always fill for any customer — no
 * money, no stock, nothing computed (rule 1). Sample values are the catalog's, so a
 * custom body is measured exactly like a system one.
 */
final class CustomTemplateVariables
{
    public const array ALL = ['name', 'order_number', 'store_name', 'hotline', 'store_url', 'track_url'];

    /** @return array<string, string> */
    public static function sampleValues(string $locale): array
    {
        return [
            ...TemplateCatalog::sampleValues($locale),
            'store_name' => $locale === 'bn' ? 'টয় মার্ট বিডি' : 'Toy Mart BD',
        ];
    }
}
