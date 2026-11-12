# Media library — API contract (Phase 4, pulled forward from Phase 7 T-02)

The admin's one home for images and PDFs. Design: `docs/_design/04-admin-spec.md` §A25 (parts, the three rules) and §B9 (screen). The pipeline underneath is the product-image pipeline (`docs/api/media.md`), shared, not copied.

Code: `app/Domain/Catalog/Media/**` (model, actions, usage resolver, jobs), `app/Http/Controllers/Api/V1/Admin/Media*`, `app/Http/Requests/Admin/Media/*`, `app/Http/Resources/Admin/Media*`. Tests: `tests/Feature/Media/**`.

> **Why Catalog, not Content.** deptrac lets every domain depend on Catalog, and Catalog on nothing but Support/Models/Customer. `product_images` (Catalog) must reference `media`, so `media` lives where Catalog can see it. Blog, pages and banners (Content) can depend on it later without a cycle.

All paths below are under `/api/v1/admin`. The admin calls them over the Sanctum cookie session; every write carries the CSRF token. Errors are RFC 9457 `application/problem+json` (`type` = `https://toymartbd.com/problems/<type>`). Validation errors are 422 `validation-error` with `errors: [{ field, message }]`; messages are localised by the request locale (`?locale=bn` or `Accept-Language: bn`) for the media-library endpoints. The product-image endpoint keeps its existing English messages.

Numbers are Western digits in both locales. Dates are ISO 8601 UTC (`2026-11-12T08:30:00+00:00`); the admin renders them in Dhaka time.

---

## Decisions (defaults the owner can revisit)

1. **Folders are flat.** A file is in **at most one** folder: `folder_id` may be `null` ("not filed": shown only under *All media*). System folders are seeded and cannot be renamed or deleted: `products`, `category_banners`, `blog`, `pages`. Custom folders can be created, renamed and deleted (deleting one un-files its files; nothing is deleted). *All media* and *Unused* are views (filters), not folders.
2. **Replace keeps the identity, not the bytes path.** `POST /media/{id}/replace` writes a new base under the **same** media row; rendition paths stay immutable so the CDN cache is never wrong. Every usage references the media id, so every product updates at once. The old files are deleted after commit. While the new renditions are generated (a few seconds) the media is `processing` and product pages show the placeholder. This supersedes "same path" in A25 rule 3.
3. **Stable public URL.** "Copy URL" copies `public_url` = `{APP_URL}/m/{id}/{filename-slug}.webp`, a short-cache (5 min) 302 to the current largest WebP rendition. It survives Replace. The filename segment is cosmetic and ignored.
4. **No video in v1.** Kinds are `image` (JPEG, PNG, WebP, AVIF by content) and `document` (PDF by content). Everything else is refused.
5. **Limits.** 10 MB per file for both kinds; images ≤ 8000 px per side. **No minimum size in the library.** The 1200 × 1200 minimum and the 1:1 rule are per-context hints the picker shows from `width`/`height`; the server does not enforce them on library upload.
6. **Storage quota** `MEDIA_STORAGE_QUOTA_GB` (config `media.storage_quota_gb`), default unset → `quota_bytes: null`; the UI shows usage without "of X GB".
7. **Permissions.** View needs `catalog.view` **or** `content.view` (every launch role). Upload, edit, move, replace and folders need `media.manage` (super_admin, manager, content_editor — the same roles as `catalog.update`). **Delete, bulk delete and bulk download** need the new `media.delete` (super_admin and manager only).
8. **PDFs are private.** A document is stored on the private originals disk and only served as an authenticated download (`Content-Disposition: attachment`, `nosniff`). It has no public URL and `/m/...` returns 404 for it.
9. **What "original" means.** The raw upload bytes are never stored (`docs/api/media.md`). For an image, the "original" is the sanitised master (WebP q92, EXIF/GPS stripped, ≤ 2400 px). Downloads and the zip return that master; `original_size_bytes` still reports the size of the uploaded file.
10. **Product-upload media follow their product image.** A file uploaded through the product form (`origin: product`) is deleted together with its last product usage, as before the library existed. A file uploaded to the library (`origin: library`) is never deleted implicitly; when its last usage goes it shows under *Unused*.

---

## Schemas

### `Media`

Returned by the list, upload and bulk endpoints.

