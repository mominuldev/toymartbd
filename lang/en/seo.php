<?php

declare(strict_types=1);

/*
| SEO module (docs/api/seo.md): server default descriptions for pages without their own
| copy, and the redirect / robots.txt validation messages.
*/
return [
    'site_name' => 'Toy Mart BD',
    'default_description' => [
        'category' => 'Shop :name toys online at Toy Mart BD. Genuine products, cash on delivery across Bangladesh.',
        'brand' => 'Shop genuine :name toys at Toy Mart BD. Cash on delivery across Bangladesh.',
        'page' => ':name — Toy Mart BD.',
        'blog_post' => ':name — the Toy Mart BD toy guide.',
        'blog_category' => ':name — articles and buying guides from Toy Mart BD.',
        'blog_tag' => 'Articles about :name from the Toy Mart BD toy guide.',
        'blog_author' => 'Articles by :name in the Toy Mart BD toy guide.',
        'product' => 'Buy :name online at Toy Mart BD. Cash on delivery across Bangladesh.',
    ],
    'redirect' => [
        'from_invalid' => 'The source must be a path starting with / (no query string or fragment).',
        'to_invalid' => 'The target must be a path starting with / or an https:// URL.',
        'same' => 'A redirect cannot point to itself.',
        'live' => ':path is a live page. Change or archive that page first; a redirect would hide it.',
        'reserved' => ':path is a built-in storefront route and cannot be redirected.',
        'loop' => 'This redirect would create a loop: :chain.',
        'duplicate' => 'A redirect from :path already exists.',
        'csv_header' => 'The first row must be the header from_path,to_path,status_code[,note].',
        'csv_empty' => 'The file has no redirect rows.',
        'csv_too_many' => 'At most :max rows per import.',
        'csv_row' => 'Row :line: :message',
        'csv_status' => 'status_code must be 301 or 302.',
    ],
    'robots' => [
        'unknown_directive' => 'Line :line: ":directive" is not a supported directive (User-agent, Allow, Disallow, Sitemap, Crawl-delay).',
        'malformed' => 'Line :line: expected "Directive: value".',
        'rule_before_agent' => 'Line :line: a rule must follow a User-agent line.',
        'bad_path' => 'Line :line: a rule path must be empty or start with / or *.',
        'bad_sitemap' => 'Line :line: Sitemap must be an absolute https:// or http:// URL.',
        'bad_delay' => 'Line :line: Crawl-delay must be a number of seconds.',
        'blocks_everything' => 'Line :line: "Disallow: /" for every crawler would remove the whole shop from search.',
    ],
];
