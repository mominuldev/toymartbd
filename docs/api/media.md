# Media pipeline (P4-CAT-07)

How product images get from an admin upload to a CDN URL. Code: `app/Domain/Catalog/Services/ImagePipeline.php`, config: `config/media.php`.

## Flow

1. **Validate by content** (`App\Http\Rules\RealImage` → `ImagePipeline::inspect`). The extension and the client MIME are ignored. The type comes from `finfo` **and** must agree with the magic bytes; only JPEG, PNG, WebP and AVIF pass. Also refused: files over 10 MB, images over 8000 px on a side (read from the header before any decode), and bytes containing `<?php`, `<script`, `<svg`, `<html`, `<iframe` or `<!doctype` (polyglots). Each refusal is a 422 `validation-error` on `file`. A product holds at most 10 images.
2. **Re-encode a master** in the request, outside any transaction: decode with GD, apply EXIF orientation, apply the optional crop, cap at 2400 px, encode WebP q92 with metadata stripped. The raw upload bytes are never stored. The master goes to the private `MEDIA_ORIGINALS_DISK` at `{base}/master.webp`.
3. **One short transaction** appends the row: `processing`, last position, primary when it is the first image.
4. **After commit**, `GenerateImageRenditions` runs on `MEDIA_QUEUE` (default `index`, which the standard worker already serves). It writes WebP (q80) and AVIF (q60) at each configured width to the public `MEDIA_DISK`, then marks the row `ready` and fires `ProductChanged`. That revalidates `products` and `product:{slug}`.

**Why queued:** on the dev machine, the master of a 3000 px JPEG takes about 1.1 s. All ten renditions take about 2.0 s more, and AVIF is most of that. Doing it all inside the request would put about 3 s on every upload in the multi-file uploader. The admin polls the image list every 2 s while any image is `processing`.

## Widths and formats

`160 · 320 · 480 · 800 · 1200` (`ProductImage::WIDTHS` = `config('media.widths')`), each in WebP and AVIF:

| Width | Used for |
|---|---|
| 160 | admin list thumbnail, cart line |
| 320 | uploader tile (174 px @2x), mobile card @1x (`urls.thumb`) |
| 480 | product card: 2-up mobile @2x, 4-up desktop (`urls.medium`) |
| 800 | PDP on tablets |
| 1200 | PDP gallery @2x, the 1200×1200 upload minimum (`urls.large`) |

Images are never upscaled. A source narrower than a width stops the list there, and `rendition_widths` records what exists. `ProductImage::url($width, $format)` returns the smallest generated width ≥ `$width`, else the largest. It returns null without a file or while the image is `processing`.

## URLs

The URL is `MEDIA_CDN_URL` + `{base}/{w}.{fmt}` when that variable is set, otherwise the disk URL (`APP_URL/storage/…` on the `public` disk, which needs `php artisan storage:link`). Paths carry a random id per upload, so a file never changes after it is written. Cache with `Cache-Control: public, max-age=31536000, immutable`.

**Owner decision pending:** the object store and the CDN host (for example Cloudflare R2 with a CDN domain). Switching means two changes. Point `MEDIA_DISK` at an S3-compatible disk, which needs `league/flysystem-aws-s3-v3`. Set `MEDIA_CDN_URL`.

## Deletion and crops

- `DELETE` removes the row. Positions close up and the next image is promoted to primary when needed. `DeleteMediaFiles` then removes `{base}` from both disks after commit. It skips a base that another row still references, because a duplicated product shares its source's files.
- `POST …/images/{image}/crop` re-crops the current master. The rectangle is in the master's pixels, `width × height` on the resource. The result goes to a new base, the renditions regenerate, and the old base is deleted after commit. Crops compound: the original is not kept.

## Admin API

All routes sit under `/api/v1/admin/products/{product}/images`. `GET` needs catalog.view. Every write needs catalog.update.

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/` | — | `{ data: ProductImage[] }` by position |
| POST | `/` | multipart `file`, `alt_text?`, `alt_text_bn?`, `variant_id?`, `crop[x,y,width,height]?` | 201 `ProductImage` |
| PATCH | `/{image}` | `alt_text?`, `alt_text_bn?`, `variant_id?` (null detaches), `is_primary?` (true only) | `ProductImage` |
| POST | `/reorder` | `{ ids }`, every id exactly once | `{ data: ProductImage[] }` |
| POST | `/{image}/crop` | `{ x, y, width, height }` | `ProductImage` (`processing`) |
| DELETE | `/{image}` | — | 204 |

`ProductImage` has these fields: `id`, `variant_id`, `url` (= `urls.large`, legacy), `urls { thumb, medium, large }` (WebP), `srcset { webp, avif }`, `width`, `height`, `processing_status`, `alt_text`, `alt_text_bn`, `position`, `is_primary`.

## Demo seed images

`DemoCatalogSeeder` (local/testing only) attaches each demo product's Figma photo (`docs/_design/assets/products/NN-{SKU}.png`, 1024×1024) through `UploadProductImage` — the same sniffing, payload scan, re-encode and renditions as an admin upload — with English and Bangla alt text from the product name, and runs `GenerateImageRenditions` synchronously, so `migrate:fresh --seed` yields `ready` images without a worker. A 1024 px source yields the 160–800 renditions; 1200 is skipped, never upscaled.

`DEMO_SEED_IMAGES` (`media.demo_seed_images`) controls it: unset means on in `local`, off everywhere else (PHPUnit, the admin E2E `serve.sh` sets `false` explicitly). About 16 s for the full seed with images on the dev machine, 1.6 s without.

**C2PA.** The AI-generated exports carry a C2PA Content Credentials chunk (`caBX`) whose manifest embeds an SVG icon, so the payload scan refuses them ("embedded script or markup"). The seeder uploads a temporary copy with only that chunk dropped (pixels byte-identical). An admin uploading a C2PA-signed PNG gets the same refusal today; whether the scan should skip known metadata chunks is an open decision, not changed here.
