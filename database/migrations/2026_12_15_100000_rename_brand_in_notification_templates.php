<?php

declare(strict_types=1);

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\TemplateMeasurer;
use App\Domain\Notification\Support\TemplateVersions;
use Illuminate\Database\Migrations\Migration;

/**
 * The store is Smart Junior now (owner, 2026-11-24). Templates already in the database keep the
 * copy they were inserted with, so they still say "Toy Mart BD" to customers. This swaps only the
 * brand name — in English and in its Bangla spelling — so anything else an admin edited in a
 * template stays as it is. Each changed row keeps its previous copy as a version and is
 * re-measured (SMS segments). Rows that never mentioned the old name are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $measurer = app(TemplateMeasurer::class);

        foreach (NotificationTemplate::query()->orderBy('id')->get() as $row) {
            $changed = false;
            foreach (['subject_en', 'subject_bn', 'preheader_en', 'preheader_bn', 'body_en', 'body_bn'] as $field) {
                $value = $row->getAttribute($field);
                if (! is_string($value)) {
                    continue;
                }
                $renamed = self::rename($value);
                if ($renamed !== $value) {
                    $row->setAttribute($field, $renamed);
                    $changed = true;
                }
            }
            if (! $changed) {
                continue;
            }

            TemplateVersions::ensureBaseline(NotificationTemplate::query()->findOrFail($row->id));
            $en = $measurer->measure((string) $row->key, $row->channel, 'en', (string) $row->body_en);
            $bn = $measurer->measure((string) $row->key, $row->channel, 'bn', (string) $row->body_bn);
            $row->fill([
                'chars_en' => $en['chars'], 'chars_bn' => $bn['chars'],
                'segments_en' => $en['segments'], 'segments_bn' => $bn['segments'],
                'encoding_en' => $en['encoding'], 'encoding_bn' => $bn['encoding'],
            ]);
            $row->save();
            TemplateVersions::append($row, null);
        }
    }

    public function down(): void
    {
        // Templates are data an admin may have edited; the previous copy is kept as a version.
    }

    private static function rename(string $text): string
    {
        $ya = '(?:\x{09DF}|\x{09AF}\x{09BC})';
        $base = '\x{099F}'.$ya.' \x{09AE}\x{09BE}\x{09B0}\x{09CD}\x{099F}';  // টয় মার্ট
        $bd = ' \x{09AC}\x{09BF}\x{09A1}\x{09BF}';                             // বিডি

        return (string) preg_replace(
            ['/'.$base.$bd.'\x{09B0}/u', '/'.$base.$bd.'\x{09A4}\x{09C7}/u', '/'.$base.$bd.'/u', '/'.$base.'/u', '/Toy Mart BD/u', '/Toy Mart(?=[\s.:,!?\'’-])/u'],
            ['Smart Junior-এর', 'Smart Junior-এ', 'Smart Junior', 'Smart Junior', 'Smart Junior', 'Smart Junior'],
            $text,
        );
    }
};
