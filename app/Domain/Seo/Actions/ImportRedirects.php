<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Seo\DTOs\RedirectData;
use App\Domain\Seo\Models\Redirect;
use App\Domain\Seo\Services\PathNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Bulk import from CSV `from_path,to_path,status_code[,note]` (Redirect manager "Import").
 * All or nothing: every row runs through {@see SaveRedirect} (same conflict, loop and
 * chain rules) inside one transaction; any row error rolls the whole file back and the
 * errors come back per line. An existing `from_path` is updated.
 *
 * @phpstan-type ImportResult array{created: int, updated: int, errors: list<array{line: int, field: string, message: string}>}
 */
final readonly class ImportRedirects
{
    public const int MAX_ROWS = 2000;

    public function __construct(private SaveRedirect $save) {}

    /** @return ImportResult */
    public function handle(string $csvPath, ?int $actorId = null): array
    {
        $rows = $this->parse($csvPath);
        $created = 0;
        $updated = 0;
        $errors = [];

        try {
            DB::transaction(function () use ($rows, $actorId, &$created, &$updated, &$errors): void {
                foreach ($rows as [$line, $data]) {
                    try {
                        $existing = Redirect::query()->where('from_path', PathNormalizer::path($data->fromPath) ?? "\0")->first();
                        $this->save->handle($existing, $data, $actorId, Redirect::SOURCE_IMPORT);
                        $existing === null ? $created++ : $updated++;
                    } catch (ValidationException $e) {
                        foreach ($e->errors() as $field => $messages) {
                            $errors[] = ['line' => $line, 'field' => $field, 'message' => (string) ($messages[0] ?? '')];
                        }
                    }
                }

                if ($errors !== []) {
                    throw new RuntimeException('rollback');
                }
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'rollback') {
                throw $e;
            }

            return ['created' => 0, 'updated' => 0, 'errors' => $errors];
        }

        return ['created' => $created, 'updated' => $updated, 'errors' => []];
    }

    /** @return list<array{0: int, 1: RedirectData}> */
    private function parse(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => (string) __('seo.redirect.csv_empty')]);
        }

        $header = fgetcsv($handle, escape: '');
        $header = is_array($header) ? array_map(static fn (mixed $h): string => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header) : [];
        if (array_slice($header, 0, 3) !== ['from_path', 'to_path', 'status_code']) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => (string) __('seo.redirect.csv_header')]);
        }

        $rows = [];
        $line = 1;
        $errors = [];
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map(strval(...), $cells)) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => (string) __('seo.redirect.csv_too_many', ['max' => self::MAX_ROWS])]);
            }
            $status = trim((string) ($cells[2] ?? ''));
            if (! in_array($status, ['301', '302'], true)) {
                $errors[] = (string) __('seo.redirect.csv_row', ['line' => $line, 'message' => __('seo.redirect.csv_status')]);

                continue;
            }
            $note = trim((string) ($cells[3] ?? ''));
            $rows[] = [$line, new RedirectData(
                fromPath: (string) ($cells[0] ?? ''),
                toPath: (string) ($cells[1] ?? ''),
                statusCode: (int) $status,
                isActive: true,
                note: $note === '' ? null : mb_substr($note, 0, 500),
            )];
        }
        fclose($handle);

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => (string) __('seo.redirect.csv_empty')]);
        }

        return $rows;
    }
}
