<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Enums;

/** An article, or a buying guide (structured sections with product picks), served under /guides. */
enum PostType: string
{
    case Article = 'article';
    case BuyingGuide = 'buying_guide';
}