```jsonc
{
  "id": 42,
  "kind": "image",                     // "image" | "document"
  "filename": "brick-box-front.jpg",   // the uploaded name, sanitised (ASCII, max 180 + extension). Backfilled rows: "product-12-photo-02.webp"; demo seed: generic product-type names ("wooden-doctor-kit-01.webp"), never a brand
  "caption": null,                     // string | null, ≤ 500
  "alt_text": "Brick Box 500, front of the box",   // string | null, ≤ 255
  "alt_text_bn": "ব্রিক বক্স ৫০০, বাক্সের সামনে",     // string | null, ≤ 255
  "alt_status": "complete",            // "complete" | "missing_bn" | "missing_en" | "missing" | "not_applicable" (documents)
  "folder_id": 1,                      // int | null
  "mime": "image/webp",                // the stored type: "image/webp" (every image master) | "application/pdf"
  "original_mime": "image/jpeg",       // the sniffed upload type: image/jpeg|png|webp|avif, application/pdf
  "width": 1600,                       // int | null (null for documents)
  "height": 1600,
  "size_bytes": 184320,                // what is served: the largest WebP rendition; for a PDF the file. 0 while processing
  "original_size_bytes": 2411724,      // the uploaded file
  "processing_status": "ready",        // "processing" | "ready" | "failed" (documents are always "ready")
  "urls": { "thumb": "https://…/320.webp", "medium": "https://…/480.webp", "large": "https://…/1200.webp" },
                                       // every value null while processing/failed and for documents
  "srcset": { "webp": "…/160.webp 160w, …", "avif": "…/160.avif 160w, …" },   // null likewise
  "public_url": "https://toymartbd.com/m/42/brick-box-front.webp",            // null for documents and until ready
  "used_in_count": 3,                  // number of usages (see MediaUsage)
  "origin": "library",                 // "library" | "product" (see decision 10)
  "uploaded_by": { "id": 7, "name": "Nusrat Jahan" },   // null for backfilled and seeded rows, or a removed user — the UI shows "—"
  "created_at": "2026-11-12T08:30:00+00:00",
  "updated_at": "2026-11-12T08:30:00+00:00"
}
```

`urls` widths come from `config('media.sizes')` (thumb 320, medium 480, large 1200); a URL is the smallest generated width ≥ that size, else the largest (never upscaled). Rendition URLs are immutable (`Cache-Control: immutable`).

### `MediaDetail`

`GET /media/{id}`, `PATCH`, and `replace` return `Media` plus:

```jsonc
{
  // …every Media field…
  "used_in": [ /* MediaUsage, see below */ ],
  "renditions": [                      // ascending width, WebP then AVIF; [] for documents or while processing
    { "format": "webp", "width": 160, "bytes": 5120, "url": "https://…/160.webp" },
    { "format": "avif", "width": 160, "bytes": 4096, "url": "https://…/160.avif" }
  ],
  "master_bytes": 402113,              // bytes of the stored original (master or PDF)
  "renditions_bytes": 512000,          // all renditions together
  "download_path": "/api/v1/admin/media/42/download"
}
```

### `MediaUsage`

One place that references the media. The admin builds the label from the structured fields (no server-side English).

```jsonc
{
  "type": "product_image",             // "product_image" today; more types will be added (treat unknown ones generically)
  "subject_type": "product",           // what to link to; "product" today
  "subject_id": 12,                    // the product id
  "title": "Brick Box 500 pcs",        // the subject's English name
  "title_bn": "ব্রিক বক্স ৫০০ পিস",       // string | null
  "position": 1,                       // 1-based gallery position for product_image; null otherwise
  "is_primary": true,                  // product_image only; null otherwise
  "admin_path": "/admin/products/12"   // where the "arrow-square-out" link goes
}
```

Ordered by product name, then gallery position. Archived products count (their rows still reference the file).

Not tracked yet: `seo_meta.og_image` stores a URL, not a media reference, so it is not a usage. Brand logos and category images/banners store a path, not a media reference (`brands.logo_path`, `categories.image_path`/`banner_path`). They will register a usage type when they move to `media_id`. Blog, pages and banners register theirs when they are built; callers of the resolver do not change.

### `MediaFolder`

