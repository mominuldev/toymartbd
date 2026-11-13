<?php

declare(strict_types=1);

namespace App\Console\Commands\Content;

use App\Domain\Content\Blog\Actions\PublishDueBlogPosts;
use Illuminate\Console\Command;

/** The scheduled-publish sweep for blog posts (scheduled every minute in BlogServiceProvider). */
final class PublishDueBlogPostsCommand extends Command
{
    protected $signature = 'toymart:blog:publish-due';

    protected $description = 'Publish scheduled blog posts whose time has come and purge the storefront cache';

    public function handle(PublishDueBlogPosts $publish): int
    {
        $ids = $publish->handle();
        $this->info('Published '.count($ids).' scheduled post(s).');

        return self::SUCCESS;
    }
}
