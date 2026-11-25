<?php

declare(strict_types=1);

/*
 * Write-time guardrails on a product's curated recommendations (ProductRelationsWriter, rule 25c).
 * Shown on the relation's row in the admin product form. Western digits in both locales (rule 36).
 */
return [
    'self' => 'A product cannot be recommended on its own page.',
    'duplicate' => 'This product is already listed with the same recommendation type.',
    'missing' => 'The recommended product does not exist.',
    'upsell_not_dearer' => 'An upsell must cost more than this product: :name costs ৳:target, this product costs ৳:source.',
    'upsell_no_source_price' => 'Give this product an active price before adding an upsell.',
    'upsell_no_target_price' => ':name has no active price, so it cannot be an upsell.',
];
