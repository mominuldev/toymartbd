<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\ProductImport;

use App\Domain\Catalog\Import\Enums\SpreadsheetFormat;
use App\Domain\Catalog\Import\Models\ProductImport;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Http\UploadedFile;

/**
 * `POST /product-imports` — a CSV (UTF-8, with or without BOM) or XLSX file of up to
 * 10 MB, in the columns of a product export. Needs catalog.import. The file is only
 * stored here; its rows are checked by the queued preview.
 */
final class StoreProductImportRequest extends ApiFormRequest
{
    public const int MAX_KILOBYTES = 10240;

    public function authorize(): bool
    {
        return $this->user()?->can('create', ProductImport::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A `.csv` or `.xlsx` file. */
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx'],
        ];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('file');
        assert($file instanceof UploadedFile);

        return $file;
    }

    public function spreadsheetFormat(): SpreadsheetFormat
    {
        return strtolower($this->upload()->getClientOriginalExtension()) === 'xlsx' ? SpreadsheetFormat::Xlsx : SpreadsheetFormat::Csv;
    }
}
