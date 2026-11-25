<?php

declare(strict_types=1);

// The admin shell links the Smart Junior icon, and every file it links exists and is a real image.
// public/favicon.ico shipped as Laravel's empty placeholder before, so the admin tab had no icon.

it('links the store icon from the admin shell, and the linked files are real images', function (): void {
    $this->withoutVite();

    $html = (string) $this->get('/admin')->assertOk()->getContent();

    foreach (['/favicon.ico', '/favicon-32.png', '/apple-touch-icon.png'] as $path) {
        expect($html)->toContain("href=\"{$path}\"");
        $file = public_path(ltrim($path, '/'));
        expect(is_file($file))->toBeTrue()
            ->and(filesize($file))->toBeGreaterThan(100);
    }

    expect(getimagesize(public_path('favicon-32.png')))->toMatchArray([0 => 32, 1 => 32])
        ->and(getimagesize(public_path('apple-touch-icon.png')))->toMatchArray([0 => 180, 1 => 180]);
});
