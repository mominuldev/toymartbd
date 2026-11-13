<?php

declare(strict_types=1);

/*
| SEO presentation shared by the API and the storefront (docs/api/seo.md).
|
| `title_separator` joins a page name and the site name in every <title>: the server's default
| titles (SEO panel, storefront SEO documents) use it, and the storefront reads the same value
| from `GET /api/v1/storefront/seo/settings` for its layout title template — ONE value, so a
| change here changes both and nothing ever carries two suffixes.
|
| OWNER DECISION OPEN (IMPLEMENTATION-PLAN Phase 7 "Owner decisions open"): " | " (what the
| server used) or " · " (what the storefront template used). Answering it is this one line.
*/

return [
    'site_name' => 'Toy Mart BD',

    'title_separator' => ' | ',
];
