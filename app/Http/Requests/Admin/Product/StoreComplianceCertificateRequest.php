<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Exceptions\InvalidCertificate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CertificateFiles;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * `POST /products/{product}/compliance/certificate` — `multipart/form-data` with `file`:
 * the BSTI certificate as a PDF (by content, ≤ 10 MB, no scripts or attachments).
 * Replaces any previous certificate. Needs catalog.update.
 */
final class StoreComplianceCertificateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && ($this->user()?->can('update', $product) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile || ! $value->isValid()) {
                    $fail('The upload failed; try again.');

                    return;
                }

                try {
                    app(CertificateFiles::class)->inspect($value->getRealPath() ?: $value->getPathname());
                } catch (InvalidCertificate $e) {
                    $fail($e->getMessage());
                }
            }],
        ];
    }

    public function uploadedPath(): string
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? ($file->getRealPath() ?: $file->getPathname()) : '';
    }

    public function clientName(): string
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file->getClientOriginalName() : 'certificate.pdf';
    }
}
