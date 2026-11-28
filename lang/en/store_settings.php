<?php

declare(strict_types=1);

// Store settings › Business, Site & branding and Branches & warehouses (Figma B · 38).
return [
    'whatsapp_invalid' => 'Enter a Bangladeshi mobile number (013–019), e.g. 01711-000222.',
    'messenger_invalid' => 'Enter the Facebook Page\'s username (letters, digits and dots, 5–50 characters) or its numeric Page id, as in m.me/toymartbd.',
    'upazila_mismatch' => 'This upazila is not in the chosen district.',
    'location_conflict_default' => 'The default location cannot be switched off: sales, opening stock and the store read from it.',
    'branch_mode_blocked' => '{1} Switch the other active branch off before running the store as a single shop.|[2,*] Switch the :count other active branches off before running the store as a single shop.',
    'branch_mode_single' => 'The store runs as a single shop. Turn on multiple branches under Branch setup before adding or switching on another location.',
    'location_conflict_stock' => 'This location still holds, reserves or expects stock. Transfer it out before switching the location off.',
    'district_metro_and_suburb' => 'A district cannot be both metro and suburb.',
    'cod_advance_not_below_threshold' => 'The advance must be less than the threshold.',
    'branding_media_missing' => 'Choose an image from the media library — this one no longer exists.',
    'branding_media_not_image' => 'Choose an image (PNG, JPG, WebP or AVIF), not a document.',
    'branding_media_svg' => 'Branding images are used for favicons, invoices, emails and social previews, which cannot show SVG. Choose a PNG, JPG, WebP or AVIF image.',
    'branding_media_not_ready' => 'This image is still processing. Wait until it is ready, then save again.',
    'branding_icon_not_square' => 'The store icon must be square (the same width and height).',
    'branding_icon_too_small' => 'The store icon must be at least 512 × 512 px.',
    'branding_share_too_small' => 'The social share image must be at least 1200 px wide.',
    'branding_share_wrong_ratio' => 'The social share image must be 1.91:1, e.g. 1200 × 630 px.',
];
