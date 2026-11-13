<?php

declare(strict_types=1);

namespace App\Domain\Seo\Subjects;

/** Path helpers shared by the subject providers. */
final class SubjectPaths
{
    /**
     * The slugs of the paths directly under `$prefix` (`/products/` + `x` → `x`).
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function slugsUnder(string $prefix, array $paths): array
    {
        if ($prefix === '') {
            return [];
        }

        $slugs = [];
        foreach ($paths as $path) {
            if (str_starts_with($path, $prefix)) {
                $slug = substr($path, strlen($prefix));
                if ($slug !== '' && ! str_contains($slug, '/')) {
                    $slugs[] = $slug;
                }
            }
        }

        return array_values(array_unique($slugs));
    }
}