```jsonc
{
  "id": 1,
  "name": "Products",                  // custom folders: as typed. System folders: an English default — translate by `system_key`
  "system_key": "products",            // "products" | "category_banners" | "blog" | "pages" | null (custom)
  "is_system": true,
  "position": 0,                       // system folders 0–3, custom folders 100 (then name order)
  "created_at": "2026-11-12T08:30:00+00:00"
}
```

---

## Endpoints

| Method | Path | Permission | Success |
|---|---|---|---|
| GET | `/media` | view | 200 `{ data: Media[], links, meta }` |
| GET | `/media/counts` | view | 200 `{ data: MediaCounts }` |
| GET | `/media/storage` | view | 200 `{ data: MediaStorage }` |
| GET | `/media/folders` | view | 200 `{ data: MediaFolder[] }` |
| POST | `/media/folders` | media.manage | 201 `MediaFolder` |
| PATCH | `/media/folders/{folder}` | media.manage | 200 `MediaFolder` |
| DELETE | `/media/folders/{folder}` | media.manage | 204 |
| POST | `/media` | media.manage | 201 `Media` |
| GET | `/media/{media}` | view | 200 `MediaDetail` |
| PATCH | `/media/{media}` | media.manage | 200 `MediaDetail` |
| POST | `/media/{media}/replace` | media.manage | 200 `MediaDetail` |
| GET | `/media/{media}/download` | view | 200 file (attachment) |
| DELETE | `/media/{media}` | media.delete | 204 · 409 `media-in-use` |
| POST | `/media/bulk/move` | media.manage | 200 `{ data: { moved } }` |
| POST | `/media/bulk/delete` | media.delete | 200 `{ data: BulkDeleteResult }` |
| POST | `/media/bulk/download` | media.delete | 200 `application/zip` · 422 over the cap |
| POST | `/products/{product}/images` with `media_id` | catalog.update | 201 `ProductImage` |
| GET | `/m/{media}/{filename}` (web, no auth) | — | 302 to the rendition · 404 |

"view" = `catalog.view` or `content.view`. A role without the permission gets 403 `forbidden`; an unknown id 404 `not-found`; no session 401 `unauthenticated`.

Single JSON resources are returned **unwrapped** (no `data` key) — the same convention as every other admin resource (`ProductImage`, `Brand`). Collections and the computed objects (`counts`, `storage`, bulk results) are wrapped in `data`.

### `GET /media` — list

Cursor pagination, 30 per page by default.

| Query | Values | Notes |
|---|---|---|
| `filter[folder]` | folder id, or `none` | `none` = not filed |
| `filter[unused]` | `1` | only media with no usage (the *Unused* view) |
| `filter[kind]` | `image` · `document` | |
| `filter[missing_alt]` | `bn` · `en` | images only, where that language's alt is empty (Missing Bangla / English alt) |
| `filter[search]` | text, ≥ 1 char after trim, ≤ 100 | case-insensitive match on filename, `alt_text`, `alt_text_bn`, and the English or Bangla **name of any product that uses the file** |
| `sort` | `newest` (default) · `oldest` · `name` · `size` | `name` = filename A→Z; `size` = `original_size_bytes` largest first. Ties break on id |
| `per_page` | 1–100 | default 30 |
| `cursor` | opaque | from `meta.next_cursor` / `meta.prev_cursor` |

Filters combine with AND. Any other `filter[…]` key, any other `sort`, or an invalid value (a non-numeric folder, `kind=video`, `missing_alt=fr`) is **400 `bad-request`** (rule 16) with a `detail` naming the allowed values.

```jsonc
{
  "data": [ /* Media */ ],
  "links": { "first": null, "last": null, "prev": null, "next": "…?cursor=eyJ…" },
  "meta": {
    "path": "https://…/api/v1/admin/media",
    "per_page": 30,
    "next_cursor": "eyJ…",   // null on the last page
    "prev_cursor": null,
    "total": 846             // matches under the current filters ("Showing 30 of 846")
  }
}
```

### `GET /media/counts` — the rail

Global (ignores any filter). One request feeds every number in the rail.

```jsonc
{
  "data": {
    "all": 1284,
    "unused": 61,
    "unfiled": 4,                                  // folder_id null
    "folders": [ { "id": 1, "count": 846 }, { "id": 2, "count": 37 } ],   // every folder, zero included
    "kinds": { "image": 1270, "document": 14 },
    "missing_alt": { "bn": 37, "en": 5 }           // images only
  }
}
```

