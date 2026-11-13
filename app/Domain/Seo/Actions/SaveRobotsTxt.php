<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Services\RobotsTxt;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Save the robots.txt text (already shape-validated by the FormRequest; re-checked here). */
final class SaveRobotsTxt
{
    public function handle(string $content, ?int $actorId = null): string
    {
        $content = rtrim(str_replace("\r\n", "\n", $content))."\n";
        $errors = RobotsTxt::validate($content);
        if ($errors !== []) {
            throw ValidationException::withMessages(['content' => $errors]);
        }

        DB::transaction(static fn () => Setting::put(RobotsTxt::SETTING, $content, SettingGroup::Seo, false, $actorId));
        SeoPagesStale::dispatch(['robots']);

        return $content;
    }
}
