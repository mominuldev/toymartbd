<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Exceptions\InvalidCertificate;
use App\Domain\Catalog\Jobs\DeleteComplianceCertificate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CertificateFiles;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Attach (or replace) a product's BSTI certificate PDF.
 *
 * 1. Outside any transaction: sniff the file ({@see CertificateFiles}) and write it to
 *    the private originals disk under a random key (rule 4 — no storage I/O inside a
 *    transaction).
 * 2. One short transaction: point the product at the new file.
 * 3. After commit: the replaced file, if any, is deleted.
 */
final readonly class AttachComplianceCertificate
{
    public function __construct(private CertificateFiles $files) {}

    /** @throws ValidationException */
    public function handle(Product $product, string $uploadedPath, string $clientName): Product
    {
        try {
            $size = $this->files->inspect($uploadedPath);
        } catch (InvalidCertificate $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $key = "compliance/{$product->id}/".Str::lower((string) Str::ulid()).'.pdf';
        $disk = Storage::disk(MediaUrls::originalsDisk());
        $disk->put($key, (string) file_get_contents($uploadedPath));

        try {
            [$product, $previous] = DB::transaction(function () use ($product, $key, $clientName, $size): array {
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);
                $previous = $product->bsti_certificate_path;

                $product->forceFill([
                    'bsti_certificate_path' => $key,
                    'bsti_certificate_name' => CertificateFiles::displayName($clientName),
                    'bsti_certificate_size' => $size,
                ])->save();

                ProductChanged::dispatch($product->id, $product->slug);

                return [$product, $previous];
            });
        } catch (Throwable $e) {
            $disk->delete($key);

            throw $e;
        }

        if (is_string($previous) && $previous !== '') {
            DeleteComplianceCertificate::dispatch($previous);
        }

        return $product;
    }
}
