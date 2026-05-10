# Laracrate

Polymorphic file storage for Laravel with direct upload to R2/S3, granular access control, sensitive content streaming, automatic image variants, video and PDF previews, per-variant watermarking, multipart uploads, text extraction, and vector embeddings.

## Table of contents

1. [Philosophy](#philosophy)
2. [Installation](#installation)
3. [Data model](#data-model)
4. [Configuration](#configuration)
5. [Using it from your model](#using-it-from-your-model)
6. [Processing pipeline](#processing-pipeline)
7. [Variants](#variants)
8. [Upload modes](#upload-modes)
9. [HTTP endpoints](#http-endpoints)
10. [Sensitive content](#sensitive-content)
11. [Artisan commands](#artisan-commands)
12. [Optional Livewire component](#optional-livewire-component)
13. [Full API](#full-api)
14. [Tests](#tests)
15. [Dependencies](#dependencies)
16. [License](#license)

## Philosophy

1. **Backend agnostic to the frontend.** The core has zero dependency on Livewire or Alpine. It exposes endpoints, a trait, and a service.
2. **Reuses Laravel's `Storage::disk()`.** Disk credentials live in `config/filesystems.php` (single source of truth). The package does not duplicate configuration.
3. **Pipeline of Actions.** Every operation is an isolated class (`edulazaro/laractions`), testable and queueable.
4. **Async processing.** Variants, video and PDF previews, text extraction, embeddings, all run on the queue. The user upload is instant.
5. **3 access modes per collection**: `public` (direct CDN), `signed` (temporary signed URL), `stream` (controller with audit and viewer bind).
6. **`path = full key` convention.** The `path` field on a File row stores the complete object key in the disk (directories, filename, extension). The `name` field is denormalization of `basename($path)`.

## Installation

### 1. Path repository in your app's `composer.json` (until it ships on Packagist)

```json
{
    "repositories": [
        { "type": "path", "url": "packages/edulazaro/laracrate", "options": { "symlink": true } }
    ],
    "require": {
        "edulazaro/laracrate": "@dev"
    }
}
```

### 2. Install and publish

```bash
composer require edulazaro/laracrate
php artisan vendor:publish --tag=laracrate-config
php artisan migrate
```

`migrate` creates 3 tables, all with the `laracrate_` prefix:

- `laracrate_files`, the main table for top-level files and variants.
- `laracrate_file_contents`, chunks of extracted text and embeddings (opt-in).
- `laracrate_multipart_uploads`, active multipart upload sessions.

The `laracrate_` prefix avoids clashing with legacy `files` tables that exist in many Laravel apps.

### 3. Disks in `config/filesystems.php`

Add the disks you intend to use (R2/S3 for real storage, `local` for dev):

```php
'media' => [
    'driver' => 's3',
    'bucket' => env('R2_BUCKET_MEDIA'),
    'endpoint' => env('R2_ENDPOINT'),
    'use_path_style_endpoint' => true,
    // ...
],
'documents' => [
    'driver' => 's3',
    'bucket' => env('R2_BUCKET_DOCUMENTS'),
    // ...
],
```

## Data model

### Table `laracrate_files`

47 core columns plus JSON:

```
id, slug (ulid)
parent_id, variant                            (variants/preview hierarchy)
fileable_type/id                              (polymorphic owner)
creator_type/id                               (polymorphic creator)
tenant_type/id                                (polymorphic tenant)
disk, path, name, original_name, extension, mime_type, size, digest
context, collection, type (image/video/audio/document), category
access (public/signed/stream), visibility, sensitive, is_encrypted
title, description, label, default, position, published, is_verified
duration, width, height, bitrate, sample_rate
metadata (json)
processing_status, processing_error, processing_started_at
downloads_count, last_downloaded_at
timestamps + softDeletes
```

### Auxiliary tables

- `laracrate_file_contents`, one row per chunk of extracted text. If the collection sets `chunk_size: 0`, one row per File holds all the text.
- `laracrate_multipart_uploads`, active multipart upload sessions for S3/R2. Typical lifetime of minutes to hours. The `laracrate:abort-stale-multipart` cron aborts those past `expires_at`.

### Key concepts

- **`path` is the full object key in the disk.** It is not concatenated with `name`. Recommended access: `$file->key` (an accessor that does `ltrim($file->path, '/')`).
- **`name` is denormalization** of the basename (with extension). Useful for queries and display, never concatenated.
- **`parent_id` and `variant`**: any child File (thumbnail, preview, transcoded) has `parent_id` pointing to its parent and `variant` carrying the role (`thumbnail`, `medium`, `preview`, `display`...). Recursive: a video preview has its own child variants.
- **3 orthogonal polymorphic relations**: `fileable` (what it belongs to), `creator` (who created it), `tenant` (multi-tenant scope).
- **`access`**: `public` produces a direct CDN URL, `signed` produces a signed URL with TTL, `stream` produces a package route with per-request re-validation.
- **`processing_status`**: `pending`, `processing`, `completed`, `failed`. Enum `EduLazaro\Laracrate\Enums\ProcessingStatus`.

## Configuration

Everything lives in `config/laracrate.php` (published with `vendor:publish`).

### `default_collection` and `default_context`

Schema defaults applied when a File row is inserted without specifying them.

```php
'default_collection' => 'default',
'default_context'    => 'default',
```

### `defaults`, defaults per file type

These apply to every collection unless overridden. Each type defines accepted mime types, max size, quality, max dimensions, and default variants.

```php
'defaults' => [
    'image' => [
        'accepted_mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'accepted_extensions' => ['jpeg', 'jpg', 'png', 'gif', 'webp'],
        'max_file_size'       => 10240,
        'format'              => 'webp',
        'quality'             => 90,
        'variant_quality'     => 85,
        'max_width'           => 1920,
        'max_height'          => 1080,
        'variants' => [
            'thumbnail' => ['width' => 300,  'height' => 300],
            'medium'    => ['width' => 800,  'height' => 800],
            'large'     => ['width' => 1600, 'height' => 1600],
        ],
    ],
    'document' => [
        'accepted_mime_types' => ['application/pdf', 'application/msword', /* ... */],
        'accepted_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
        'max_file_size'       => 20480,
    ],
    'audio' => [/* ... */],
    'video' => [/* ... */],
],
```

### `collections`, definition of each collection

Each collection sets disk, access mode, accepted types with their config, and optionally `single`, `sensitive`, `encrypt`, `ttl_hours`, `quota_bytes`, `component`, `placeholder`.

```php
'collections' => [

    'avatar' => [
        'disk'      => 'media',
        'access'    => 'public',
        'single'    => true,                   // only 1 file per owner
        'component' => 'user-avatar',          // default blade component (optional)
        'types'     => [
            'image' => [
                'variants' => [
                    'small'  => ['width' => 64,  'height' => 64,  'fit' => true],
                    'medium' => ['width' => 128, 'height' => 128, 'fit' => true],
                ],
            ],
        ],
    ],

    'identity' => [
        'disk'      => 'documents',
        'access'    => 'stream',
        'sensitive' => true,                   // bind URL to the user
        'encrypt'   => true,                   // encrypt binary at rest
        'types'     => [
            'image' => [
                'variants' => [
                    'thumbnail' => ['width' => 300, 'height' => 300],            // no watermark
                    'display'   => ['width' => 1200, 'watermark' => true],       // watermarked
                ],
            ],
            'document' => [
                'preview' => ['page' => 1, 'width' => 2000],
            ],
        ],
    ],

    'temp_uploads' => [
        'disk'      => 'media',
        'access'    => 'public',
        'ttl_hours' => 24,                     // purged via command
    ],

],
```

**Rules for `types`:**

- An allowlist of which types the collection accepts, plus the config of what to do with each.
- Each entry can be a bare string (`'image'`, inherits global defaults) or an array (`'image' => [overrides]`).
- `variants` always live inside a type (`types.image.variants`).
- `preview` for document and video produces a special variant; its own child variants go in `preview.variants`.
- The global type defaults are recursively merged with the collection override. You only declare what you want to change.

#### Granular per-model config (optional)

The same collection name can serve several models with **different config per model**, using an optional `models` block. The block is keyed by morph alias (or FQCN if you don't use a morph map). Each entry is merged on top of the base.

```php
'documents' => [
    // base — shared by every model that uses this collection
    'disk'   => 'documents',
    'access' => 'signed',
    'types'  => [
        'document' => ['preview' => ['page' => 1, 'width' => 1600]],
    ],

    'models' => [
        'case' => [
            // inherits everything above, plus:
            'path' => 'cases/{slug}/documents',
        ],
        'organization' => [
            'path' => 'orgs/{handle}/documents',
            // override puntual: en orgs no queremos preview
            'types' => [
                'document' => ['preview' => false],
            ],
        ],
    ],
],
```

Semantics:

- **Without `models`** → flat config, any model that uses the collection gets the same behavior (legacy / default).
- **With `models`** → the collection is restricted to those aliases; any other model throws `EduLazaro\Laracrate\Exceptions\CollectionNotAllowedForModel`.
- Override is `array_replace_recursive` on top of the base. Scalars get replaced; nested arrays merge key by key (declare the full array on a key when you want a wholesale swap).
- The `models` key itself is stripped from the resolved output — callers see a normal flat array.
- Tooling that iterates collections without a model context (e.g. `laracrate:purge-expired`) gets the base config without the `models` block.

### `placeholders`, fallback when there is no file

Resolution order (most specific to most general):

1. `config('laracrate.collections.{name}.placeholder')`
2. `config('laracrate.placeholders.{type}')`
3. `config('laracrate.placeholders.default')`

```php
'placeholders' => [
    'default'  => '/img/laracrate/file.svg',
    'image'    => '/img/laracrate/image.svg',
    'video'    => '/img/laracrate/video.svg',
    'audio'    => '/img/laracrate/audio.svg',
    'document' => '/img/laracrate/document.svg',
],
```

Each slot accepts a fixed string or a dynamic closure:

```php
'image' => fn ($collection, $type, $model) => "/api/avatars/{$model->id}.svg",
```

### `urls`, URL strategy

```php
'urls' => [
    'signed_ttl'             => 5,    // signed URL TTL in minutes (R2)
    'signed_cache_ttl'       => 4,    // server-side cache TTL of the signed URL
    'sensitive_redirect_ttl' => 10,   // ultra-short TTL after validation (seconds)
    'route_signed_ttl'       => 15,   // HMAC TTL for /files/{slug}/stream (minutes)
    'bind_to_user'           => true, // tie the URL to the current viewer when sensitive
],
```

### `policies`, bridge to Laravel's Gate

```php
'policies' => [
    'register_gate' => true,
],
```

When `register_gate` is on you can use the native ergonomics:

```php
@can('view', $file)
$user->can('update', $file)
$this->authorize('delete', $file)
Route::middleware('can:view,file')
```

Mapping: `view`/`update`/`delete` go to the registry's `canView`/`canEdit`/`canDelete`.

### `stream`, streaming endpoints

```php
'stream' => [
    'route_prefix'        => 'files',
    'route_name_prefix'   => 'laracrate.files',
    'middleware'          => ['web', 'auth'],
    'increment_downloads' => true,
    'log_access'          => true,
],
```

### `status`, polling endpoints

```php
'status' => [
    'route_prefix' => 'laracrate/files',
    'middleware'   => ['web', 'auth'],
],
```

Endpoints:

- `GET /laracrate/files/{slug}/status`, status of a single file.
- `POST /laracrate/files/status`, batch (multiple slugs).

### `multipart`, large uploads

```php
'multipart' => [
    'threshold'       => 100 * 1024 * 1024,  // 100 MB; the frontend decides when to use multipart
    'part_size'       => 10  * 1024 * 1024,  // 10 MB per part (S3 minimum is 5 MB)
    'expire_minutes'  => 60,                 // multipart session TTL
    'url_ttl_minutes' => 60,                 // presigned URL TTL per part
    'route_prefix'    => 'laracrate/multipart',
    'middleware'      => null,               // null inherits from uploads
],
```

### `image`, image processing

```php
'image' => [
    'driver'             => 'imagick',  // 'imagick' (recommended) or 'gd'
    'optimize_originals' => false,      // re-encode the original to webp with max dims
    'max_width'          => 1920,
    'max_height'         => 1920,
    'quality'            => 85,
],
```

### `video`, transcoding

```php
'video' => [
    'max_width'    => 1920,
    'max_height'   => 1920,
    'bitrate_kbps' => 2500,
],
```

Requires `ffmpeg` and `ffprobe` on the server's PATH.

### `encryption`, encryption of sensitive binaries

```php
'encryption' => [
    'driver' => 'laravel',
],
```

If a collection sets `'encrypt' => true`, the binary is encrypted with `EncryptFileAction` before being uploaded to the backend, and decrypted on the fly when served by `StreamFileController`.

### `embeddings`, text extraction and vectors

```php
'embeddings' => [
    'enabled'       => false,
    'provider'      => 'openai',
    'api_key'       => env('LARACRATE_EMBEDDINGS_API_KEY'),
    'model'         => env('LARACRATE_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
    'dimensions'    => 1536,
    'chunk_size'    => 1000,
    'chunk_overlap' => 100,
    'batch_size'    => 16,
],
```

Per-collection activation:

```php
'collections' => [
    'documents' => [
        'extract_text' => true,
        'embed'        => true,
        // ...
    ],
],
```

Custom provider:

```php
// AppServiceProvider::register()
$this->app->bind(
    \EduLazaro\Laracrate\Contracts\EmbeddingProvider::class,
    \App\Embeddings\MyCustomProvider::class
);
```

Custom text extractor:

```php
// AppServiceProvider::boot()
$registry = app(\EduLazaro\Laracrate\Support\TextExtractorRegistry::class);
$registry->add(new \App\Extractors\MyOcrExtractor());
```

Bundled implementations:

- `OpenAiEmbeddingProvider` (default).
- `NullEmbeddingProvider` (no-op for testing).
- `PdfTextExtractor` (PDFs via `smalot/pdfparser`).
- `PlainTextExtractor` (text/*).

### `watermark`, per-variant watermark

The watermark is baked into the binary of specific variants. **The original (master) NEVER carries a watermark.** Only variants that explicitly opt in.

```php
'watermark' => [
    'image_path' => env('LARACRATE_WATERMARK_IMAGE', null),  // PNG to overlay
    'size'       => 0.40,                                    // 40% of the variant's width
    'opacity'    => 30,                                      // 0 to 100
    'position'   => 'center',

    'text' => [
        'content'         => null,                           // null, fixed string, or closure(File): ?string
        'font_size_ratio' => 0.0195,
        'color'           => 'rgba(255, 255, 255, 0.60)',
        'position'        => 'bottom-left',
        'padding'         => 20,
        'font_path'       => null,
    ],
],
```

Per-variant activation:

```php
'collections' => [
    'identity' => [
        'types' => [
            'image' => [
                'variants' => [
                    'thumbnail' => ['width' => 300, 'height' => 300],            // no watermark
                    'display'   => ['width' => 1200, 'watermark' => true],       // with watermark
                ],
            ],
        ],
    ],
],
```

If you change the PNG or tweak sizes, regenerate the variants and the master stays untouched.

### `ui`, default theme for the optional Livewire component

```php
'ui' => [
    'default_theme' => env('LARACRATE_THEME', 'default'),
],
```

Only relevant if you use the optional Livewire component. Details in its section.

### `queue`

```php
'queue' => [
    'connection' => env('LARACRATE_QUEUE_CONNECTION', null),  // null uses Laravel's default
    'name'       => env('LARACRATE_QUEUE_NAME', 'default'),
],
```

Useful for isolating file processing from other queues.

## Using it from your model

### `HasFiles` trait

```php
use EduLazaro\Laracrate\Concerns\HasFiles;

class Property extends Model
{
    use HasFiles;

    // Per-model override (optional). Recursively merged with the global collection.
    protected array $fileCollections = [
        'gallery' => [
            'types' => [
                'image' => [
                    'variants' => [
                        'og' => ['width' => 1200, 'height' => 630, 'fit' => true, 'format' => 'jpg'],
                    ],
                ],
            ],
        ],
    ];
}
```

### Server-side upload (regular request)

```php
$property->addFile($request->file('image'), 'gallery', [
    'title' => 'Front facade',
    'label' => 'facade',
]);
```

### Direct upload to R2 (presigned, recommended)

JS client:

```js
import { presignAndUpload } from 'edulazaro/laracrate/resources/js/laracrate';

const result = await presignAndUpload(fileInput.files[0], {
    disk: 'media',
    fileable: { type: 'property', id: 123 },
    collection: 'gallery',
    maxSizeKb: 102400,
    onProgress: (pct) => console.log(`${(pct * 100).toFixed(0)}%`),
});

await fetch(`/properties/${propertyId}/files`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    body: JSON.stringify({ ...result, collection: 'gallery' }),
});
```

Backend confirm:

```php
use EduLazaro\Laracrate\Support\FileUpload;

Route::post('/properties/{property}/files', function (Request $request, Property $property) {
    $upload = FileUpload::fromArray($request->validate([
        'key'           => 'required|string',
        'mime_type'     => 'required|string',
        'original_name' => 'required|string',
        'size'          => 'required|integer',
    ]));

    return $property->addFile($upload, $request->input('collection', 'gallery'));
});
```

### Multipart (large files, larger than 100 MB)

The JS helper detects the size and switches to multipart automatically when it crosses the `threshold`. The backend is already covered by the package routes.

### Show in blade

```blade
{{-- File URL --}}
<img src="{{ $property->file('gallery')?->variant('medium')->url() }}">

{{-- Variant with dot notation --}}
<img src="{{ $videoFile->variant('preview.thumbnail')->url('image') }}">

{{-- Helpers with automatic placeholder fallback --}}
<img src="{{ $user->fileLink('avatar', 'medium') }}">

{{-- Render with a configurable blade component --}}
{{ $user->fileRender('avatar', 'medium', ['class' => 'w-12 h-12 rounded-full']) }}

{{-- Direct stream link (collections with access=stream) --}}
<a href="{{ $file->link }}">Download</a>
<img src="{{ $file->preview_link }}">
```

`$file->variant('preview.thumbnail')` walks with dot notation and **falls back to the closest real ancestor** if the chain breaks (it never returns null). If you need to fail loudly, use `variantOrFail()`.

### Helpers `fileLink()` and `fileRender()`

They remove the null-check boilerplate:

```php
$user->fileLink('avatar')                          // URL or configured placeholder
$user->fileLink('avatar', 'medium')                // medium variant
$user->fileLink('cover', 'preview.thumbnail')      // dot notation
$user->fileLink('cover', 'preview.small', 'image') // force a type

$user->fileRender('avatar', 'medium', ['class' => 'w-12 h-12'])
// produces <x-{component} :model="$user" :url="..." class="w-12 h-12" />
```

`fileLink()` returns `string|null`. `fileRender()` returns `HtmlString`.

#### Default blade component per collection

```php
'collections' => [
    'avatar' => [
        'component' => 'user-avatar',
        // ...
    ],
],
```

Component in your app:

```blade
{{-- resources/views/components/user-avatar.blade.php --}}
@props(['model', 'url'])

@if($url)
    <img src="{{ $url }}" {{ $attributes->merge(['class' => 'rounded-full']) }} alt="{{ $model->name }}">
@else
    <div {{ $attributes->merge(['class' => 'rounded-full bg-gray-300 flex items-center justify-center text-white']) }}>
        {{ strtoupper(mb_substr($model->name, 0, 1)) }}
    </div>
@endif
```

### Delete, reorder, publish

```php
$property->deleteFile($file);
$property->reorderFiles('gallery', $request->input('ids'));
$file->makeDefault();
$file->publish();
$file->unpublish();
```

### Policies (authorization)

```php
use EduLazaro\Laracrate\Support\PolicyRegistry;

// AppServiceProvider::boot()
app(PolicyRegistry::class)
    ->viewable('property',   fn ($file, $user) => $user && $file->fileable->isOwnedBy($user))
    ->editable('property',   fn ($file, $user) => $user && $file->fileable->canEdit($user))
    ->deletable('property',  fn ($file, $user) => $user && $file->fileable->canEdit($user));
```

Defaults when no policy is registered:

- The **human creator** of the File can always view, edit, and delete.
- Files with `access='public'` can always be viewed.
- Everything else: deny.

## Processing pipeline

When a top-level File is created (no `parent_id`), `FileObserver::created` dispatches `ProcessFileJob` (queue). The job orchestrates `ProcessFileAction`, which iterates the **Steps** of the `ProcessingPipelineRegistry` in ascending priority order.

Default steps shipped by the package:

| Priority | Step | Triggers when |
|---:|---|---|
| 10 | `ExtractImageDimensions` | type === image |
| 10 | `ExtractVideoDimensions` | type === video (requires ffprobe) |
| 20 | `OptimizeImage` | type === image and collection.optimize_originals === true |
| 25 | `TranscodeVideo` | type === video and collection.types.video.transcode === true |
| 40 | `GenerateImageVariants` | type === image and there is a `variants` config |
| 45 | `ExtractVideoPreview` | type === video and there is a `preview` config |
| 45 | `ExtractPdfPreview` | type === document and mime === application/pdf |
| 60 | `ExtractText` | extract_text or embed, and there is a TextExtractor for the mime |
| 70 | `ChunkText` | embed === true and text was extracted |
| 80 | `GenerateEmbedding` | embeddings.enabled, embed === true, and there are chunks |

Priority convention:

- 0 to 19: metadata (dimensions, duration).
- 20 to 39: original transformation (optimize, transcode, encrypt).
- 40 to 59: derivatives (variants, previews, thumbnails).
- 60 to 79: semantic extraction (text, OCR, transcription).
- 80 to 99: AI (chunking, embeddings, classification).

Events:

- `FileProcessingStarted`, before the first step.
- `FileProcessed`, all steps completed OK.
- `FileProcessingFailed`, a step threw.
- `VariantGenerated`, when a variant is created.
- `EmbeddingsReady`, when embeddings are generated.

Fail-fast policy: if a step throws, the File is left at `processing_status = FAILED` and `ProcessFileJob` retries with backoff (3 tries: 10s, 30s, 60s). Subsequent steps do not run on that attempt.

If the File is deleted before the worker reaches the job (typical when `setFile()` replaces an avatar), Laravel discards the job silently thanks to `$deleteWhenMissingModels = true`. No zombie entries in `failed_jobs`.

### Extending the pipeline from your app

```php
// AppServiceProvider::boot()
$registry = app(\EduLazaro\Laracrate\Support\ProcessingPipelineRegistry::class);

// Add your own step
$registry->add(new \App\Files\Pipeline\VirusScanStep());

// Remove a default
$registry->remove(\EduLazaro\Laracrate\Pipeline\Steps\Image\OptimizeImageStep::class);
```

Custom Step:

```php
namespace App\Files\Pipeline;

use App\Files\Actions\VirusScanAction;
use EduLazaro\Laracrate\Contracts\ProcessingStep;
use EduLazaro\Laracrate\Models\File;

class VirusScanStep implements ProcessingStep
{
    public function supports(File $file): bool
    {
        return $file->creator_type === 'user'
            && in_array($file->collection, ['documents', 'attachments'], true);
    }

    public function priority(): int
    {
        return 5;
    }

    public function handle(File $file): void
    {
        VirusScanAction::create()->run(['file' => $file]);
    }
}
```

## Variants

Variants are child rows of `laracrate_files` with `parent_id` and `variant`. The cascade FK deletes them when you delete the parent. The `FileObserver` deletes the binary in R2 when the row is force-deleted.

### Path convention

- `path` of the original: `{fileable_type}/{fileable_id}/{collection}/{ulid_filename.ext}`.
- `path` of a variant: `{parentDir}/variants/{baseName}_{variantName}.{ext}`.
- `path` of a sibling (for example, a transcoded `mp4` replacing the `mov`): `{parentDir}/{newName}.{ext}`.

Helpers on the `File` model:

```php
$file->key                                  // ltrim($file->path, '/'), the full key
$file->variantKey($newName)                 // build the key for a variant (variants/ subdir)
$file->siblingKey($newName)                 // build the key for a sibling (same dir)
$file->createVariant($name, $overrides)     // create a variant row inheriting parent scope
```

### Per-variant watermark

The watermark is baked into the variant's binary **at generation time**. The original always stays clean. If you change the PNG or text tomorrow, regenerate the variants and you are done.

See the `watermark` config block.

## Upload modes

| Mode | When to use | Pros | Cons |
|---|---|---|---|
| **Via server** (`addFile($uploadedFile)`) | small files, strict server-side validation | encrypt at rest possible, PHP validation | binary flows through PHP |
| **Direct presigned** (PUT to R2) | the normal flow | no PHP in the upload path, scales well | no encrypt at rest |
| **Multipart** (larger than 100 MB) | large videos, datasets | parallelizable parts, resumable | more client complexity |

The presign accepts `fileable_type`, `fileable_id`, and `collection` to generate the **canonical key directly**. If the model is unknown at upload time, the binary lands in `temp/` and `CreateFileAction` moves it with S3 server-side `copyObject` (zero download to PHP).

## HTTP endpoints

| Method | Route | Description |
|---|---|---|
| POST | `/laracrate/uploads/presign` | Generate a presigned URL (single PUT) |
| DELETE | `/laracrate/uploads/{disk}/{encodedKey}` | Cancel a `temp/` upload |
| POST | `/laracrate/multipart/init` | Start a multipart upload |
| POST | `/laracrate/multipart/{id}/parts` | Re-issue presigned URLs for parts |
| POST | `/laracrate/multipart/{id}/complete` | Assemble parts and register the File |
| DELETE | `/laracrate/multipart/{id}` | Abort a multipart session |
| GET | `/files/{slug}/stream` | Stream with audit (collections `access=stream`) |
| GET | `/files/{slug}/preview` | Stream without bumping `last_downloaded_at` |
| GET | `/files/{slug}/download` | Force download (Content-Disposition: attachment) |
| GET | `/laracrate/files/{slug}/status` | File status for polling after async upload |
| POST | `/laracrate/files/status` | Batch status of multiple slugs |
| POST | `/_laracrate/local/upload` | Local-driver upload (Laravel signed route) |
| GET | `/_laracrate/local/serve/{slug}` | Serve a File from the local disk |

## Sensitive content

For collections with `access=stream`, the per-request flow is:

1. Package URL signed by Laravel (TTL `route_signed_ttl`).
2. The controller validates the signature.
3. If `sensitive=true`, validates `Auth::id() === query('u')` (URL bind).
4. Policy chain, `FilePolicy::view($file, $user)` reads `PolicyRegistry`.
5. If `is_encrypted=true`, `DecryptFileAction` decrypts before serving.
6. Audit, increments `last_downloaded_at`, optionally logs IP and user_id.

The watermark **is not applied here**, it is baked into the variant.

## Artisan commands

```bash
# Abort stale multipart sessions (schedule hourly)
php artisan laracrate:abort-stale-multipart

# Delete Files past their TTL together with their binaries (schedule hourly)
php artisan laracrate:purge-expired
```

Recommended schedule in `app/Console/Kernel.php`:

```php
$schedule->command('laracrate:abort-stale-multipart')->hourly();
$schedule->command('laracrate:purge-expired')->hourly();
```

## Optional Livewire component

The package ships an optional Livewire component, `LaracrateUploader`, ready to be used as a visual uploader for any collection. It is **fully optional**: the package core works without Livewire, and your app can build its own uploader or call `addFile()`/`setFile()` directly from your forms.

```blade
<livewire:laracrate-uploader :model="$user" collection="avatar" />
<livewire:laracrate-uploader :model="$user" collection="avatar" theme="ios" layout="portrait" />
```

It supports 8 themes (`default`, `brutalist`, `material`, `ios`, `glassmorphism`, `neon`, `minimal`, `neumorphism`) and 2 layouts (`row`, `portrait`). The global theme is configured at `config('laracrate.ui.default_theme')`.

To customize the views:

```bash
php artisan vendor:publish --tag=laracrate-views
```

If you do not use Livewire, simply ignore this section. Themes and the component are not loaded unless you render them.

## Full API

### `HasFiles` trait

```php
$model->files(?$collection = null)              // MorphMany (top-level only)
$model->file($collection)                       // first file ordered by default → latest
$model->images($collection)                     // shortcut of files($collection)->where(type, image)
$model->getFile($collection)                    // first file (alias)
$model->defaultFile($collection)                // file with default=true

$model->addFile($upload, $collection, $metadata = [])
$model->setFile($collection, $upload, $metadata = [])  // single, replaces the existing
$model->deleteFile($file, $forceDelete = false)
$model->reorderFiles($collection, $orderedIds)
$model->setDefaultFile($file)

$model->fileLink($collection, $variant = null, $forceType = null): ?string
$model->fileRender($collection, $variant = null, $attrs = []): HtmlString

$model->getCollectionConfig($collection): array
$model->getDiskFor($collection): string
$model->resolveFileTenant(): ?Model
```

### `File` model

```php
// Relations
$file->parent
$file->children
$file->fileable
$file->creator
$file->tenant
$file->contents                                  // chunks from laracrate_file_contents

// Variants
$file->variant('preview.thumbnail')              // dot notation, falls back to ancestor
$file->variantOrFail('preview.thumbnail')        // throws if the chain breaks

// URLs
$file->url($forceType = null)                    // real URL or placeholder
$file->link                                      // accessor: alias of url()
$file->preview_link                              // accessor: variant('preview.thumbnail')->url('image')
$file->streamUrl()
$file->downloadUrl()
$file->placeholderFor('image')

// Storage
$file->key                                       // accessor: ltrim(path, '/')
$file->variantKey($newName)
$file->siblingKey($newName)
$file->createVariant($variantName, $overrides)

// State
$file->makeDefault()
$file->publish() / unpublish()
$file->isVariant() / isTopLevel() / isSensitive()
$file->isImage() / isVideo() / isAudio() / isDocument()
$file->createdByUser() / createdByAgent() / createdAutomatically()

// Extracted text (when embed)
$file->extractedText(): ?string                  // joins all chunks
$file->hasEmbeddings(): bool

// Authorization (delegates to PolicyRegistry)
$file->canView($user)
$file->canEdit($user)
$file->canDelete($user)

// Scopes
File::published()
File::unpublished()
File::default()
File::ordered()
File::topLevel()
File::withDescendants(2)
File::forTenant($tenant)
```

### `StorageManager` service

```php
$manager = app(\EduLazaro\Laracrate\Services\StorageManager::class);

$manager->urlFor($file)                                       // delegates to GeneratePublicUrl/Signed/Stream
$manager->diskFor($file)                                      // Storage::disk for the File
$manager->readBinary($file)                                   // full binary contents
$manager->writeBinary($disk, $key, $content, $mime)
$manager->deleteFromBackend($disk, $key)
$manager->moveServerSide($disk, $fromKey, $toKey)             // S3 copyObject
$manager->batchDelete($disk, $keys)                           // up to 1000 keys per request
$manager->presignedUpload($disk, $key, $mime, $maxSize, $minutes = 15)
$manager->withLocalCopy($file, $callback)                     // safe temporary download

$manager->getCollectionConfig($collection): array
$manager->getTypeConfig($collection, $type): array
$manager->acceptsType($collection, $type): bool
$manager->driverOf($disk): string
$manager->s3ClientOf($disk): ?S3Client
```

## Tests

`Storage::fake()` and SQLite in-memory, no Docker, no external services:

```bash
cd packages/edulazaro/laracrate
composer install
vendor/bin/phpunit
```

Tests cover the model, trait, observer, manager, policies, presigned controller, stream controller, multipart, and embeddings.

## Dependencies

- Laravel 11+ and PHP 8.2+
- `intervention/image` (image processing, watermark)
- `aws/aws-sdk-php` (S3/R2 presign and multipart)
- `edulazaro/laractions` (base Action class)
- `smalot/pdfparser` (optional, for `PdfTextExtractor`)
- `imagick` PHP extension (recommended) or `gd`
- `ffmpeg`, `ffprobe` on PATH (only if you use video)

## License

MIT