### `GET /media/storage` — the meter

```jsonc
{
  "data": {
    "used_bytes": 3435973837,        // originals + renditions
    "originals_bytes": 1288490188,   // masters and PDFs (private disk)
    "renditions_bytes": 2147483649,  // WebP + AVIF renditions (public disk)
    "quota_bytes": null,             // int when MEDIA_STORAGE_QUOTA_GB is set
    "file_count": 1284
  }
}
```

Sizes are the byte counts recorded when the files were written (no disk scan per request).

### `GET /media/folders`

`{ data: MediaFolder[] }`, system folders first by `position`, then custom folders by name.

### `POST /media/folders` · `PATCH /media/folders/{folder}`

Body `{ "name": "Eid campaign 2026" }`: required, trimmed, 1–60 characters, unique among folders (case-insensitive) → 422 on `name`. Bangla names are fine.

- Renaming a system folder → **409 `conflict`** ("System folders cannot be renamed or deleted.").

### `DELETE /media/folders/{folder}`

Custom folders only (system → 409 `conflict`). Its files become not filed (`folder_id: null`); no file is deleted. 204.

### `POST /media` — upload

`multipart/form-data`, **one file per request** (the UI queues and shows per-file progress).

| Field | Rules |
|---|---|
| `file` | required. JPEG, PNG, WebP, AVIF or PDF **by content** (name and client MIME are ignored); ≤ 10 MB |
| `folder_id` | optional, an existing folder id, or omitted/empty = not filed |
| `alt_text`, `alt_text_bn` | optional, ≤ 255 (ignored for a PDF) |
| `caption` | optional, ≤ 500 |

201 with `Media`. An image is `processing` (renditions are queued after commit; poll `GET /media/{id}` or the list every 2 s while any item is `processing`); a PDF is `ready` at once. Upload is **never** refused for missing alt text (A25 rule 1).

Refusals are 422 `validation-error` on `file`. The messages (EN shown; Bangla with `locale=bn`) state the cause, for the queue item:

| Cause | Message |
|---|---|
| Not an accepted type by content (incl. SVG, GIF, HEIC, video) | `Not a real image or PDF — the content is not JPG, PNG, WebP, AVIF or PDF.` |
| Over 10 MB | `11.4 MB — over the 10 MB limit.` |
| Over 8000 px | `9000 × 6000 px — over the 8000 px limit per side.` |
| Image carrying script or markup (polyglot) | `The file contains embedded script or markup and was refused.` |
| Undecodable / truncated image | `The image could not be read.` |
| PDF with JavaScript, launch actions, embedded files, RichMedia or XFA | `The PDF contains scripts, attachments or interactive content. Export it again as a plain PDF.` |
| Empty file | `The file is empty.` |

A failed upload stores nothing (no row, no file).

### `GET /media/{media}` — detail

`MediaDetail`, including `used_in`.

### `PATCH /media/{media}` — edit

JSON, any of: `alt_text`, `alt_text_bn` (string ≤ 255 or null; trimmed, empty → null), `caption` (≤ 500 or null), `folder_id` (folder id or null). Only the keys present change. Returns `MediaDetail`.

Alt text is **one source of truth** shared with the product: editing it here changes `alt_text`/`alt_text_bn` on every `ProductImage` that uses this media, and editing a product image's alt changes this media. Each product using it is revalidated on the storefront.

### `POST /media/{media}/replace` — replace file

`multipart/form-data`, `file` (same rules as upload). The new file must be the **same kind** (image ↔ image, PDF ↔ PDF), else 422 on `file` (`Replace with an image` / `Replace with a PDF`). Keeps `id`, alt text, caption, folder, origin and every usage; updates `filename`, sizes, dimensions and `original_mime`. Returns `MediaDetail` (an image is `processing` again). The old files are deleted after commit (decision 2).

### `GET /media/{media}/download`

The stored original as an attachment: an image's master (`{filename-stem}.webp`, `image/webp`) or the PDF. Headers `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`.

### `DELETE /media/{media}`

Needs `media.delete`. 204 when unused; files are removed after commit.

In use → **409 `media-in-use`** (A25 rule 2), with the usages as an extension member:

```jsonc
{
  "type": "https://toymartbd.com/problems/media-in-use",
  "title": "Media in use",
  "status": 409,
  "detail": "This file is used in 3 places. Remove it from them, or replace the file instead.",
  "usages": [ /* MediaUsage */ ]
}
```

### `POST /media/bulk/move`

`{ "ids": [1, 2, 3], "folder_id": 4 }` — `ids` 1–200 distinct existing ids (an unknown id → 422 on `ids.N`); `folder_id` an existing folder or `null` (un-file). 200 `{ "data": { "moved": 3 } }`.

### `POST /media/bulk/delete`

`{ "ids": [1, 2, 3] }` — 1–200 distinct integers. Needs `media.delete`. Deletes what it can and **skips files in use** (A25 rule 2); never fails as a whole for that. 200:

```jsonc
{
  "data": {
    "deleted": 2,
    "skipped": 1,
    "results": [
      { "id": 1, "status": "deleted", "used_in_count": 0 },
      { "id": 2, "status": "in_use",  "used_in_count": 3 },
      { "id": 3, "status": "deleted", "used_in_count": 0 },
      { "id": 9, "status": "not_found", "used_in_count": 0 }
    ]
  }
}
```

`results` is in request order.

### `POST /media/bulk/download`

`{ "ids": [1, 2, 3] }`. Needs `media.delete`. 200 `application/zip` attachment `toymartbd-media-YYYYMMDD-HHMM.zip` (Dhaka time) holding each original under its filename (duplicates get ` (2)`, ` (3)`…). Download it with `fetch` → `blob()`; it is a POST so the CSRF header applies.

Caps (config `media.zip_max_files`, `media.zip_max_bytes`): **at most 100 files and 500 MB** of originals. Over either → **422 `validation-error` on `ids`**: `100 files at most per download; you selected 140.` / `The selected files are 612.4 MB; the limit is 500 MB per download.` Unknown ids → 422 on `ids.N`.

### `POST /products/{product}/images` — attach from the library

The existing product-image endpoint (`docs/api/media.md`) additionally accepts JSON or multipart **`media_id`** instead of `file` (exactly one of the two; both or neither → 422). The media must be a `kind: image` (else 422 on `media_id`) and must not already be in this product's gallery (422 on `media_id`). `crop` is not allowed with `media_id` (422). Same 10-image cap (422 on `media_id`), same position (appended last) and primary rules (primary when it is the first image). Optional `alt_text`/`alt_text_bn`/`variant_id` behave as on upload — alt values, when sent, are written to the media (shared). 201 with the unchanged `ProductImage` shape; its `processing_status` and URLs are the media's.

Uploading a **file** to this endpoint now also creates a `media` row (`origin: product`) in the *Products* folder, so every product photo appears in the library.

### `GET /m/{media}/{filename}` — stable public URL

Web route, no auth. **302** to the largest WebP rendition with `Cache-Control: public, max-age=300`. **404** (cached 60 s) when the id is unknown, the media is a document, or it has no ready rendition. Throttled at 240 requests a minute per client.

---

## Error types used here

| Status | `type` | When |
|---|---|---|
| 400 | `bad-request` | unlisted filter or sort, invalid filter value |
| 401 | `unauthenticated` | no session |
| 403 | `forbidden` | the role lacks the permission |
| 404 | `not-found` | unknown media or folder id |
| 409 | `media-in-use` | `DELETE /media/{id}` on a used file (`usages` member) |
| 409 | `conflict` | renaming or deleting a system folder |
| 419 | `csrf-token-mismatch` | a write without the CSRF token |
| 422 | `validation-error` | refused file, bad body, zip over the cap |

---

## Implementation notes

- Tables: `media`, `media_folders`, and `product_images.media_id` (DATABASE-DESIGN §3.9 as-built).
- `product_images` file columns (`path`, `width`, `height`, `processing_status`, `rendition_widths`) and alt columns stay (expand only). For a linked row they are a write-through **mirror** kept in the same transaction as every media write, so rolling back the code is safe; **reads never use them** when `media_id` is set — `ProductImage` delegates those attributes to its media. The contract step that drops them is a later batch.
- Existing product images are linked by the backfill (`php artisan toymart:media:backfill`, also run by migration `2026_11_17_100300`): one media row per distinct `product_images.path`, so a duplicated product and its source share one media. Idempotent, chunked by id, one short transaction per chunk.
