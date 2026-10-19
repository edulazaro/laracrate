![Laracrate](art/banner.png)

# Laracrate

<p align="center">
    <a href="https://github.com/edulazaro/laracrate/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/laracrate/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/laracrate"><img src="https://img.shields.io/packagist/v/edulazaro/laracrate" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/laracrate"><img src="https://img.shields.io/packagist/dt/edulazaro/laracrate" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/laracrate"><img src="https://img.shields.io/packagist/php-v/edulazaro/laracrate" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/laracrate/blob/main/LICENSE.md"><img src="https://img.shields.io/packagist/l/edulazaro/laracrate" alt="License"></a>
</p>

Polymorphic file storage for Laravel: direct uploads to R2/S3, granular access control, sensitive-content streaming, automatic image variants, video and PDF previews, per-variant watermarks, multipart uploads, text extraction, vector embeddings, and hybrid (keyword plus semantic) search.

## Table of contents

1. [What is Laracrate?](#what-is-laracrate)
2. [Philosophy](#philosophy)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Quick start](#quick-start)
6. [Core concepts](#core-concepts)
7. [Data model](#data-model)
8. [Configuration](#configuration)
9. [Working with files from your models](#working-with-files-from-your-models)
10. [Displaying files](#displaying-files)
11. [Upload modes](#upload-modes)
12. [HTTP endpoints](#http-endpoints)
13. [Folders](#folders)
14. [File slots](#file-slots)
15. [Processing pipeline](#processing-pipeline)
16. [Images, variants and watermarks](#images-variants-and-watermarks)
17. [Video and PDF variants](#video-and-pdf-variants)
18. [Text extraction, embeddings and search (RAG)](#text-extraction-embeddings-and-search-rag)
19. [Access control and authorization](#access-control-and-authorization)
20. [Sensitive content and encryption](#sensitive-content-and-encryption)
21. [Multi-tenancy, buckets and usage](#multi-tenancy-buckets-and-usage)
22. [Livewire components and themes](#livewire-components-and-themes)
23. [React and Svelte components](#react-and-svelte-components)
24. [Localization](#localization)
25. [Artisan commands](#artisan-commands)
26. [Events](#events)
27. [API reference](#api-reference)
28. [Testing](#testing)
29. [Roadmap](#roadmap)
30. [Sponsors](#sponsors)
31. [Author](#author)
32. [License](#license)

## What is Laracrate?

**Laracrate** is a polymorphic file storage layer for Laravel built for object storage (Cloudflare R2, AWS S3) and local disks. It lets any Eloquent model own collections of files, uploads the binary straight from the browser to your bucket (the file never passes through PHP), then runs an asynchronous pipeline that derives everything else: image variants, video and PDF previews, extracted text, vector embeddings, and a searchable chunk store for retrieval-augmented generation. The backend is fully decoupled from any frontend, and optional upload components for Livewire, React and Svelte ship in the box if you want them.

What you get:

- **Direct upload to R2 and S3.** Presigned single-part PUT for normal files, and multipart upload for large files (parallel parts with per-part retries and ETag assembly).
- **Polymorphic ownership.** Three independent morphs per file (`fileable`, `creator`/`owner`, `tenant`) so a file knows what it belongs to, who created it, and which tenant scopes it.
- **Asynchronous processing pipeline.** Creating a file dispatches a queued job that runs ordered steps by file type. Your user's upload stays instant.
- **Image variants and per-variant watermarks.** Automatic resized derivatives with optional watermarking configured per variant.
- **Typed variants for video and PDF.** MP4 copies, teasers built from fragments, frames and strips of frames, and rendered PDF pages, each under the name your app gives it, plus a `preview` that names one of them.
- **Public or private files.** A private file gets a signed link straight to the disk, or is served through PHP with the view policy checked on every request: the caller chooses, per use.
- **Text extraction, embeddings and hybrid search.** Opt-in extraction, chunking, embedding generation, and a pluggable `ChunkStore` (MySQL or Meilisearch) for keyword plus semantic search.
- **Folders and slots.** A folder tree for organizing files, and file slots for "fill in this required document" workflows with quotas.
- **Multi-tenant buckets and usage accounting.** Optional dedicated bucket per tenant and storage usage reporting for quotas.
- **Optional upload components.** Three of them, for Livewire, React and Svelte, styled by a single CSS file with eleven themes. The React and Svelte ones upload to targets the app declares once, with their authorization. None of it is required to use the package.

At a glance:

```text
  Your model (HasFiles)
        |  addFile($upload, $collection, ...)
        v
  CreateFileAction ----- writes binary -----> Storage backend (R2 / S3 / local)
        |                                      (StorageManager: plain disk or tenant bucket)
        v
  File row (laracrate_files)   processing_status = pending
        |  FileObserver::created()  ->  dispatch
        v
  ProcessFileJob (queue)  ->  ProcessFileAction (orchestrator)
        |  resolves steps by file type, collection and model, ordered by priority
        v
  Pipeline steps:
    image  : extract dimensions -> optimize -> generate variants
    video  : extract dimensions -> transcode -> generate variants -> extract preview
    pdf    : extract preview
    text   : extract text -> chunk -> generate embeddings -> persist chunks
        |                                                          |
        v                                                          v
  variants (child File rows)                              ChunkStore.store()
                                                       (MysqlChunkStore | MeilisearchChunkStore)
                                                                   |
        processing_status = completed                              v
                                                          search() (keyword + semantic)
```

## Philosophy

Laracrate follows six principles. Keep them in mind and the rest of the API will feel predictable.

1. **The backend is frontend-agnostic.** There is zero coupling to Livewire, Vue, or Alpine in the storage layer. The Livewire uploader is an optional, publishable convenience, not a dependency of the core.
2. **Reuse `Storage::disk()`.** Disk credentials live in your app's `config/filesystems.php`, not here. Laracrate resolves disks through Laravel's filesystem and never duplicates that configuration.
3. **Everything is a pipeline of Actions.** Each operation is an isolated, testable, queueable class (built on `edulazaro/laractions`). The processing pipeline is just a registry of ordered steps you can extend.
4. **Processing is asynchronous.** Variants, video and PDF previews, text extraction, and embeddings all run on the queue. The user's upload is instantaneous, and a file simply stays `pending` until a worker picks it up.
5. **Who a file is for is decided per collection; how it is served, per use.** A collection is `public` or `private`. For a private file the code picks a signed link to the disk (`url()`) or the route through PHP (`streamUrl()`); only encrypted and sensitive files are always served through PHP.
6. **`path` is the full object key.** A file's `path` stores the complete key of the object in the disk (directories, filename, extension included). Use the `key` accessor to read it. Never rebuild a key by concatenating `path` and `name`.

## Requirements

Laracrate targets current Laravel. The core requirements below are enough to store files and serve them. Media processing, text extraction, and search rely on optional system tools and services that you only install for the features you use.

| What | Requirement | Notes |
| --- | --- | --- |
| PHP | `>= 8.2` | |
| Laravel | `laravel/framework >= 12.0` | |
| Actions | `edulazaro/laractions >= 1.0` | Queueable, testable action classes used throughout. |
| AWS SDK | `aws/aws-sdk-php ^3.300` | Presigned uploads and S3/R2 operations. |
| Image processing | `intervention/image ^3.0` | Image variants and optimization (needs an imagick or gd PHP extension). |
| Flysystem adapter | `league/flysystem-aws-s3-v3 ^3.0` | S3/R2 disk driver. |

The four packages above are hard Composer dependencies and are installed for you. The following are optional and unlock specific pipeline features:

| Optional dependency | Unlocks |
| --- | --- |
| `smalot/pdfparser` | Native PDF text extraction (`PdfTextExtractor`). |
| `imagick` or `gd` PHP extension | Image variant generation through Intervention Image. |
| `ffmpeg` + `ffprobe` | Video dimension extraction, transcoding, and preview frames. |
| `poppler-utils` (`pdftoppm`) or `ghostscript` | Rasterized PDF previews. |
| `meilisearch/meilisearch-php` + a Meilisearch server | The `meilisearch` chunk store for server-side hybrid search (see the Text extraction, embeddings and search section). |
| An OpenAI or Anthropic API key | Embeddings, plus OCR and transcription based extraction. |

These tools are invoked only inside queued pipeline steps, so they never block an upload. If a tool is missing, the corresponding step is skipped or the file stays unprocessed, but storing and serving the file still works.

## Installation

Laracrate installs like any Laravel package. You pull it in with Composer, publish the config, run the migrations, and point your collections at a storage disk. The package ships sensible defaults, so a basic image upload works with almost no setup.

### Prerequisites

See the Requirements section for the full list. In short you need PHP 8.2 or higher and Laravel 12 or higher.

### Upgrading from 1.x

2.0 changes the upload components, the themes, how files are served and how variants are declared. [UPGRADE.md](UPGRADE.md) lists each change and what to do; the 1.x documentation is the [README of 1.3.0](https://github.com/edulazaro/laracrate/blob/1.3.0/README.md).

### Install with Composer

```bash
composer require edulazaro/laracrate
```

The service provider is auto-discovered, so there is nothing to register manually.

### Publish the config and run migrations

Publish the config file, then migrate. The migrations create the package tables (all prefixed `laracrate_`) and are timestamped `0000_00_00` so they run before your own migrations.

```bash
php artisan vendor:publish --tag=laracrate-config
php artisan migrate
```

You only need the config tag to get started. The package loads its migrations, views, and translations directly from the package directory, so publishing those is optional and only needed when you want to customize them. The views are not published: the look is `resources/css/laracrate.css`, customised with variables (see "Themes and customising").

| Tag | What it copies | Destination |
| --- | --- | --- |
| `laracrate-config` | The config file | `config/laracrate.php` |
| `laracrate-migrations` | The migration files | `database/migrations` |
| `laracrate-translations` | The language files | `lang/vendor/laracrate` |

After publishing config, clear the cache if you have it cached:

```bash
php artisan config:clear
```

### Declare your disks

Laracrate never stores storage credentials. It reuses Laravel's `Storage::disk()`, so every collection points at a disk you define in `config/filesystems.php`. The default config in `config/laracrate.php` ships collections that reference two disks, `media` and `documents`, so define those (or rename the collections to match disks you already have).

For production on S3 or Cloudflare R2, add an `s3` driver disk. R2 is S3-compatible, so the same driver works, you just set R2 credentials and endpoint:

```php
// config/filesystems.php
'disks' => [

    // S3 or Cloudflare R2 (R2 uses the same s3 driver)
    'media' => [
        'driver'   => 's3',
        'key'      => env('R2_ACCESS_KEY_ID'),
        'secret'   => env('R2_SECRET_ACCESS_KEY'),
        'region'   => env('R2_DEFAULT_REGION', 'auto'),
        'bucket'   => env('R2_BUCKET'),
        'endpoint' => env('R2_ENDPOINT'),
        'use_path_style_endpoint' => true,
        'throw'    => true,
    ],

    'documents' => [
        'driver'   => 's3',
        'key'      => env('R2_ACCESS_KEY_ID'),
        'secret'   => env('R2_SECRET_ACCESS_KEY'),
        'region'   => env('R2_DEFAULT_REGION', 'auto'),
        'bucket'   => env('R2_DOCUMENTS_BUCKET'),
        'endpoint' => env('R2_ENDPOINT'),
        'use_path_style_endpoint' => true,
        'throw'    => true,
    ],

],
```

For local development you can point the same disk names at the local filesystem so you do not need a bucket while building:

```php
// config/filesystems.php (local dev)
'media' => [
    'driver'     => 'local',
    'root'       => storage_path('app/media'),
    'url'        => env('APP_URL') . '/storage/media',
    'visibility' => 'public',
    'throw'      => true,
],
```

**Recommended: use real object storage in development too.** The `local` driver above is the quickest way to start, but for consistency with production it is strongly recommended to point your development disks at S3-compatible storage instead: either a free Cloudflare R2 bucket, or a local MinIO instance. The direct-upload flow (presigned `PUT`, multipart, and the server-side `copyObject` move from `temp/` to the canonical key) relies on real S3/R2 semantics that the `local` driver only emulates through signed-route fallbacks, so building against R2 or MinIO surfaces problems the local driver would otherwise hide. MinIO uses the same `s3` driver shown above: point `endpoint` at your MinIO URL (for example `http://localhost:9000`) and keep `use_path_style_endpoint => true`.

Each collection declares its own disk in `config/laracrate.php`. The package raises an error on purpose if a collection has no disk, so there is no silent default. See the Configuration section for the full collection schema and the Multi-tenancy, buckets and usage section for per-tenant bucket overrides.

## Quick start

This is the fastest path from a fresh install to a stored, displayed file. The example uses the built-in `avatar` collection from the published config, which points at the `media` disk, accepts images, and is marked `single` (one file per model).

### 1. Add the trait to a model

Add `HasFiles` to any Eloquent model that should own files.

```php
use EduLazaro\Laracrate\Concerns\HasFiles;

class User extends Authenticatable
{
    use HasFiles;
}
```

### 2. Point a collection at a disk

The `avatar` collection already exists in `config/laracrate.php`:

```php
// config/laracrate.php
'collections' => [
    'avatar' => [
        'disk'   => 'media',
        'access' => 'public',
        'single' => true,
        'types'  => [
            'image' => [
                'variants' => [
                    'small'  => ['width' => 64,  'height' => 64,  'fit' => true],
                    'medium' => ['width' => 128, 'height' => 128, 'fit' => true],
                    'large'  => ['width' => 256, 'height' => 256, 'fit' => true],
                ],
            ],
        ],
    ],
],
```

Make sure the `media` disk exists in `config/filesystems.php` (see the Installation section).

### 3. Upload a file from a controller

Call `addFile()` with the uploaded file and the collection name. Because `avatar` is `single`, use `setFile()` instead if you want each new upload to replace the previous one.

```php
use Illuminate\Http\Request;

class AvatarController
{
    public function store(Request $request)
    {
        $request->validate(['avatar' => 'required|image']);

        $file = $request->user()->setFile('avatar', $request->file('avatar'));

        return back();
    }
}
```

`addFile()` accepts an `UploadedFile`, a local path string, a `FileUpload`, or a `Binary`. It returns the created `File` model (or `null`). The full signature and the optional `$data`, `$slots`, `$creator`, `$owner`, and `$folder` arguments are documented in the Working with files from your models section.

### 4. Display the file in Blade

Use `fileLink()` to get a URL, falling back to a configured placeholder when no file exists. Pass a variant name as the second argument to get a resized version.

```blade
{{-- Original (or placeholder if none) --}}
<img src="{{ $user->fileLink('avatar') }}" alt="Avatar">

{{-- The 'medium' variant --}}
<img src="{{ $user->fileLink('avatar', 'medium') }}" alt="Avatar">
```

Since `avatar` declares a single type (`image`), you do not need to pass the type argument. For multi-type collections you do. See the Displaying files section for `fileRender()` and placeholder resolution.

### 5. Run a queue worker

Originals are stored instantly, but variants and previews are generated asynchronously on the queue by `ProcessFileJob`. Until a worker processes the job, `fileLink('avatar', 'medium')` may fall back to the original or a placeholder. Run a worker so processing completes:

```bash
php artisan queue:work
```

If the queue is not running, the file stays in a pending processing state until a worker starts. That is by design, not a bug. See the Processing pipeline section for how steps are ordered and run.

## Core concepts

Before you reach for the reference sections, here is the vocabulary Laracrate uses. Every file is a row in `laracrate_files` (an Eloquent `EduLazaro\Laracrate\Models\File`) decorated with the concepts below. Read this once and the rest of the docs will click.

**Collection.** The business grouping a file belongs to, stored in the `collection` column (for example `avatar`, `documents`, `lawsuit-document`). A collection is declared in `config('laracrate.collections.*')` and decides the disk, the access (`public` or `private`), the accepted types, the variants to generate, and whether text extraction or embeddings run. When you attach a file to one of your models you always name its collection (see the Working with files from your models section).

**Type.** What a file is, stored in the `type` column and cast to the `EduLazaro\Laracrate\Enums\FileType` enum: `IMAGE`, `VIDEO`, `AUDIO`, `DOCUMENT`. Laracrate derives it from the MIME with `FileType::fromMime()` (anything that is not `image/*`, `video/*`, or `audio/*` becomes `DOCUMENT`); the format itself stays in `mime_type` and `extension`, and the pipeline picks its steps by the type.

**Mode.** How an image is shown, in the `mode` column, cast to `EduLazaro\Laracrate\Enums\FileMode`: `default`, `sphere` (a 360° photo) or `frames` (a strip of a video's frames). Its type stays `image`, so it is processed, listed and served as any other image.

**File vs variant.** A top-level **file** has `parent_id = null`. A **variant** is a derived file (a thumbnail, an MP4 copy, a video frame, a PDF page) linked to its parent by `parent_id` and named by the `variant` column with the name your config gives it. Variants are real `File` rows, created with `$file->createVariant($name, $overrides)`, and they inherit the parent scope (fileable, creator, tenant, disk, collection, access). You navigate them with dot notation: `$file->variant('portada.mini')` walks the tree and falls back to the nearest existing ancestor instead of returning null, while `$file->variantOrFail('portada.mini')` throws if any link is missing. `$file->preview()` is the variant the collection names as the type's `preview`. Helpers: `$file->isTopLevel()`, `$file->isVariant()`. See the Images, variants and watermarks and Video and PDF variants sections.

**The four polymorphic morphs.** A file carries four independent `morphTo` relations. They are orthogonal, not a hierarchy, and you set only the ones your app needs.

| Morph | Columns | Meaning |
| --- | --- | --- |
| `fileable` | `fileable_type`, `fileable_id` | What the file belongs to (a `Property`, `User`, `Service`). |
| `creator` | `creator_type`, `creator_id` | Who created it. Null for system-generated files. |
| `owner` | `owner_type`, `owner_id` | The semantic owner when it differs from the creator (uploaded on behalf of someone). Null when it matches the creator. |
| `tenant` | `tenant_type`, `tenant_id` | The multi-tenant scope. Leave null in single-tenant apps. |

Use `$file->effectiveOwner()` to get the explicit owner or fall back to the creator. See the Multi-tenancy, buckets and usage section.

**path is the full object key.** The `path` column stores the complete object key on the disk (directories, filename, and extension together), for example `user/1/avatar/01J...webp`. It is not just the directory, and you never concatenate it with `name` to build a key. Always read the key through the accessor `$file->key`, which trims a stray leading slash. Derived keys come from `$file->siblingKey($name)` (same directory) and `$file->variantKey($name)` (sibling `variants/` subdirectory). The `name` column is just `basename($path)`.

**Access.** The `access` column, cast to `EduLazaro\Laracrate\Enums\FileAccess`, says who a file is for:

| Case | Meaning |
| --- | --- |
| `PUBLIC` | Anyone. `url()` is its permanent public URL (`Storage::url()`), with no signature and no audit. |
| `PRIVATE` | Only who its view policy lets in. `url()` signs a link straight to the disk that expires; `streamUrl()` and `downloadUrl()` serve it through PHP, checking the policy on every request. |

How a private file is served is chosen where it is used, not per collection. The exception is a file marked `sensitive` or stored encrypted: it never gets a direct link, so `url()` returns its stream route. Each collection declares its access. See the Displaying files and Access control and authorization sections.

**Visibility.** A separate axis from access, stored in the `visibility` column and cast to `EduLazaro\Laracrate\Enums\FileVisibility`: `OWNER`, `GROUP`, `TENANT`, or `WORLD`. Where access governs the transport, visibility expresses the intended audience for your policies to enforce.

**Processing status.** The lifecycle of the async pipeline, in the `processing_status` column and cast to `EduLazaro\Laracrate\Enums\ProcessingStatus`: `PENDING` (just created, queued) goes to `PROCESSING` (steps running) and ends in either `COMPLETED` (all applicable steps ran) or `FAILED` (a step threw, with the message in `processing_error`). The enum offers `isTerminal()` and `isInProgress()`. This applies only to top-level files; variants are born `COMPLETED`. See the Processing pipeline section.

**Chunk.** A piece of extracted text, modeled by `EduLazaro\Laracrate\Models\FileChunk` and accessed via `$file->chunks()` (ordered by `chunk_index`) or `$file->chunk()` (the first chunk). Each chunk carries `chunk_index`, `text`, `tokens`, `metadata`, and an optional `context`. The lightweight chunk row lives in `laracrate_file_chunks`; the heavy payload (text plus embedding) lives in `laracrate_file_chunk_data`. Chunks are the unit of RAG search. See the Text extraction, embeddings and search section.

**ChunkStore.** The contract (`EduLazaro\Laracrate\Contracts\ChunkStore`) that abstracts where chunks are persisted and searched. Its methods are `store(File $file, array $chunks): int`, `getByFile(File $file): Collection`, `search(string $query, array $filters = [], array $options = []): Collection`, `deleteByFile(File $file): void`, and `driverName(): string`. The shipped drivers are `MysqlChunkStore` (LIKE plus cosine similarity in PHP) and `MeilisearchChunkStore` (native hybrid BM25 plus vector). Bind your own implementation (Qdrant, pgvector) to swap backends.

**Embedding.** A numeric vector that captures the meaning of a chunk (1536 dimensions for `text-embedding-3-small`), produced by an `EmbeddingProvider` and used for semantic similarity. Use `$file->hasEmbeddings()` to check whether every chunk has one.

**semantic_ratio.** The `0-1` weight of semantic ranking versus keyword ranking in `ChunkStore::search()`, passed in the `options` array (default `0.7`). A value of `0` is keyword only and embeds nothing (no embedding API cost), `1` is fully semantic, and anything above `0` embeds the query.

**Slot.** A named placeholder a user fills in (for example "ID front", "ID back"), modeled by `EduLazaro\Laracrate\Models\FileSlot` with extension and type restrictions and quotas. Files attach to slots through the `laracrate_file_slot_pivot` table, reachable via `$file->slots()`. See the File slots section.

**Folder.** An optional logical grouping under a fileable, modeled by `EduLazaro\Laracrate\Models\Folder` and referenced by the `folder_id` column (null means the fileable root). Move a file with `$file->moveToFolder($folder)`; the binary key on the backend never changes, only `folder_id`. See the Folders section.

**Multipart.** The protocol for large uploads (at or above the configured `multipart.threshold`, default 100 MB). The binary is split into parts of at least 5 MB, uploaded in parallel with per-part retries and reassembled by ETags. The session is tracked in `laracrate_multipart_uploads`. See the Upload modes section.

**Presigned URL.** A cryptographically signed, time-limited URL that authorizes a direct operation against the storage backend (PUT to upload, GET to download) without exposing your credentials. The browser uploads straight to R2 or S3 and your application server never touches the bytes. See the Upload modes and HTTP endpoints sections.

## Data model

Laracrate ships eight tables, all prefixed with `laracrate_`. The prefix avoids collisions with the legacy `files` table that already exists in many Laravel apps. The class names do not repeat the prefix (`File`, not `LaracrateFile`), because the `EduLazaro\Laracrate\Models` namespace already disambiguates. This follows the Cashier and Media Library convention.

The schema started with three tables and grew. Two renames matter if you are upgrading: `laracrate_file_contents` was renamed to **`laracrate_file_chunks`**, and an intermediate `laracrate_file_chunk_data` table existed for a few migrations before being folded back into `laracrate_file_chunks` (its `text` and `embedding` columns now live directly on the chunk row). The migrations are idempotent, so a fresh install lands on the final shape below.

### Tables

| Table | Model | Purpose |
| --- | --- | --- |
| `laracrate_files` | `File` | One row per file (and per variant). The central table. |
| `laracrate_file_chunks` | `FileChunk` | Extracted text split into chunks, with embeddings for search. One row per chunk. |
| `laracrate_multipart_uploads` | `MultipartUpload` | Active and historical S3/R2 multipart upload sessions. |
| `laracrate_file_slots` | `FileSlot` | Named upload slots with per-slot rules (allowed types, count limits). |
| `laracrate_file_slot_pivot` | (pivot, via `File::slots()`) | Many-to-many link between files and slots. |
| `laracrate_tenant_buckets` | `TenantBucket` | Per-tenant dedicated bucket overrides for a base disk. |
| `laracrate_folders` | `Folder` | Folder tree (parent/child plus denormalized path) for organizing files. |
| `laracrate_folderables` | `Folderable` | Aggregated storage usage counter per (owner, collection). |

The `laracrate_files.folder_id` column links a file to a folder. There is no separate file/folder pivot: a file belongs to at most one folder.

### `laracrate_files`

The `File` model (`EduLazaro\Laracrate\Models\File`) wraps this table. Its route key is `slug`. It uses soft deletes. Columns, grouped by concern:

**Identity and hierarchy**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | Primary key. |
| `slug` | ulid | Unique. Used as the public route key (never expose `id`). |
| `parent_id` | bigint, nullable | Points at the parent file. Null means top-level. Set means this row is a variant. Cascades on delete. |
| `variant` | string(50), nullable | Variant name, as the collection config names it (`thumbnail`, `web`, `portada`, ...). Unique together with `parent_id`. |
| `folder_id` | bigint, nullable | Folder this file lives in. Null means the root of its fileable. Nulls on folder delete. |

**The four morphs**

Each file carries four independent polymorphic relations (see the Core concepts section for why they stay orthogonal):

| Columns | Relation | Meaning |
| --- | --- | --- |
| `fileable_type`, `fileable_id` | `fileable()` | What the file belongs to (a User, Property, Service). |
| `creator_type`, `creator_id` | `creator()` | Who or what created the row. Null for system-generated. |
| `owner_type`, `owner_id` | `owner()` | Semantic owner when it differs from the creator. Falls back to the creator via `effectiveOwner()`. |
| `tenant_type`, `tenant_id` | `tenant()` | Multi-tenant scope (Organization, Workspace). Null for single-tenant apps. |

**Storage key**

| Column | Type | Notes |
| --- | --- | --- |
| `disk` | string | The Laravel disk name, or a `tb:{id}` token for a dedicated tenant bucket. |
| `path` | string | The full object key in the disk (directories, filename, extension). Read it through `$file->key`, never by hand. |
| `name` | string | `basename($path)` denormalized. |
| `original_name` | string | The filename as uploaded. |
| `extension` | string(10) | Lowercase extension. |
| `mime_type` | string(100) | Detected MIME type. |
| `size` | unsignedBigInteger | Bytes. |
| `digest` | string(80), nullable | Content hash for dedupe or integrity. |

**Classification**

| Column | Type | Notes |
| --- | --- | --- |
| `context` | string | Defaults to `laracrate.default_context`. Indexed. |
| `collection` | string | Defaults to `laracrate.default_collection`. Indexed. |
| `type` | enum | `image`, `video`, `audio`, `document`. Cast to `FileType`. Indexed. |
| `mode` | string | How an image is shown: `default`, `sphere` or `frames` (since migration `0000_00_00_000042`). Cast to `FileMode`. Indexed. |
| `category` | string, nullable | Free-form app category. Indexed. |

**Access flags**

| Column | Type | Notes |
| --- | --- | --- |
| `access` | enum | `public` or `private`. Defaults to `private`. Cast to `FileAccess` through `AsFileAccess`, which reads the 1.x values `signed` and `stream` as private until migration `0000_00_00_000041` rewrites them (a `stream` row also becomes `sensitive`). Indexed. |
| `visibility` | string, nullable | Free-form visibility label. The `FileVisibility` enum (`owner`, `group`, `tenant`, `world`) is available if you want to use its values. Indexed. |
| `sensitive` | boolean | Defaults to false. A sensitive file never gets a direct link: it is only served through PHP. Indexed. |
| `is_encrypted` | boolean | Defaults to false. |

**Metadata and presentation**

| Column | Type | Notes |
| --- | --- | --- |
| `title` | string, nullable | Display title. |
| `description` | text, nullable | Display description. |
| `label` | string(100), nullable | Short label. |
| `default` | boolean | Marks the default file in its (fileable + collection) group. |
| `position` | unsignedInteger | Sort order. Defaults to 0. |
| `published` | boolean | Defaults to true. Indexed. |
| `is_verified` | boolean | Defaults to false. Indexed. |
| `metadata` | json, nullable | Free-form bag. Cast to array. |

**Media metadata**

| Column | Type | Notes |
| --- | --- | --- |
| `duration` | unsignedInteger, nullable | Seconds, for video and audio. |
| `width`, `height` | unsignedInteger, nullable | Pixels, for image and video. |
| `bitrate` | unsignedInteger, nullable | For video and audio. |
| `sample_rate` | unsignedInteger, nullable | For audio. |

**Processing and audit**

| Column | Type | Notes |
| --- | --- | --- |
| `processing_status` | enum, nullable | `pending`, `processing`, `completed`, `failed`. Cast to `ProcessingStatus`. |
| `processing_error` | text, nullable | Error message when a step throws. |
| `processing_started_at` | timestamp, nullable | When the pipeline began. |
| `processing_extractor` | string(255), nullable | Class of the text extractor used. |
| `processing_provider` | string(50), nullable | Embedding or extraction provider. |
| `processing_model` | string(100), nullable | Model used for embedding or extraction. |
| `summary` | text, nullable | Optional LLM-distilled summary of the extracted content. |
| `downloads_count` | unsignedInteger | Defaults to 0. |
| `last_downloaded_at` | timestamp, nullable | Updated on each served download. |

**Indexing trackers**

These three timestamps record where a file's chunks have been indexed, so you can re-index incrementally and migrate between search backends safely (see the Text extraction, embeddings and search section).

| Column | Type | Notes |
| --- | --- | --- |
| `mysql_indexed_at` | timestamp, nullable | Chunks ready in MySQL (LIKE keyword plus cosine in PHP). Indexed. |
| `meili_indexed_at` | timestamp, nullable | Chunks pushed to Meilisearch. Indexed. |
| `storage_indexed_at` | timestamp, nullable | `{path}.chunks.jsonl` backup written. |

**Timestamps**: `created_at`, `updated_at`, plus `deleted_at` (soft deletes).

### `laracrate_file_chunks`

Wrapped by `FileChunk`. One row per chunk of extracted text. A collection with no chunking stores everything in a single row at `chunk_index` 0. The foreign key to `laracrate_files` cascades on delete.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | Primary key. |
| `file_id` | bigint | Parent file. Cascades on delete. |
| `chunk_index` | unsignedInteger | Position within the file. Defaults to 0. Unique together with `file_id`. |
| `context` | string(30), nullable | Discriminator for multi-section extractions (`text` for OCR verbatim, `description` for a generated visual description, or any opaque label). Indexed with `file_id`. |
| `text` | longText, nullable | Chunk text. The column has a FULLTEXT index, though the MySQL chunk store currently keyword-matches with SQL LIKE. |
| `embedding` | json, nullable | Embedding vector. Cast to array. Cosine similarity is computed in PHP. |
| `tokens` | unsignedInteger, nullable | Token count for the chunk. |
| `metadata` | json, nullable | Free-form bag (page numbers, etc). Cast to array. |

`File::chunks()` returns these ordered by `chunk_index`, and `File::chunk()` returns the single `chunk_index` 0 row. The older `contents()` and `content()` relations are kept as deprecated aliases for apps migrating from the previous table name.

### `laracrate_multipart_uploads`

Wrapped by `MultipartUpload`. One row per multipart upload session against an S3-compatible disk. Small files use a single PUT and never touch this table. Completed and aborted rows are kept as an audit trail rather than deleted (see the Upload modes section).

Key columns: `upload_id` (unique, the provider's id), `disk`, `key`, `mime_type`, `expected_size`, `part_size`, `total_parts`, and `status` (enum `active`, `completed`, `aborted`, `expired`, cast to `MultipartUploadStatus`). It mirrors the file morphs with `creator_*`, `tenant_*`, and `fileable_*` columns plus `collection`, and links to the resulting row via `file_id` (nulls on file delete). Lifecycle timestamps: `expires_at`, `completed_at`, `aborted_at`, plus an `error` column.

### `laracrate_file_slots` and `laracrate_file_slot_pivot`

`FileSlot` wraps `laracrate_file_slots`: named upload slots with rules (see the File slots section). Columns: `tenant_type`/`tenant_id` and `context_type`/`context_id` for scoping, `name`, `description`, `color`, `allowed_extensions` (json array), `allowed_types` (json array of `FileType` values), `max_files_per_creator`, `max_files_total`, and `position`.

`laracrate_file_slot_pivot` is the many-to-many link, with `file_id` and `file_slot_id` (both cascade on delete, unique together). It has no dedicated model: reach it through `File::slots()` or `FileSlot::files()`.

### `laracrate_tenant_buckets`

`TenantBucket` wraps this table: one row overrides a single config disk (the `base_disk`) with a dedicated bucket for one tenant (see the Multi-tenancy, buckets and usage section). Columns: `tenant_type`/`tenant_id`, `base_disk`, `bucket`, `public_url` (nullable), `credentials` (longText, cast to `encrypted:array` for bring-your-own-account setups), `is_active`, and `label`. Unique on (`tenant_type`, `tenant_id`, `base_disk`). `toDiskConfig()` merges the base disk config with the override.

### `laracrate_folders`

`Folder` wraps this table: a parent/child folder tree with a denormalized `path` kept in sync by an observer (see the Folders section). It uses soft deletes. Columns: a `folderable_*` morph (the tree owner), `parent_id` (cascades on delete, null means root), `name`, `path` (string(500)), a `creator_*` morph, and a `metadata` json bag. Unique on (`folderable_type`, `folderable_id`, `path`).

### `laracrate_folderables`

Despite the name, this is not a pivot. `Folderable` wraps an aggregated usage counter, one row per (`folderable_type`, `folderable_id`, `collection`), maintained in real time by the file observer when a collection has `track_usage` enabled. Columns: the `folderable_*` morph, `collection`, `total_size_bytes`, `files_count`, `folders_count`, and `last_recomputed_at`. Unique on (`folderable_type`, `folderable_id`, `collection`). The `laracrate:recompute-usage` command rebuilds it if you suspect drift.

## Configuration

Laracrate is configured entirely through one published file, `config/laracrate.php`. Publish it during installation (see the Installation section), then shape it to your app. The defaults are safe and runnable as-is: every key below has a working default, so you only override what your app actually needs.

This section walks the file top to bottom. For the deeper behavior that some keys drive (the pipeline, embeddings, watermarks), the relevant H2 section is cross-referenced rather than re-explained here.

```bash
php artisan vendor:publish --tag=laracrate-config
```

### Default collection and context

Applied to the schema when a `File` row is inserted without an explicit `collection` or `context`. This happens, for example, when a variant is created and inherits from its parent. Any string is valid; the convention is `default`. Changing these values updates the column DEFAULT only if you re-run the migration.

```php
'default_collection' => 'default',
'default_context'    => 'default',
```

### Defaults per file type

The `defaults` block declares the **safe-by-default** allowlist of MIME types, extensions, max sizes, and processing options for each of the four file types (`image`, `document`, `audio`, `video`). Any collection that does not declare its own `accepted_mime_types` or `accepted_extensions` inherits these.

Some formats are deliberately excluded from the allowlist and must be opted into explicitly per collection:

- **SVG**, because it can carry `<script>`.
- **ICO**, because of legacy CVEs.
- **HTML, JS, PHP, EXE, BAT, SH**, because they are executable.
- **ZIP, RAR, 7Z**, because they are containers with zip-slip and hidden-content vectors.

To allow any of these, override `accepted_mime_types` and `accepted_extensions` in the specific collection along with your own validation policy.

| Type | `max_file_size` (KB) | Notable defaults |
| --- | --- | --- |
| `image` | `10240` (10 MB) | `format: webp`, `quality: 90`, `variant_quality: 85`, `max_width: 1920`, `max_height: 1080`, plus `thumbnail` (300x300), `medium` (800x800), `large` (1600x1600) variants |
| `document` | `20480` (20 MB) | PDF, Word, OpenDocument, RTF, plain text, Markdown, Excel, CSV, PowerPoint, EPUB. No variants (documents use rasterized previews) |
| `audio` | `5120` (5 MB) | MP3, WAV, OGG, M4A, FLAC, AAC, Opus, WebM |
| `video` | `102400` (100 MB) | MP4, MOV, WebM, M4V, MKV, AVI, OGV |

The image `accepted_extensions` are `jpeg, jpg, png, gif, webp, heic, heif, bmp, tiff`. See the Images, variants and watermarks section for how `format`, `quality`, and `variants` drive processing.

### Collections

A **collection** is the upload policy for one business context (avatars, a gallery, identity documents). Each entry declares where files land, who can read them, and how they are processed. Collections are the central concept of the package, so this is the largest block.

```php
'collections' => [
    'avatar' => [
        'disk'   => 'media',
        'access' => 'public',
        'single' => true,
        'types'  => [
            'image' => [
                'variants' => [
                    'small'  => ['width' => 64,  'height' => 64,  'fit' => true],
                    'medium' => ['width' => 128, 'height' => 128, 'fit' => true],
                    'large'  => ['width' => 256, 'height' => 256, 'fit' => true],
                ],
            ],
        ],
    ],
],
```

The package ships four example collections (`avatar`, `gallery`, `documents`, `identity`) so you can see the shape. Replace them with your own.

#### Anatomy of a collection entry

| Key | Type | Purpose |
| --- | --- | --- |
| `disk` | string | The `Storage::disk()` name from your `config/filesystems.php`. The package never duplicates credentials; it resolves this disk. There is no default, a missing disk is intentionally an error. |
| `access` | string | `public` or `private` (the default). Who the files are for; how a private one is served is chosen where it is used (`url()` or `streamUrl()`). The 1.x values `signed` and `stream` still read as private, `stream` also as `sensitive`. See the Displaying files and Access control sections. |
| `single` | bool | `true` keeps one file per owner. Replacing the file removes the previous one. |
| `sensitive` | bool | The files never get a direct link: `url()` returns the stream route, which checks the view policy and logs each access. See the Sensitive content section. |
| `encrypt` | bool | Encrypts the binary before it is stored on the backend. See the Sensitive content and encryption section. |
| `ttl_hours` | int | Files in the collection expire after this many hours. The `laracrate:purge-expired` command (run hourly) deletes them, cascading to variants and the backend binary. Useful for `temp_uploads`, expiring exports, or unpromoted drafts. |
| `quota_bytes` | int | Storage limit your app can check with `UsageReporter` before accepting more uploads. The package does not enforce quotas itself. See the Multi-tenancy, buckets and usage section. |
| `track_usage` | bool | Maintain live per-owner usage counters (file count and total bytes) for this collection in the `laracrate_folderables` table, updated by the file observer on create and delete, and rebuildable with `laracrate:recompute-usage`. See the Multi-tenancy, buckets and usage section. |
| `component` | string | Blade component used for default rendering. |
| `placeholder` | string | Fallback asset when the file does not exist (highest priority in placeholder resolution, below). |
| `types` | array | Per-type processing config (`image`, `document`, `audio`, `video`), keyed by type. Each type block can carry `accepted_mime_types`, `accepted_extensions`, `max_file_size`, `variants`, `preview`, and so on, overriding the global `defaults`. The `image` block's `modes` (`['default', 'sphere']`) says whether 360° photos are told apart. |
| `variants` | array | Inside a type block. Image variants: `['name' => ['width' => ..., 'height' => ..., 'fit' => bool, 'watermark' => bool]]` (see the Images, variants and watermarks section). Video and PDF variants carry a `type` (`video`, `image`, `frames`); see the Video and PDF variants section. |
| `preview` | string | Inside a type block. The name of one of its variants (dot notation for nested ones, `'portada.mini'`): `$file->preview()` returns it. See the Video and PDF variants section. |
| `extract` / `extract_text` | bool, string or array | When to extract text from the file: `'always'` (`true`), `'on_demand'` or `'never'` (`false`), or a list of types. Can also be set per type inside `types` (`extract_text` is a legacy alias for `extract`). See the per-type extraction note below and the Text extraction section. |
| `embed` | bool, string or array | Whether to generate embeddings, with the same values as `extract`. See the Text extraction, embeddings and search section. |
| `actions` | array | Custom actions to attach to the collection. |
| `models` | array | Per-model scoping (covered next). |

#### The `models` block: per-model scoping

By default any model using `HasFiles` can write to a collection with the same config. Declaring `models` restricts the collection to specific owner types and merges a per-model override on top of the base config. Resolution lives in `EduLazaro\Laracrate\Support\CollectionConfig::resolve()`.

```php
'documents' => [
    'disk'   => 'documents',
    'access' => 'private',
    'models' => [
        // Cases never hand out a direct link.
        'case'         => ['sensitive' => true],
        // Organizations accept larger documents.
        'organization' => ['types' => ['document' => ['max_file_size' => 51200]]],
    ],
],
```

Semantics when `models` is present:

- Only the listed keys may use the collection. Keys are matched against the morph alias or the fully qualified class name, normalized through `Relation::morphMap()`. A model not listed triggers `EduLazaro\Laracrate\Exceptions\CollectionNotAllowedForModel`.
- The per-model override is merged over the base with `array_replace_recursive`, so nested structures (like `variants`) merge key by key.
- The `models` key itself is stripped from the resolved config.
- A per-model override cannot relocate the binary. The object key is always `{fileable_morph}/{id}/{collection}/{file}` (tenant-prefixed when the file has a tenant), built by `CreateFileAction`. It is not configurable through a `path` key.
- Resolving with no model (`CollectionConfig::resolve($collection)` with a null second argument) returns the base config without merging, which is what tooling that iterates collections without a model context receives.

You can check whether a collection is scoped with `CollectionConfig::isRestricted($collection)`.

#### Per-type `extract` and `embed`

`extract`, `embed` and, for videos, `visual` resolve to one of three modes through `EduLazaro\Laracrate\Support\ExtractionResolver`:

| Value | Mode | Meaning |
| --- | --- | --- |
| `'always'` or `true` | always | Runs when the file is uploaded. |
| `'on_demand'` | on demand | Runs only once someone requests it with `$file->requestExtraction()`. |
| `'never'` or `false` | never | Never runs. |

Declare them for the whole collection or per type inside `types`. The type wins:

```php
'documents' => [
    'extract' => 'always',             // default for every type
    'embed'   => true,
    'types'   => [
        'document' => ['max_file_size' => 51200],
        'audio'    => ['max_file_size' => 51200, 'extract' => 'on_demand'],
        'video'    => ['max_file_size' => 204800, 'extract' => 'on_demand', 'visual' => 'never'],
    ],
],
```

The collection-level value also accepts a list of types, which means `'always'` for those types and `'never'` for the rest. It matches the file's type by exact value or by the `type.` prefix:

```php
'extract' => ['document', 'image']        // only these file types
'extract' => ['document', 'video.visual'] // videos too, with frame description
```

`extract` decides whether the text pipeline runs for a file and `embed` whether its text is embedded, so `embed` never extracts a type that `extract` excludes. When `extract` is not declared at all, the pipeline follows `embed`. `visual` describes video frames on top of the transcript and only applies when the video is extracted. The legacy boolean key `extract_text` is still honored. An unknown value throws an `InvalidArgumentException`.

Apps can register a per-file override with `ExtractionResolver::setOverrideResolver(callable)`. The callable receives the `File` and returns an array in the same shape as the collection config (or null), for example per organization or per case. Whatever the override declares wins over the collection config, and its `types` blocks are merged type by type. See the Text extraction section.

#### On-demand extraction

On-demand types are skipped when the file is uploaded: the file ends up `completed`, with no text. Request the processing when it is needed, from a menu action or from an agent tool:

```php
if ($file->canRequestExtraction()) {
    $file->requestExtraction(auth()->user());            // queued
    $file->requestExtraction(auth()->user(), sync: true); // inline, e.g. inside a tool
}
```

`requestExtraction()` records who asked and when in the file's `metadata` (`extraction_requested_at`, `extraction_requested_by_type`, `extraction_requested_by_id`), which turns the on-demand modes on for that file, and processes it again from the semantic extraction band (priority 60). The original is not transcoded again and its variants are not regenerated. It returns `false` when there is nothing on demand for the file. `canRequestExtraction()` is true until the file has been requested, and again if that processing failed.

### Placeholders

The fallback chain when a file or variant does not exist or is not the type the render expects. Override these in your published config.

```php
'placeholders' => [
    'default'  => '/img/laracrate/file.svg',
    'image'    => '/img/laracrate/image.svg',
    'video'    => '/img/laracrate/video.svg',
    'audio'    => '/img/laracrate/audio.svg',
    'document' => '/img/laracrate/document.svg',
],
```

Resolution runs from most specific to most general:

1. `config('laracrate.collections.{name}.placeholder')`
2. `config('laracrate.placeholders.{type}')`
3. `config('laracrate.placeholders.default')`

#### Dynamic placeholders (initials avatars, generated SVGs)

A placeholder can be a **callable** instead of a string. When it is, `fileLink()` and `fileRender()` invoke it with `(string $collection, string $type, Model $model)` and use the returned string as the URL. This is how you render a generated fallback (an initials avatar, a `ui-avatars` URL, a per-model SVG) when a model has no file.

Use a **callable array** (`[Class::class, 'method']`), not a `Closure`. Closures are not serializable, so a `Closure` placeholder breaks `php artisan config:cache` in production.

```php
// config/laracrate.php
'collections' => [
    'avatar' => [
        'disk'        => 'media',
        'access'      => 'public',
        'single'      => true,
        'placeholder' => [\App\Support\InitialsAvatar::class, 'placeholderFor'],
    ],
],
```

```php
// app/Support/InitialsAvatar.php
namespace App\Support;

class InitialsAvatar
{
    // The signature the package calls: (collection, type, model).
    public static function placeholderFor($collection, $type, $model): ?string
    {
        return self::dataUri($model?->name);
    }

    public static function dataUri(?string $name, int $size = 200): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $initials = strtoupper(mb_substr($name, 0, 1));      // 1 to 2 letters from the name
        $bg       = '#1E40AF';                               // derive a color from the name if you want variety
        $font     = (int) round($size * 0.42);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '">'
             . '<rect width="100%" height="100%" fill="' . $bg . '"/>'
             . '<text x="50%" y="50%" fill="#fff" font-size="' . $font . '" font-family="sans-serif"'
             . ' text-anchor="middle" dominant-baseline="central">'
             . htmlspecialchars($initials, ENT_QUOTES | ENT_XML1) . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
```

Now `$user->fileLink('avatar', 'small')` returns the uploaded avatar when it exists, or the inline initials SVG when it does not, with no null checks in your views. A callable placeholder needs the model, so it resolves only through `fileLink()` and `fileRender()` (which carry it), not through the bare `$file->placeholderFor()`. String placeholders work everywhere.

### URL strategy

How long the signed link of a private file lasts when the caller does not say. `$file->url(expires: ...)` sets it per call; `streamUrl()` and `downloadUrl()` are not signed and never expire. See the Displaying files section.

```php
'urls' => [
    'signed_ttl'       => 5,
    'signed_cache_ttl' => 4,
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `signed_ttl` | `5` | Minutes the default signed link of a private file stays valid. |
| `signed_cache_ttl` | `4` | Minutes that default link is cached server-side, so a page with many files signs each one once. A link with its own `expires` is not cached. |

### Policies

The package uses `PolicyRegistry` as the canonical place to declare authorization per `fileable_type`. When `register_gate` is `true` (default), the service provider also binds `FilePolicy` to the Laravel Gate so you can use the native ergonomics.

```php
'policies' => [
    'register_gate' => true,
],
```

With the bridge on, the Gate abilities `view`, `update`, and `delete` map to the registry methods `canView`, `canEdit`, and `canDelete`, so `@can('view', $file)`, `$user->can('update', $file)`, and `Route::middleware('can:view,file')` all work. Set `register_gate => false` if your app already registers its own `FilePolicy`. See the Access control and authorization section.

### Streaming

Routing and audit options for the routes that serve a file through PHP (`streamUrl()`, `downloadUrl()`) and the viewer's data.

```php
'stream' => [
    'route_prefix'        => 'laracrate/files',
    'route_name_prefix'   => 'laracrate.files',
    'middleware'          => ['web', 'auth'],
    'increment_downloads' => true,
    'log_access'          => true,
],
```

The `laracrate/files` prefix avoids collisions with an existing `FileController` under `/files/...`, which is common. Keep `auth` in the middleware: these routes carry no signature, so the session and the view policy are what let a request in. `increment_downloads` bumps the download counter on `download`, and `log_access` logs each access. See the HTTP endpoints section.

### Status polling

Endpoints to poll processing status after an async upload.

```php
'status' => [
    'route_prefix' => 'laracrate/files',
    'middleware'   => ['web', 'auth'],
],
```

`GET /laracrate/files/{slug}/status` returns one file, `POST /laracrate/files/status` accepts a batch of slugs. See the HTTP endpoints section.

### Uploads

Routing and a disk allowlist for the direct presigned upload endpoints. The multipart block inherits this middleware when its own is `null`.

```php
'uploads' => [
    'route_prefix'  => 'laracrate/uploads',
    'middleware'    => ['web', 'auth'],
    'allowed_disks' => [],
    'targets'       => base_path('routes/uploads.php'),
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `route_prefix` | `laracrate/uploads` | URL prefix for the upload endpoints (presign, cancel, and the grant based sign, confirm and receive). |
| `middleware` | `['web', 'auth']` | Middleware for the upload route group. Authorization is your app's responsibility. |
| `allowed_disks` | `[]` | Allowlist of disks the plain `presign` and `cancel` endpoints accept. Empty means the disks your configured collections use, never any other disk (`public`, `local`...). The grant based endpoints do not read it: their disk always comes from the grant's collection. |
| `targets` | `routes/uploads.php` | The file where the app declares its [upload targets](#upload-targets) for the React and Svelte components, read the first time one is asked for. A published config without the key has none. |

A collection may also say how the components send its bytes with `'upload' => 'direct'` (presigned PUT to the bucket) or `'upload' => 'server'` (through PHP). Without it each component uses its own default: the dropzones go direct, the single file uploader goes through the server. A collection with `'encrypt' => true` always goes through the server, the only place it can be encrypted.

These are the defaults; override them in your published config to restrict `allowed_disks` or change the prefix or middleware. See the HTTP endpoints and Upload modes sections.

### Multipart upload

Tuning for large uploads to S3/R2. The server does not force multipart; the frontend chooses based on `file.size`. These values are the recommended thresholds.

```php
'multipart' => [
    'threshold'       => 100 * 1024 * 1024,  // 100 MB
    'part_size'       => 10  * 1024 * 1024,  // 10 MB
    'expire_minutes'  => 60,
    'url_ttl_minutes' => 60,
    'route_prefix'    => 'laracrate/multipart',
    'middleware'      => null,
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `threshold` | 100 MB | Below this the client should use a single presigned PUT, at or above it multipart. |
| `part_size` | 10 MB | Bytes per part (S3 minimum is 5 MB). 10 MB means 100 parts for 1 GB, 800 for 8 GB. |
| `expire_minutes` | `60` | TTL of the multipart session. After it, `laracrate:abort-stale-multipart` aborts it. |
| `url_ttl_minutes` | `60` | TTL of the per-part presigned URLs. |
| `route_prefix` | `laracrate/multipart` | URL prefix for the multipart endpoints. |
| `middleware` | `null` | Middleware for the route group. `null` inherits from the uploads block. |

See the Upload modes and HTTP endpoints sections.

### Image

Image processing options used by the optimize and variant steps. Do not confuse `image.driver` (used for variants and optimization) with `pdf_preview_engine` (used for PDF rasterization).

```php
'image' => [
    'driver'             => 'imagick',
    'optimize_originals' => false,
    'max_width'          => 1920,
    'max_height'         => 1920,
    'quality'            => 85,
],
```

`driver` is `imagick` (recommended) or `gd`. When `optimize_originals` is `true`, the original is re-encoded to webp within `max_width`/`max_height` at `quality`. In a collection whose image type takes the `sphere` mode (`'image' => ['modes' => ['default', 'sphere']]`), `DetectSphereStep` gives a photo mode `sphere` when its XMP declares an equirectangular projection (`GPano:ProjectionType="equirectangular"`, as 360 cameras and Google Photo Sphere write it); a wide photo is not a 360 one. See the Images, variants and watermarks section.

### PDF preview engine

Selects the engine that rasterizes a PDF page into a PNG for a page variant (`'type' => 'image'` in the document `variants`).

```php
'pdf_preview_engine' => 'auto',
```

| Value | Requirements | Notes |
| --- | --- | --- |
| `pdftoppm` | poppler-utils (`apt install poppler-utils`) | Does not need Ghostscript or any change to ImageMagick `policy.xml`. |
| `imagick` | PHP imagick extension, Ghostscript (`gs`), and the PDF coder enabled in ImageMagick `policy.xml` | Heavier setup. |
| `auto` | tries `pdftoppm`, falls back to `imagick` | Default. |

You can override the engine per variant:

```php
'variants' => ['portada' => ['type' => 'image', 'page' => 1, 'width' => 600, 'engine' => 'pdftoppm']],
```

See the Video and PDF variants section.

### Video

Defaults for ffmpeg-based transcoding (`'transcode' => true` and the video copies in `variants`) when a variant does not set its own `max_width`, `max_height` or `bitrate_kbps`.

```php
'video' => [
    'max_width'    => 1920,
    'max_height'   => 1920,
    'bitrate_kbps' => 2500,
],
```

See the Video and PDF variants section.

### Encryption

Driver used to encrypt the binary for collections with `encrypt: true`.

```php
'encryption' => [
    'driver' => 'laravel',
],
```

`laravel` uses the framework `Crypt` facade. See the Sensitive content and encryption section.

### Embeddings

Opt-in text extraction and vector embeddings. `enabled` is the master switch: when `false`, nothing is embedded even if a collection asks for it.

```php
'embeddings' => [
    'enabled'           => false,
    'provider'          => 'openai',
    'api_key'           => env('LARACRATE_EMBEDDINGS_API_KEY'),
    'model'             => env('LARACRATE_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
    'dimensions'        => 1536,
    'chunk_size'        => 1000,
    'chunk_overlap'     => 100,
    'batch_size'        => 16,
    'extractors'        => [],
    'min_text_per_file' => 100,
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `enabled` | `false` | Master switch for the whole feature. |
| `provider` | `openai` | Provider implementing `EmbeddingProvider`. The package ships an OpenAI provider; the real binding is done in `LaracrateServiceProvider`. |
| `api_key` | env `LARACRATE_EMBEDDINGS_API_KEY` | If null, the OpenAI provider falls back to `OPENAI_API_KEY`. |
| `model` | `text-embedding-3-small` | Provider model, overridable per environment. |
| `dimensions` | `1536` | Vector dimensions. Fixed by the model, change only when you change the model. |
| `chunk_size` | `1000` | Approximate tokens per chunk. `0` disables chunking (one row per file). |
| `chunk_overlap` | `100` | Token overlap between consecutive chunks. |
| `batch_size` | `16` | Chunks per request to the provider. |
| `extractors` | `[]` | Ordered chain of text extractors. Empty means the built-in defaults. |
| `min_text_per_file` | `100` | Minimum characters an extractor must produce to count as successful. Below this, the next extractor in the chain is tried. |

The `extractors` chain runs in order; if one returns less than `min_text_per_file` characters, the next is tried. A typical scanned-PDF chain is `PdfTextExtractor` (fast, native PDFs) then `OcrPdfTextExtractor` (LLM OCR) then `PlainTextExtractor`. See the Text extraction, embeddings and search (RAG) section for the full behavior.

### Chunks

Selects the `ChunkStore` backend that persists and searches text chunks.

```php
'chunks' => [
    'driver' => env('LARACRATE_CHUNKS_DRIVER', 'mysql'),
],
```

| Driver | Storage | Notes |
| --- | --- | --- |
| `mysql` | `laracrate_file_chunks` (SQL LIKE keyword match plus cosine similarity in PHP) | No external dependencies. Scales well up to roughly 5K chunks per scope. |
| `meilisearch` | A Meilisearch index with user-provided embeddings | Native hybrid search (BM25 plus vector) with `semanticRatio` server-side. Requires `meilisearch/meilisearch-php` and a `Meilisearch\Client` binding in your app. |

Custom backends (Qdrant, pgvector) can bind `ChunkStore` directly. See the Text extraction, embeddings and search (RAG) section.

### Meilisearch

Applies only when `chunks.driver` is `meilisearch`.

```php
'meilisearch' => [
    'index'    => env('LARACRATE_MEILISEARCH_INDEX', 'laracrate_file_chunks'),
    'embedder' => env('LARACRATE_MEILISEARCH_EMBEDDER', 'default'),
],
```

### OCR

Config for `OcrPdfTextExtractor`, the fallback for scanned PDFs. The provider is selectable via env, and each provider's API key falls back to the generic key for that provider.

```php
'ocr' => [
    'provider'  => env('LARACRATE_OCR_PROVIDER', 'anthropic'),

    // Fallback language for the auto-generated image description when the
    // image has no visible text to infer the language from (image OCR only).
    'locale'    => 'en',

    'anthropic' => [
        'api_key' => env('LARACRATE_ANTHROPIC_API_KEY') ?: env('ANTHROPIC_API_KEY'),
        'model'   => env('LARACRATE_OCR_ANTHROPIC_MODEL', env('LARACRATE_OCR_MODEL', 'claude-haiku-4-5')),
    ],
    'openai' => [
        'api_key' => env('LARACRATE_OPENAI_API_KEY') ?: env('OPENAI_API_KEY'),
        'model'   => env('LARACRATE_OCR_OPENAI_MODEL', env('LARACRATE_OCR_MODEL', 'gpt-4o-mini')),
    ],
],
```

`provider` is `anthropic` (default, model `claude-haiku-4-5`) or `openai` (model `gpt-4o-mini`). `locale` only affects image OCR (`OcrImageTextExtractor`): the image description follows the visible text's language, falling back to this locale when the image has no text. See the Text extraction section.

### Watermark

Settings for the watermark embedded into specific variants. The original (master) is never watermarked; only variants that declare `'watermark' => true` are. The defaults here are global, applied wherever a variant opts in.

```php
'watermark' => [
    'image_path' => env('LARACRATE_WATERMARK_IMAGE', null),
    'size'       => 0.40,
    'opacity'    => 30,
    'position'   => 'center',
    'text'       => [
        'content'         => null,
        'font_size_ratio' => 0.0195,
        'color'           => 'rgba(255, 255, 255, 0.60)',
        'position'        => 'bottom-left',
        'padding'         => 20,
        'font_path'       => null,
    ],
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `image_path` | env `LARACRATE_WATERMARK_IMAGE`, else `null` | Absolute path or path relative to `public_path()` of the PNG to overlay. `null` applies no image. |
| `size` | `0.40` | Watermark width as a fraction of the variant width (0.0 to 1.0). |
| `opacity` | `30` | Overlay opacity (0 to 100). |
| `position` | `center` | One of `center`, `top-left`, `top-right`, `bottom-left`, `bottom-right`. |
| `text.content` | `null` | Optional auxiliary text: `null`, a fixed string, or a `closure(File): ?string` for dynamic text (set via a provider or published config, not env). |
| `text.font_size_ratio` | `0.0195` | Font size as a fraction of the image width. |
| `text.color` | `rgba(255, 255, 255, 0.60)` | CSS rgba color. |
| `text.position` | `bottom-left` | One of `bottom-left`, `bottom-right`, `top-left`, `top-right`. |
| `text.padding` | `20` | Padding from the edge, in pixels. |
| `text.font_path` | `null` | Path to a `.ttf` font, or `null` for the system font. |

See the Images, variants and watermarks section for the mechanics.

### Queue

Routing for the package jobs (variants, previews, embeddings) dispatched by `ProcessFileJob`.

```php
'queue' => [
    'connection' => env('LARACRATE_QUEUE_CONNECTION', null),
    'name'       => env('LARACRATE_QUEUE_NAME', 'default'),
],
```

`connection` of `null` uses your default queue connection. All processing runs on the queue by design, so the user's upload stays instant. See the Processing pipeline section.

## Working with files from your models

The **`HasFiles`** trait is the main API surface you interact with day to day. Add it to any Eloquent model (User, Property, Service, Organization) and that model can hold files grouped into named **collections** (`avatar`, `gallery`, `documents`, and so on). Files attach through a polymorphic relation, so one model can own many collections at once.

```php
use EduLazaro\Laracrate\Concerns\HasFiles;

class User extends Authenticatable
{
    use HasFiles;
}
```

Collections are declared in `config/laracrate.php` (see the Configuration section). You usually do not need to do anything else on the model. If you want per-model tweaks to a collection (a different disk, a different placeholder, a render component), declare the optional `$fileCollections` property. It is merged recursively over the base config (`array_replace_recursive`), so you only override the keys you care about:

```php
class User extends Authenticatable
{
    use HasFiles;

    protected array $fileCollections = [
        'avatar' => [
            'component'   => 'user-avatar',
            'placeholder' => '/img/default-avatar.png',
        ],
    ];
}
```

### Adding files

Use **`addFile()`** to append a file to a collection. It accepts an `UploadedFile`, a local path string, a `Binary`, or a `FileUpload` (the value object returned by direct-to-bucket uploads, see the Upload modes section).

```php
$user->addFile($request->file('photo'), 'gallery');
```

The full signature:

```php
public function addFile(
    UploadedFile|Binary|FileUpload|string $file,
    string $collection,
    array $data = [],
    array $slots = [],
    ?Model $creator = null,
    ?Model $owner = null,
    ?Folder $folder = null,
): ?File
```

| Argument | Purpose |
| --- | --- |
| `$file` | The upload source: `UploadedFile`, path string, `Binary`, or `FileUpload`. |
| `$collection` | The collection name, must be declared in config. |
| `$data` | Per-file attributes (see below). |
| `$slots` | File slot keys to attach the file to (see the File slots section). |
| `$creator` | Who created it. Defaults to `auth()->user()`. |
| `$owner` | Semantic owner when different from the creator. |
| `$folder` | A `Folder` belonging to this same model (see the Folders section). |

The `$data` array maps to dedicated columns: `title`, `description`, `category`, `visibility`, `label`, `default`, `position`, plus a `metadata` key that is stored as-is in the JSON `metadata` column. Any other key throws `InvalidArgumentException`, so a typo fails loudly instead of being silently dropped. To store arbitrary data, nest it under `metadata`.

```php
$user->addFile($request->file('cv'), 'documents', [
    'title'    => 'Resume 2026',
    'category' => 'application',
    'metadata' => ['source' => 'web'],
]);
```

Processing (variants, previews, text extraction, embeddings) runs asynchronously on the queue after the file is created, so `addFile()` returns instantly. See the Processing pipeline section.

### Replacing a single file

Use **`setFile()`** for "one file per collection" cases like an avatar or a logo. It force-deletes every existing file in the collection (and their variants), then adds the new one. Pass `null` to clear the collection without adding anything.

```php
$user->setFile('avatar', $request->file('avatar'));

$user->setFile('avatar', null); // remove the current avatar
```

```php
public function setFile(
    string $collection,
    UploadedFile|Binary|FileUpload|string|null $file,
    array $data = [],
    ?Model $creator = null,
    ?Model $owner = null,
): ?File
```

### Deleting and reordering

```php
$user->deleteFile($file);                  // soft delete
$user->deleteFile($file, forceDelete: true); // also purge the binary now

$user->reorderFiles('gallery', [42, 17, 9]); // assigns position 0, 1, 2 by index
```

`reorderFiles()` takes the file IDs in the order you want and writes `position` by array index. It only touches top-level files that belong to this model and collection, so it is safe to drive directly from a drag-and-drop UI.

### Defaults

A collection can mark one file as its default (the chosen avatar among several uploads, the cover of a gallery). Set it from the parent model or from the file itself:

```php
$user->setDefaultFile($file); // unsets any other default in the collection, returns the fresh File

$file->makeDefault();         // same, called on the File model
```

### Querying files

| Method | Returns | Notes |
| --- | --- | --- |
| `files(?string $collection = null)` | `MorphMany` | Top-level files ordered by `position` then `id`. Filter by collection when passed. |
| `file(string $collection)` | `?File` | The default (if any) or otherwise the most recent file in the collection. |
| `defaultFile(string $collection)` | `?File` | Only the file flagged `default = true`. |
| `images(?string $collection = null)` | `MorphMany` | Files where `type = image`. |

```php
$avatar  = $user->file('avatar');        // single best file
$gallery = $user->files('gallery')->get(); // all, in order
$photos  = $user->images()->get();         // every image across collections
```

`files()` and `images()` return query builders, so you can keep chaining (`->where(...)`, `->paginate(...)`). `file()` and `defaultFile()` execute and return a `File` or `null`.

## Displaying files

Once a file exists, you render it from a URL. A file has three, and you pick the one the place needs:

| Method | What it is | Expires |
| --- | --- | --- |
| `$file->url()` | A direct link. Public file: its public URL. Private file: a link signed straight to the disk (an R2/S3 presigned GET, or a signed local route on a `local` disk). Encrypted or sensitive file: its `streamUrl()`, since PHP has to decrypt it or check each access. | Private links: after `laracrate.urls.signed_ttl` (5 min), or when you say with `url(expires: ...)`. S3 and R2 refuse more than 7 days. |
| `$file->streamUrl()` | The file through PHP, shown in the browser. Behind the session, the view policy is checked on every request; `Range` is honoured, so video seeks. | Never. |
| `$file->downloadUrl()` | The file through PHP as an attachment, counted in `downloads_count`. | Never. |

```php
$file->url();                               // signed for 5 minutes when private
$file->url(expires: now()->addHours(3));    // a video watched for a while
$file->streamUrl();                         // a link stored in a document, or a page left open
```

Use `url()` where the page renders and is read right away, and `streamUrl()` where the link must keep working (an image embedded in a document, a long session). A signed link is only checked when it is made; the stream route checks the policy every time.

The simplest path is to grab the file and read its **`url()`**:

```blade
@php($avatar = $user->file('avatar'))

@if ($avatar)
    <img src="{{ $avatar->url() }}" alt="Avatar">
@endif
```

For Blade convenience the model exposes accessors so you can skip the method call:

| Accessor | Equivalent to |
| --- | --- |
| `$file->link` | `$file->url()` |
| `$file->preview_link` | `$file->preview()?->url('image')`; for an image without a preview, the image itself; anything else, the image placeholder |

```blade
<img src="{{ $file->link }}">
<img src="{{ $file->preview_link }}"> {{-- the variant the type names as its preview --}}
```

`preview_link` never throws: if the disk is unreachable or the variant is missing, it returns the configured placeholder so one broken file cannot break the page. It is a direct link (`url()`), so it follows the same expiry; for one that lasts, use `$file->preview()?->streamUrl()`.

### Navigating variants

A file can have derived variants (a `thumbnail`, a `small` image, a video frame called `portada`, and so on, created by the pipeline under the names your config gives them). Reach them with **`variant()`** using dot notation:

```php
$file->variant('thumbnail')->url();
$file->variant('portada.mini')->url('image');
```

`variant()` never returns `null`. If any link in the chain is missing it falls back to the closest existing ancestor, so `$file->variant('portada.mini')` returns `portada` (or the original) when `mini` was not generated yet. When that silent fallback would be a bug, use **`variantOrFail()`** instead, which throws `RuntimeException` if any segment is missing:

```php
$thumb = $file->variantOrFail('portada.mini'); // throws if not generated
```

**`preview()`** is the variant the type's `preview` names, or `null` when it names none or that variant does not exist yet. Unlike `variant()`, it never falls back to the original, so a video is never handed out as an image:

```php
// 'video' => [..., 'preview' => 'portada.mini']
$video->preview();              // the same as $video->variant('portada.mini')
$video->preview()?->url();
```

To avoid lazy-loading each variant on access, eager load the tree with the `withVariants()` scope on the `File` model:

```php
$files = File::withVariants()->get(); // loads file -> its variants -> theirs
```

### `url()` and forced types

`url()` returns the real URL, or `null` when no valid backend resolves. Pass a type to force a fallback: if the file is not that type, or its URL cannot be built, you get the configured placeholder for that type instead of `null`. A 360° photo or a strip of frames is type `image`, so `url('image')` serves it.

```php
$file->url();          // real URL or null
$file->url('image');   // real URL if it is an image, otherwise the image placeholder
```

### Rendering from the parent model

The trait gives you two helpers that go straight from a model + collection to a renderable result, with placeholder fallback built in. Prefer these when you do not have a `File` instance in hand.

**`fileLink()`** returns a URL string (the file, a variant, or a placeholder), so the `<img>` always has a `src`:

```blade
<img src="{{ $user->fileLink('avatar') }}">
<img src="{{ $user->fileLink('avatar', 'medium') }}">     {{-- a variant --}}
<img src="{{ $user->fileLink('cover', 'portada.mini') }}">
```

```php
public function fileLink(string $collection, ?string $variant = null, ?string $forceType = null): ?string
```

`$forceType` selects which placeholder to fall back to. You only need it for multi-type collections (for example a `gallery` that accepts both images and video). When a collection declares a single type in config, the type is inferred for you.

**`fileRender()`** returns rendered HTML. With no component configured it emits a plain `<img>`; with a component configured for the collection it renders that component instead. Extra attributes are forwarded:

```blade
{{ $user->fileRender('avatar', 'medium', ['class' => 'w-12 h-12 rounded-full']) }}
```

```php
public function fileRender(string $collection, ?string $variant = null, array $attrs = []): HtmlString
```

### Per-collection components

For a consistent look per collection (a round avatar with initials fallback, a card for documents), point the collection at a Blade component via the `component` config key (or the `$fileCollections` override shown above). `fileRender()` then renders:

```blade
<x-{component} :model="$model" :url="$url" ...attrs />
```

The component receives `$model` (the owning model) and `$url` (the resolved URL, which may be `null` when there is no file and no placeholder). A minimal component for the `avatar` example above:

```blade
{{-- resources/views/components/user-avatar.blade.php --}}
@props(['model', 'url'])

@if ($url)
    <img src="{{ $url }}" alt="{{ $model->name }}" {{ $attributes->merge(['class' => 'rounded-full']) }}>
@else
    <span {{ $attributes->merge(['class' => 'rounded-full bg-gray-200 grid place-items-center']) }}>
        {{ Str::of($model->name)->substr(0, 1)->upper() }}
    </span>
@endif
```

With that component registered, `{{ $user->fileRender('avatar') }}` renders the avatar everywhere, and falls back to the initials badge when the user has no photo.

## Upload modes

Laracrate gives you three ways to get a file's bytes into your storage backend. They differ in who carries the bytes (your PHP server or the browser talking straight to S3/R2) and how large the file is. In every mode the end result is the same: a row in `laracrate_files` created through `$model->addFile(...)`, with the binary already at its canonical key.

| Mode | How the bytes travel | Best for | Pros | Cons |
| --- | --- | --- | --- | --- |
| **Server-side** | Browser to your PHP, then PHP to the disk | Small files, simple forms, trusted server flows | One request, no JS, works on any disk | Bytes pass through PHP (memory, request size limits) |
| **Direct presigned PUT** | Browser straight to S3/R2 via a presigned URL | Most uploads up to roughly 100 MB | Offloads bandwidth from PHP, progress events | Needs S3-compatible disk (or the local fallback), two steps |
| **Multipart** | Browser uploads in parts straight to S3/R2 | Large files (video, archives) | Parallel parts, resumable, no PHP transfer | S3/R2 only, more orchestration |

The threshold between presigned and multipart is just a frontend hint, `config('laracrate.multipart.threshold')` (100 MB by default). The server does not enforce it. Your client code decides which path to take based on `file.size`.

### Server-side (addFile with an UploadedFile)

The simplest mode. Hand `addFile()` the `UploadedFile` straight from the request and Laracrate writes it to the collection's disk for you.

```php
public function store(Request $request)
{
    $request->validate(['avatar' => 'required|image|max:5120']);

    $file = $request->user()->addFile($request->file('avatar'), 'avatar');

    return back();
}
```

`addFile()` also accepts a `Binary` value object, a `FileUpload` (see below), or a string key. See the Working with files from your models section for the full signature and the `$data`, `$slots`, `$folder` parameters.

### Direct presigned PUT

Here the browser uploads straight to S3/R2 and your server never touches the bytes. The flow is: ask the server for a presigned URL, `PUT` the file to it, then confirm by sending the resulting key back so `addFile()` can persist the `File` row.

The JS helper ships at `resources/js/laracrate.js` inside the package (it is not published to npm). Copy it into your app's JS sources, or point a bundler alias at `vendor/edulazaro/laracrate/resources/js/laracrate.js`, then import `presignAndUpload`, which does the presign request and the `PUT` (with progress) in one call:

```js
import { presignAndUpload } from './laracrate';

const result = await presignAndUpload(file, {
    disk: 'media',
    maxSizeKb: 10240,
    onProgress: (ratio) => console.log(Math.round(ratio * 100) + '%'),
});

// result = { key, disk, original_name, mime_type, size }
// Send it to your own controller to confirm.
await fetch('/profile/avatar', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify(result),
});
```

`presignAndUpload(file, opts)` options: `disk` (required), `maxSizeKb`, `presignUrl` (override the presign route), and `onProgress` (a `0..1` callback). It returns `{ key, disk, original_name, mime_type, size }`.

On the backend, rebuild a `FileUpload` from that payload and pass it to `addFile()`:

```php
use EduLazaro\Laracrate\Support\FileUpload;

public function confirm(Request $request)
{
    $data = $request->validate([
        'disk'          => 'required|string',
        'key'           => 'required|string',
        'original_name' => 'required|string',
        'mime_type'     => 'required|string',
        'size'          => 'required|integer',
    ]);

    $file = $request->user()->addFile(
        FileUpload::fromArray($data),
        'avatar'
    );

    return response()->json(['slug' => $file->slug]);
}
```

`FileUpload::fromArray()` accepts `disk`, `key`, `original_name` (or `originalName`), `mime_type` (or `mimeType`), and optional `size`, `width`, `height`, `duration`, `digest`.

**The size is measured, not trusted.** `addFile()` asks the bucket how big the object is (one HEAD, no download) and checks the collection's rules with that, whatever `size` says: a presigned PUT does not bind the length, so the size the browser declares means nothing. An object over the maximum is refused (`UploadRejected` with reason `size`) and deleted from `temp/`; one that is not in the bucket is refused with reason `missing`.

**Where the file lands.** The presign endpoint always signs a key under `temp/`; the request cannot name a model path, which used to let anyone write into another model's folder. When you then call `addFile()` with that `temp/` key, Laracrate moves the object to its canonical key (`{fileable_type}/{fileable_id}/{collection}/{ulid}_{name}`). On S3-compatible disks the move is a server-side `copyObject` plus `deleteObject` (`StorageManager::moveServerSide()`): the bytes never come back through PHP.

If the user cancels before confirming, delete the orphaned `temp/` object with `deleteTemp(disk, key)` from the JS helper. It only deletes keys that start with `temp/`.

### Multipart (large files)

For files past the threshold, S3/R2 multipart splits the upload into parts that the browser `PUT`s in parallel, each returning an `ETag`. It works under an [upload grant](#upload-modes-grants-and-the-upload-tray), like `sign` and `confirm`: the grant is the only source of the target, so the request never names a disk, a model or a key. There is no bundled multipart client yet (the upload queue sends every file with a single PUT), so you drive the endpoints yourself based on `file.size`:

1. **Init.** `POST /laracrate/multipart/init` with `grant`, `file_name`, `mime` and `size`, and optionally `part_size`. The file is checked against the collection and slot rules first, as `sign` does. The response gives you `id`, `part_size`, `total_parts`, `expires_at`, and `parts` (an array of `{ part_number, url, method }`).
2. **Upload parts.** `PUT` each part's bytes to its `url`. Capture the `ETag` response header for every part (your bucket's CORS has to expose it).
3. **Reissue (optional).** If a part URL expires before you use it, `POST /laracrate/multipart/{id}/parts` with `grant` and `part_numbers` to get fresh URLs.
4. **Complete.** `POST /laracrate/multipart/{id}/complete` with `grant` and `parts` as `[{ part_number, etag }, ...]`. S3 assembles the object and Laracrate creates the File, answering like `confirm`. The size checked is the one the bucket holds, since no part URL binds a length, and an object the collection refuses is deleted. To cancel instead, `DELETE /laracrate/multipart/{id}` with `grant`.

Reissuing, completing and cancelling only accept the grant that started the session, which only the user it was issued to can send. The object goes straight to its canonical key rather than to `temp/`: moving it afterwards would be a single `CopyObject`, which S3 refuses past 5 GB.

Sessions are tracked in `laracrate_multipart_uploads`, with the File they produced in `file_id`. Schedule `laracrate:abort-stale-multipart` (see the artisan commands section): until a session is aborted, its parts take space in the bucket without showing in any listing.

An application that starts multipart sessions on its own, with `InitiateMultipartUploadAction` and its siblings behind its own routes, keeps doing so: those sessions carry no grant, and these endpoints do not reach them.

Earlier versions took the disk and the key of `init` from the request, and left these routes with no middleware at all when `multipart.middleware` was `null`, the shipped default. If you cannot update yet, set that key explicitly.

Multipart requires an S3-compatible disk. Tuning lives under `config('laracrate.multipart')`: `part_size` (10 MB default, 5 MB minimum), `expire_minutes`, and `url_ttl_minutes`.

### Local driver for development

You usually do not have S3/R2 in local dev. When the upload disk uses Laravel's `local` driver, `StorageManager::presignedUpload()` cannot mint a true presigned URL, so it returns a Laravel signed route to the local upload endpoint instead (`POST` instead of `PUT`). `presignAndUpload` follows whatever URL and method the presign response specifies, so the same client code works against local storage with no changes. The signed-route endpoints serving this are described in the next section.

## HTTP endpoints

Laracrate registers its routes in `routes/web.php`. They cover four concerns: direct uploads (presign and multipart), streaming and downloading protected files, polling processing status, and the local-driver upload/serve fallback. Each group has its own prefix, middleware, and route-name prefix driven by config, so you can move or re-protect them without touching the package.

You rarely call these routes by name from PHP. The JS helper and your upload flow hit them by URL. The full set:

| Method | URI | Route name | Purpose |
| --- | --- | --- | --- |
| POST | `laracrate/uploads/presign` | `laracrate.uploads.presign` | Mint a presigned PUT URL (or local signed-route fallback) for a direct upload |
| DELETE | `laracrate/uploads/{disk}/{encodedKey}` | `laracrate.uploads.cancel` | Delete an abandoned `temp/` object (`encodedKey` is base64 then URL-encoded) |
| POST | `laracrate/uploads/sign` | `laracrate.uploads.sign` | Grant based: check a file against the rules, then a presigned PUT and a receipt, or the address to post it to |
| POST | `laracrate/uploads/confirm` | `laracrate.uploads.confirm` | Grant based: create the File of a direct upload (the receipt proves the key was issued to this grant) |
| POST | `laracrate/uploads/receive` | `laracrate.uploads.receive` | Grant based: take a file through PHP and create the File at once (encrypted collections) |
| POST | `laracrate/uploads/register` | `laracrate.uploads.register` | Presign mode of the components: create the File of an object the component signed, by its key alone |
| POST | `laracrate/uploads/remove` | `laracrate.uploads.remove` | Grant based: remove the current file of a target that replaces it (a replacing grant only) |
| GET | `laracrate/uploads/targets/{target}` | `laracrate.uploads.target` | An upload target: the collection's rules, the current file and the scope, with no grant (`?params[venue]=12`) |
| POST | `laracrate/uploads/targets/{target}` | `laracrate.uploads.target.grant` | An upload target: a grant, asked for when the user picks files |
| POST | `laracrate/multipart/init` | `laracrate.multipart.init` | Grant based: check a file against the rules and start a multipart session, returns the part URLs |
| POST | `laracrate/multipart/{multipart}/parts` | `laracrate.multipart.parts` | Reissue presigned URLs for specific parts (with the session's grant) |
| POST | `laracrate/multipart/{multipart}/complete` | `laracrate.multipart.complete` | Assemble the object from the part ETags and create the File (with the session's grant) |
| DELETE | `laracrate/multipart/{multipart}` | `laracrate.multipart.abort` | Abort and clean up a multipart session (with the session's grant) |
| GET | `laracrate/files/{file:slug}/stream` | `laracrate.files.stream` | The file through PHP, inline (`streamUrl()`). Checks `canView` on every request, honours `Range`, decrypts, logs; not counted as a download |
| GET | `laracrate/files/{file:slug}/download` | `laracrate.files.download` | The file through PHP as an attachment (`downloadUrl()`), counted in `downloads_count` |
| GET | `laracrate/files/{file:slug}/viewer` | `laracrate.files.viewer` | What the viewer shows (JSON): name, type and a link made at request time, after `canView` |
| GET | `laracrate/files/{file:slug}/status` | `laracrate.files.status` | Processing status for one file (JSON) |
| POST | `laracrate/files/status` | `laracrate.files.status.batch` | Processing status for many files in one request |
| POST | `_laracrate/local/upload` | `laracrate.local.upload` | Receive bytes for the local-driver presigned fallback (signed) |
| GET | `_laracrate/local/serve/{file:slug}` | `laracrate.local.serve` | Serve a local-disk file after verifying the URL signature (signed) |

**Prefixes and middleware.** Each group reads its own config, so prefixes and route names shown above are the defaults:

| Group | Prefix config | Middleware config | Name prefix |
| --- | --- | --- | --- |
| Presigned uploads | `laracrate.uploads.route_prefix` (`laracrate/uploads`) | `laracrate.uploads.middleware` (`['web', 'auth']`) | `laracrate.uploads.` |
| Multipart | `laracrate.multipart.route_prefix` (`laracrate/multipart`) | `laracrate.multipart.middleware` (falls back to the uploads middleware when null) | `laracrate.multipart.` |
| Stream / download / viewer | `laracrate.stream.route_prefix` (`laracrate/files`) | `laracrate.stream.middleware` (`['web', 'auth']`) | `laracrate.stream.route_name_prefix` (`laracrate.files`) |
| Status | `laracrate.status.route_prefix` (`laracrate/files`) | `laracrate.status.middleware` (`['web', 'auth']`) | `laracrate.files.` |
| Local driver | fixed `_laracrate/local` | `signed` | `laracrate.local.` |

A few details worth knowing:

- **Authorization is yours.** The upload and multipart groups only apply the configured middleware. Restricting which disks a user may write to is enforced separately by `config('laracrate.uploads.allowed_disks')`, checked in the presign endpoint. The grant based endpoints, multipart included, take the disk from the grant's collection.
- **`cancel` only touches `temp/`, and only what this browser signed.** The DELETE endpoint refuses any key that is not a plain `temp/` name, and any key that was not signed by `presign` in the same browser session (or for the same user when there was no session). The `deleteTemp()` JS helper builds the `{encodedKey}` segment for you.
- **Files through PHP are not signed.** `stream`, `download` and `viewer` sit behind the session and check the file's view policy on every request, so their links never expire. Keep `auth` in `laracrate.stream.middleware`.
- **Multipart sessions answer to their grant.** `parts`, `complete`, and `abort` return 403 for any grant other than the one that started the session, and for sessions started outside these endpoints.
- **Local routes are signed, not authed.** The `_laracrate/local` group is protected by Laravel's `signed` middleware. The URLs are minted by `StorageManager::presignedUpload()` and `GenerateSignedUrlAction`, so they carry their own expiry. They exist only as the dev-time stand-in for real presigned S3/R2 URLs.
- **Status responses.** Both status endpoints check `canView()` per file and return `{ slug, status, ready, url, preview, variants, error }` per file (the batch endpoint keys the map by slug and silently omits files you cannot view). Use the `pollFileStatus` and `pollFilesStatus` JS helpers to consume them; see the displaying files and processing pipeline sections for how `ready` and `variants` are populated.

## Folders

**Folders** give a model a tree of named folders to organize its files. They are purely logical: a folder never changes where the binary lives in your disk (a file's storage key stays the same when you move it between folders). Use folders when you want a drive-like UI on top of a fileable (a user's personal drive, an organization's shared drive, a per-case document tree).

Add the `HasFolders` trait to any model. It pairs with `HasFiles` but is independent: a model can use one, the other, or both.

```php
use EduLazaro\Laracrate\Concerns\HasFiles;
use EduLazaro\Laracrate\Concerns\HasFolders;

class Organization extends Model
{
    use HasFiles;
    use HasFolders;
}
```

### Creating folders

Call `addFolder()` on the model. Pass a parent `Folder` to nest, or leave it null to create a root folder. The folder's polymorphic `folderable_*` morph is set to the owning model automatically.

```php
$contracts = $organization->addFolder('Contracts');
$y2025     = $organization->addFolder('2025', parent: $contracts);

// Optional audit and metadata:
$folder = $organization->addFolder(
    name: 'Invoices',
    parent: null,
    creator: auth()->user(),   // defaults to auth()->user() when null
    metadata: ['color' => '#a855f7'],
);
```

`addFolder()` throws `InvalidArgumentException` if the `$parent` you pass belongs to a different folderable. The full signature is:

```php
public function addFolder(
    string $name,
    ?Folder $parent = null,
    ?Model $creator = null,
    array $metadata = []
): Folder
```

### Listing the tree

| Call | Returns |
| --- | --- |
| `$organization->folders()` | MorphMany of every folder at any depth |
| `$organization->rootFolders()` | Only top-level folders (`parent_id` null), ordered by name |
| `$folder->children()` | Direct child folders (one level), ordered by name |
| `$folder->descendants()` | Query of all nested folders below, via the denormalized `path` (one indexed query, no SQL recursion) |
| `$folder->files()` | Files directly in this folder (not recursive) |
| `$folder->allFiles()` | Top-level files in this folder and all its descendants |
| `$folder->breadcrumb()` | Array of ancestor folders from root to this one, for breadcrumbs |
| `$folder->sizeBytes()` | Sum of `size` for every file in the subtree |

```php
foreach ($organization->rootFolders as $folder) {
    echo $folder->name . ' (' . $folder->sizeBytes() . ' bytes)';
}
```

### Putting files into folders

When you create a file, pass the target folder to `addFile()` (see the Working with files from your models section):

```php
$organization->addFile($upload, 'drive', folder: $contracts);
```

To move an existing file, call `moveToFolder()` on the `File`. Pass null to move it back to the fileable root.

```php
$file->moveToFolder($y2025);   // into a folder
$file->moveToFolder(null);     // back to the root
```

Both `addFile()` and `moveToFolder()` throw `InvalidArgumentException` if the folder belongs to a different fileable, so a file can never be attached to another owner's folder.

### Moving and renaming folders

Move a folder under a new parent with `moveTo()` (null moves it to root):

```php
$y2025->moveTo($archive);
```

`moveTo()` refuses two things: moving a folder between different folderables, and any move that would create a cycle (making a folder a descendant of itself). Both raise `InvalidArgumentException`.

The denormalized `path` column (for example `Contracts/2025`) is the source of truth for fast listings, and `parent_id` is the source of truth for structure. The `FolderObserver` keeps them in sync: on every save it recomputes `path` from `parent->path + name`, and after an update it cascades the new path to all descendants. Renaming `Contracts` to `Agreements` rewrites `Contracts/2025` to `Agreements/2025` automatically. Setting `path` by hand is pointless because the observer overwrites it.

### Deleting folders

`Folder` uses soft deletes. To remove a whole subtree permanently, call `forceDeleteRecursive()`. It force-deletes every file in the subtree first (which fires the `FileObserver` and purges the binaries plus chunks), then the descendant folders deepest-first, then the folder itself.

```php
$contracts->forceDeleteRecursive();
```

> The `folderable` morph backs two unrelated features. The `Folder` model organizes files in a tree, while the separate `Folderable` model is a per-collection usage counter. They share the morph name but nothing else. Usage tracking is covered in the Multi-tenancy, buckets and usage section.

## File slots

A **file slot** is a structured "you must upload X" requirement: a named target with rules about what can land in it and how many files it accepts. Use slots for things like an admission checklist ("Upload your ID", "Upload proof of address") or a quota ("Upload up to 3 invoices for June"). A slot does not classify or categorize your files, it only defines where files fit and under what rules. Categories, tags, and hierarchies stay in your app.

Slots live in `laracrate_file_slots` and link to files through the `laracrate_file_slot_pivot` table (many-to-many, so one file can satisfy several slots).

### Defining a slot

Create a `FileSlot` directly. Every rule is optional; an empty rule means "no restriction".

```php
use EduLazaro\Laracrate\Models\FileSlot;

$slot = FileSlot::create([
    'name'                  => 'National ID',
    'description'           => 'Upload your ID document',
    'allowed_extensions'    => ['pdf', 'jpg', 'png'],
    'allowed_types'         => ['document', 'image'],
    'max_files_per_creator' => 1,
    'max_files_total'       => null,
    'tenant_type'           => $org->getMorphClass(),
    'tenant_id'             => $org->getKey(),
    'context_type'          => $case->getMorphClass(),
    'context_id'            => $case->getKey(),
]);
```

| Column | Type | Purpose |
| --- | --- | --- |
| `name` | string | Slot label shown to the uploader |
| `description` | string, nullable | Optional helper text |
| `color` | string, nullable | Optional UI color |
| `allowed_extensions` | array, nullable | Allowed file extensions (empty = any) |
| `allowed_types` | array, nullable | Allowed `FileType` values: `document`, `image`, `video`, `audio` (empty = any) |
| `max_files_per_creator` | int, nullable | Per-creator limit (null = unlimited) |
| `max_files_total` | int, nullable | Global limit across all creators (null = unlimited) |
| `position` | int | Display order, defaults to 0 |
| `tenant_type` / `tenant_id` | morph, nullable | Multi-tenant scope, same convention as `File` |
| `context_type` / `context_id` | morph, nullable | Optional finer scope inside the tenant (for example one case) |

### Attaching files to slots

Pass the slots when you create the file. The `$slots` argument of `addFile()` accepts `FileSlot` models or their IDs, and they are validated before the file is written:

```php
$organization->addFile($upload, 'documents', slots: [$slot]);
```

During creation Laracrate checks each slot's extension rule and quota. If the slot does not accept the file's extension, or the per-creator or global limit is already reached, `addFile()` throws `InvalidArgumentException` and no file is created. On success the file is attached with `syncWithoutDetaching`, so re-attaching is idempotent.

You can also manage the relation directly from either side:

```php
$slot->files;    // BelongsToMany of files in this slot
$file->slots;    // BelongsToMany of slots this file satisfies

$file->slots()->syncWithoutDetaching([$slot->id]);
```

### Checking rules and completion

`FileSlot` exposes the predicates used during upload, so you can drive UI and your own validation with the same logic.

| Method | Returns |
| --- | --- |
| `uploadedCount(?string $creatorType = null, ?int $creatorId = null)` | Number of files in the slot, optionally filtered by creator morph |
| `canAcceptMore(?string $creatorType = null, ?int $creatorId = null)` | Array `['can' => bool, 'reason' => 'global'\|'per_creator'\|null, 'limit' => int\|null]` |
| `acceptsExtension(string $extension)` | Whether the extension is allowed (empty list = any) |
| `acceptsType(string $type)` | Whether the `FileType` value is allowed (empty list = any) |
| `accepts(File $file)` | Full check on a file: extension AND type rules must both pass when both are declared |

```php
// Is this required slot satisfied for the current user?
$done = $slot->uploadedCount($user->getMorphClass(), $user->getKey()) >= 1;

// Can the user add another file?
$check = $slot->canAcceptMore($user->getMorphClass(), $user->getKey());
if (! $check['can']) {
    // $check['reason'] is 'global' or 'per_creator', $check['limit'] is the cap
}

// Pre-flight a file before showing an upload button:
if ($slot->accepts($file)) {
    // extension and type both allowed
}
```

`uploadedCount()` and `canAcceptMore()` count across all creators when you omit the creator arguments, so passing no arguments gives you the global totals while passing the creator morph scopes to one uploader.

## Processing pipeline

Every top-level file goes through an asynchronous **processing pipeline** that runs in the queue, so the user's upload returns instantly while heavier work (image variants, video transcoding, PDF previews, text extraction, embeddings) happens in the background.

The flow is always the same:

1. A top-level `File` is created. `FileObserver::created` sets `processing_status` to `pending` and dispatches `ProcessFileJob`.
2. `ProcessFileJob` runs on the queue and calls `ProcessFileAction`.
3. `ProcessFileAction` marks the file `processing` (firing `FileProcessingStarted`), resolves the applicable steps, runs them in ascending `priority()` order, then marks the file `completed` and fires `FileProcessed`.

The observer only enqueues a job for files whose `type` is `image`, `video`, `document`, or `audio`. Variants (files with a `parent_id`) never enter the pipeline: their generating action marks them `completed` and the observer just fires `VariantGenerated`. See the Data model section for the `processing_status` column and the Events section for the dispatched events.

```php
// You do not call this yourself. Creating a file triggers it.
$file = $user->addFile($uploaded, 'documents');
// $file->processing_status is now ProcessingStatus::PENDING
// A ProcessFileJob is queued. Once the worker runs it,
// processing_status moves to PROCESSING then COMPLETED (or FAILED).
```

The `ProcessingStatus` enum (`EduLazaro\Laracrate\Enums\ProcessingStatus`) has four cases: `PENDING`, `PROCESSING`, `COMPLETED`, `FAILED`, plus helpers `isTerminal()` and `isInProgress()`. If the queue is not running in development, the file stays `pending` until a worker picks it up. That is expected behavior, not a bug.

### Default steps

Each step is a small class implementing `EduLazaro\Laracrate\Contracts\FileActionInterface`. The package registers these globally in `LaracrateServiceProvider`, ordered here by `priority()`:

| Priority | Step class (namespace `EduLazaro\Laracrate\Pipeline\Steps\...`) | Runs when |
|----------|-----------------------------------------------------------------|-----------|
| 5 | `Image\DetectSphereStep` | `type` is `image` and the collection's image type takes the `sphere` mode: gives mode `sphere` to a photo whose XMP declares an equirectangular projection; otherwise it stays `default` |
| 10 | `Image\ExtractImageDimensionsStep` | the base type is `image` |
| 10 | `Video\ExtractVideoDimensionsStep` | `type` is `video` |
| 20 | `Image\OptimizeImageStep` | the base type is `image` and optimization is enabled (collection `optimize`, type `optimize`, or `laracrate.image.optimize_originals`) |
| 25 | `Video\TranscodeVideoStep` | `type` is `video` and the video type config sets `'transcode' => true` |
| 30 | `Video\GenerateVideoVariantsStep` | `type` is `video` and the video type config declares `variants` (copies, teasers, frames, strips of frames) |
| 40 | `Image\GenerateImageVariantsStep` | the base type is `image` and the image type config declares `variants` |
| 45 | `Document\GeneratePdfVariantsStep` | `type` is `document`, `mime_type` is `application/pdf`, and the document type config declares variants with `'type' => 'image'` (rendered pages) |
| 60 | `Text\ExtractTextStep` | embeddings enabled, the collection should extract or embed, and a text extractor exists for the file |
| 70 | `Text\ChunkTextStep` | embeddings enabled, the collection should embed, and the `{key}.json` sidecar exists |
| 80 | `Text\GenerateEmbeddingStep` | embeddings enabled, the collection should embed, and the `{key}.chunks.jsonl` sidecar exists |
| 90 | `Text\PersistChunksStep` | the `{key}.chunks.jsonl` sidecar exists |

The text steps write two **sidecar artifacts** next to the binary on the same disk: `{key}.json` (extracted full text plus per-page content) and `{key}.chunks.jsonl` (chunks and embeddings). Each later step gates on the artifact the previous one produced, so the chain stops cleanly if extraction yields nothing. These sidecars are purged when the file is force deleted. See the Images, variants and watermarks, Video and PDF variants, and Text extraction, embeddings and search (RAG) sections for what each step actually does and the config keys it reads.

### Priority bands

`priority()` returns an ascending integer. Lower numbers run first. Follow this convention so your steps slot in at the right point:

| Band | Purpose |
|------|---------|
| 0-19 | Metadata (dimensions, duration) |
| 20-39 | Transforming the original (optimize, transcode, encrypt) |
| 40-59 | Derivatives (variants, pages, thumbnails) |
| 60-79 | Semantic extraction (text, OCR, transcription) |
| 80-99 | AI (chunking, embeddings, classification) |
| 100+ | App-specific post-processing |

### Failure and retry behavior

The pipeline is **fail-fast**. If any step throws, `ProcessFileAction` marks the file `failed`, stores the exception message in `processing_error`, fires `FileProcessingFailed`, and rethrows. Later steps do not run.

A file deleted for good while its pipeline runs (someone removes an attachment that is still being processed) is not a failure. Its delete purged what existed then; the pipeline notices before the next step, stops, and clears what the steps wrote afterwards (variants, the extraction sidecars, chunks) through `FileObserver::purgeLeftovers()`. A soft deleted file keeps its row and is processed as usual.

The rethrow lets the queue retry. `ProcessFileJob` declares:

- `public int $tries = 3;`
- `public array $backoff = [10, 30, 60];` (seconds between attempts)
- `public int $timeout = 600;`
- `public bool $deleteWhenMissingModels = true;`

`$deleteWhenMissingModels` matters when a file is replaced before the worker reaches its job (for example `setFile()` swapping an avatar): Laravel silently discards the orphaned job instead of failing three times with `ModelNotFoundException`. Any job you add that receives a model should set it too. The job's queue name and connection come from `laracrate.queue.name` and `laracrate.queue.connection` (see the Configuration section).

### Writing a step

A step decides whether it applies (`supports()`, optional) and what to do (`handle()`), and declares its order (`priority()`). The `supports(File $file): bool` method is optional: if you omit it, `handle()` always runs. Scope by file type, collection, or model inside `supports()`; throw from `handle()` to fail the pipeline.

```php
<?php

namespace App\Pipeline\Steps;

use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;

class ScanForVirusesStep implements FileActionInterface
{
    public function supports(File $file): bool
    {
        return $file->type === FileType::DOCUMENT;
    }

    public function priority(): int
    {
        return 5; // run before everything else
    }

    public function handle(File $file): void
    {
        // Inspect $file->key on $file->disk. Throw to fail the pipeline.
    }
}
```

Steps run on the queue worker, so this is also the only place you should shell out to external binaries (ffmpeg, imagick, pdftoppm). Keep that work out of the observer and out of `CreateFileAction`.

There are two extension points.

#### (a) Register a step globally

Add it to the `FileActionRegistry` from your own service provider's `boot()`. It then applies to every file (subject to its own `supports()`), and `remove()` lets you drop a packaged default by class name.

```php
use App\Pipeline\Steps\ScanForVirusesStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\OptimizeImageStep;
use EduLazaro\Laracrate\Support\FileActionRegistry;

public function boot(): void
{
    app(FileActionRegistry::class)
        ->add(new ScanForVirusesStep())
        ->remove(OptimizeImageStep::class);
}
```

#### (b) Declare a step per collection or per model

Declare the step class under a collection's `actions` key in `config/laracrate.php`. `ProcessFileAction` resolves it from the container and merges it with the global steps before sorting by priority. Steps under `models.{fileable_type}.actions` are added (not substituted) for files whose `fileable_type` matches, so you can layer model-specific steps on top of collection-wide ones.

```php
'collections' => [
    'documents' => [
        // ... disk, access, etc.
        'actions' => [
            \App\Pipeline\Steps\ClassifyDocumentStep::class, // all documents
        ],
        'models' => [
            \App\Models\Lawsuit::class => [
                'actions' => [
                    \App\Pipeline\Steps\DetectDeadlinesStep::class, // lawsuits only
                ],
            ],
        ],
    ],
],
```

Per-collection action classes must implement `FileActionInterface`. If a configured class does not, `ProcessFileAction` logs a warning and skips it. For introspection you can call `app(FileActionRegistry::class)->applicableFor($file)` to see which global steps would run for a given file.

## Images, variants and watermarks

Laracrate processes every image you upload into an optimized original plus any number of resized **variants** (thumbnails, medium, large, and so on). Variants are generated asynchronously in the pipeline, so the user's upload stays instant. Each variant is a real `File` row of its own with `parent_id` pointing at the original, `type = image`, and a `variant` name, so you can query and serve it like any other file. The original is never destroyed by variant generation, and the original is never watermarked.

You declare variants per file type inside a collection's config. The orchestrator (`GenerateImageVariantsAction`) iterates `types.image.variants` for the collection and dispatches `GenerateImageVariantAction` once per definition. A failure in one variant is logged and skipped, the rest still generate. A 360° photo (mode `sphere`) and a strip of frames (mode `frames`) are type `image`: they go through the same steps and read the same `image` block. The type's `'preview' => 'thumbnail'` names the variant `$file->preview()` and `preview_link` return (see the Video and PDF variants section).

```php
// config/laracrate.php
'collections' => [
    'gallery' => [
        'disk'   => 'media',
        'access' => 'public',
        'types'  => [
            'image' => [
                'variants' => [
                    'thumbnail' => ['width' => 300,  'height' => 300, 'fit' => true],
                    'medium'    => ['width' => 800,  'height' => 800],
                    'large'     => ['width' => 1600, 'height' => 1600],
                ],
            ],
        ],
    ],
],
```

### Where a variant lives

The variant binary is written under a `variants/` subdirectory next to the original, using `$file->variantKey($newName)`. The variant filename is the original base name plus the variant name, for example `photo_thumbnail.webp`. The variant row is created with `$file->createVariant($name, $overrides)`, which inherits the parent's scope. Regeneration is idempotent: if a variant with the same name already exists it is force-deleted and rebuilt.

To read variant keys back, see the `key`, `variantKey`, and `createVariant` helpers covered in the Data model section, and use the model's variant accessors covered in the Working with files from your models section.

### Variant options

Each variant definition accepts these keys. Per-variant values win, then the collection's `types.image` level, then the global `defaults.image` level, then a hardcoded fallback.

| Option | Type | Default | Meaning |
|--------|------|---------|---------|
| `width` | int or null | `null` | Target width. |
| `height` | int or null | `null` | Target height. |
| `fit` | bool | `false` | `true` crops to fill the box (`cover`). `false` scales down preserving aspect ratio without enlarging (`scaleDown`). |
| `quality` | int | `80` | Encoder quality (0-100). |
| `format` | `webp` or `jpg` | `webp` | Output format. `webp` writes `image/webp`, `jpg` writes `image/jpeg`. |
| `watermark` | bool | `false` | Bake the watermark into this variant. See below. |

Quality resolution is slightly special: a variant's own `quality` wins, otherwise `types.image.variant_quality`, then `defaults.image.variant_quality` (default `85`), then the normal `quality` cascade.

### Optimizing the original

The original is left untouched unless you opt in. `OptimizeImageAction` re-encodes a top-level image to WebP and downscales it to a maximum bounding box. It is a no-op for variants (variants are already generated optimized) and only runs when `image.optimize_originals` is `true` or the collection requests it.

```php
// config/laracrate.php
'image' => [
    'driver'             => 'imagick', // 'imagick' (recommended) or 'gd'
    'optimize_originals' => false,
    'max_width'          => 1920,
    'max_height'         => 1920,
    'quality'            => 85,
],
```

The `image.driver` value selects the Intervention Image backend (`imagick` or `gd`) for all image work: optimization, variant generation, and watermarking. Image dimensions are captured separately by `ExtractImageDimensionsAction` and stored on `width` and `height` (it reads the binary with `getimagesizefromstring` and is a no-op once both are set).

### Watermarks

A watermark is baked into the binary of specific variants at generation time. You opt in per variant with `'watermark' => true` in that variant's definition. The original master file never carries a watermark, only the variants that explicitly ask for one.

```php
// config/laracrate.php
'collections' => [
    'identity' => [
        'disk'      => 'documents',
        'access'    => 'private',
        'sensitive' => true,
        'types'     => [
            'image' => [
                'variants' => [
                    'thumbnail' => ['width' => 300],                       // no watermark
                    'display'   => ['width' => 1200, 'watermark' => true], // watermarked
                ],
            ],
        ],
    ],
],
```

The watermark itself is configured once in the top-level `watermark` block. It can overlay a PNG, a text string, or both. If neither a PNG nor text is configured, applying the watermark is a no-op.

```php
// config/laracrate.php
'watermark' => [
    // PNG to overlay. Absolute path, or relative to public_path(). null skips the image.
    'image_path' => env('LARACRATE_WATERMARK_IMAGE', null),

    // Width as a fraction of the variant width (0.0 - 1.0). 0.40 = 40% of the width.
    'size'     => 0.40,
    // PNG opacity (0-100).
    'opacity'  => 30,
    // 'center' | 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right'.
    'position' => 'center',

    'text' => [
        // null = no text, a string = fixed text, or a closure(File): ?string for dynamic text.
        'content'         => null,
        // Font size as a fraction of the image width.
        'font_size_ratio' => 0.0195,
        // CSS rgba color.
        'color'           => 'rgba(255, 255, 255, 0.60)',
        // 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'.
        'position'        => 'bottom-left',
        // Padding from the edge, in pixels.
        'padding'         => 20,
        // Path to a .ttf font. null uses the system font.
        'font_path'       => null,
    ],
],
```

The text `content` closure receives the `File` and may return `null` to skip the text, which is useful for stamping per-file data (an owner id, an order number) onto the watermark. Because a closure cannot live in `env`, set it by publishing the config or in a service provider.

## Video and PDF variants

Videos and PDFs get variants too, declared in the type's `variants` like the image ones and named by your app. Each one says what it is with `type`:

| `type` | Of | What it makes |
| --- | --- | --- |
| `video` (the default for a video) | a video | An MP4 copy, or a teaser when it has `clips` or a `generator` |
| `image` | a video | One frame, as a JPEG |
| `frames` | a video | A strip of frames in one JPEG, stored as type `image` with mode `frames` |
| `image` | a PDF | One page, as a PNG |

They run in the pipeline (`GenerateVideoVariantsStep`, `GeneratePdfVariantsStep`) and need external binaries (`ffmpeg` and `ffprobe` for video, an engine for PDF), so they are opt-in per collection. Every variant is a child `File` under its name; a variant's own `variants` are made from it, so `teaser.mini` is a copy of the teaser and `portada.mini` a resize of the frame. A bare name (`'web'`) is a variant with no options. Regenerating replaces each variant.

### Video

Dimensions and duration are read first with `ffprobe` (`ExtractVideoDimensionsAction`, stored on `width`, `height`, `duration`). Then the variants:

```php
// config/laracrate.php
'collections' => [
    'deportes' => [
        'disk'   => 'media',
        'access' => 'private',
        'types'  => [
            'video' => [
                'accepted_extensions' => ['mp4', 'mov'],
                'max_file_size'       => 512000,

                'variants' => [
                    // MP4 copies
                    'hd' => ['max_height' => 1080],
                    'sd' => ['max_height' => 480, 'bitrate_kbps' => 800],

                    // a frame, and a small copy of it
                    'portada' => [
                        'type'     => 'image',
                        'frame_at' => '00:00:03',
                        'width'    => 1280,
                        'variants' => ['mini' => ['width' => 300]],
                    ],

                    // a strip of 6 frames spread across the video
                    'escenas' => ['type' => 'frames', 'frames' => 6, 'width' => 320],

                    // 5 one-second fragments joined, no audio, and a smaller copy of it
                    'teaser' => [
                        'type'     => 'video',
                        'clips'    => 5,
                        'seconds'  => 1,
                        'audio'    => false,
                        'variants' => ['mini' => ['max_height' => 360]],
                    ],
                ],

                'preview' => 'portada.mini',
            ],
        ],
    ],
],
```

Uploading `gol.mov` leaves:

```
#50  gol.mov                (original, untouched)
 ├─ #51  hd        mp4
 ├─ #52  sd        mp4
 ├─ #53  portada   jpg      (frame at 0:03)
 │    └─ #54  mini   jpg    (300 px)
 ├─ #55  escenas   jpg      (type frames)
 └─ #56  teaser    mp4
      └─ #57  mini   mp4    (the teaser at 360)
```

```blade
<video controls src="{{ $video->variant('hd')->url() }}" poster="{{ $video->preview_link }}"></video>
<video muted loop playsinline src="{{ $video->variant('teaser.mini')->url() }}"></video>
```

What each kind takes:

- **Copy** (`video`, no `clips` or `generator`): `max_width`, `max_height`, `bitrate_kbps`, defaulting to `laracrate.video`. The copy is H.264 / AAC MP4, 8-bit 4:2:0, HDR tone-mapped to SDR and with its index at the start, so it plays in every browser. It is skipped when the original already plays in every browser and fits its size: `variant()` then falls back to the original.
- **Teaser** (`video` with `clips` or `generator`): `clips` fragments (5) of `seconds` each (1), spread evenly over the video, joined into one MP4 within the same `max_*` and `bitrate_kbps`. `audio` (false) keeps the sound when the video has any. Its metadata lists the fragments: `{clips: [[start, length], ...]}`.
- **Frame** (`image`): `frame_at` as a time (`'00:01:20'`), seconds (`3.5`) or a share of the length (`'20%'`), `00:00:01` by default; a moment past the end takes the last frame. `width` and `height` scale it. Its metadata keeps `at`.
- **Strip of frames** (`frames`): `frames` (6) spread evenly, each scaled to `width` (320) or `height`, side by side or in rows of `columns`. Its metadata says how to cut it: `{frames, columns, rows, width, height, at: [...]}`, with the width and height of one frame. A strip is one image, so a hover preview moves the background through it with a single download.

**Who picks the moments.** A teaser or a strip of frames can take its moments from a class of yours, an AI looking for the action or the scenes an editor marked, with `generator`. Laracrate only cuts and joins:

```php
'teaser' => ['type' => 'video', 'audio' => false, 'generator' => App\Media\AiTeaser::class],
```

```php
use EduLazaro\Laracrate\Contracts\MomentsGenerator;

class AiTeaser implements MomentsGenerator
{
    /** @return list<array{at: float, seconds?: float}> start and length, in seconds */
    public function generate(File $video, array $spec): array
    {
        return [['at' => 12.0, 'seconds' => 2], ['at' => 47.5, 'seconds' => 1.5]];
    }
}
```

A moment outside the video is dropped, a missing `seconds` takes the variant's `seconds`, and a strip of frames reads only `at`. When the generator throws or returns nothing usable, the moments are spread evenly and the failure is logged.

**Replacing the original.** `'transcode' => true` on the video type still re-encodes the original in place to the same browser-safe MP4 (`TranscodeVideoStep`, before the variants), and its dimensions are re-read. Use it when nobody needs the upload as it was; declare a copy in `variants` when the original must survive (evidence, quality, metadata).

### PDF

A document variant with `'type' => 'image'` renders one page of a PDF to a PNG (`RenderPdfPageAction`):

```php
'documents' => [
    'disk'   => 'documents',
    'access' => 'private',
    'types'  => [
        'document' => [
            'variants' => [
                'portada' => [
                    'type'     => 'image',
                    'page'     => 1,          // 1-indexed, 1 by default
                    'width'    => 2000,       // downscale only, 2000 by default
                    'variants' => ['thumbnail' => ['width' => 300], 'medium' => ['width' => 800]],
                ],
            ],
            'preview' => 'portada.thumbnail',
        ],
    ],
],
```

The rasterization engine is selectable with `pdf_preview_engine`, or per variant with `engine` (and `resolution`, 150 dpi by default):

| Engine | Requires | Notes |
|--------|----------|-------|
| `pdftoppm` | poppler-utils (`pdftoppm` binary) | No Ghostscript and no ImageMagick `policy.xml` changes. Downscaling falls back to GD. |
| `imagick` | PHP `imagick` extension, Ghostscript (`gs`), and PDF enabled in ImageMagick's `policy.xml` | Reads the PDF through Ghostscript. |
| `auto` | either of the above | Tries `pdftoppm` first, falls back to `imagick` if it is not available. |

When the engine is forced to `pdftoppm` and the binary is missing or fails, the page is logged and skipped rather than falling back.

### `preview`: the variant that stands for a file

A type's `preview` names one of its variants, with dot notation for a nested one. It is a pointer, not a variant of its own:

```php
'video' => ['variants' => [...], 'preview' => 'portada.mini'],
'image' => ['variants' => ['thumbnail' => ['width' => 300]], 'preview' => 'thumbnail'],
```

- `$file->preview()` returns that variant, or `null` when the type names none or it does not exist yet (the queue has not made it, or it failed). It never falls back to the original.
- `$file->preview_link` is `preview()`'s `url('image')`; for an image without a preview, the image itself; for anything else, the image placeholder. The status endpoints answer it as `preview`.

### Upgrading a 1.x config

The 1.x settings fail with a message that says what to write instead (`InvalidArgumentException` when the variants or the preview are read, so the file's processing fails with it):

| 1.x | 2.0 |
| --- | --- |
| `'preview' => ['frame_at' => '00:00:01', 'variants' => [...]]` | `'variants' => ['preview' => ['type' => 'image', 'frame_at' => '00:00:01', 'variants' => [...]]], 'preview' => 'preview.thumbnail'` |
| `'preview' => ['page' => 1, ...]` on a document | the same, with `'type' => 'image', 'page' => 1` |
| `'transcode' => 'variant'` and `$file->playback()` | `'variants' => ['playback']` and `$file->variant('playback')` |

Keeping the 1.x names (`preview`, `playback`) in the new config keeps the rows already generated under them.

## Text extraction, embeddings and search (RAG)

Laracrate can turn your uploaded files into searchable text and vector embeddings, so you can run keyword, semantic, or hybrid search across everything a model owns. This is the foundation for retrieval-augmented generation (RAG): extract text from a PDF, contract, audio recording or scanned image, chunk it, embed it, store it, then query it. Everything runs in the processing pipeline (see the Processing pipeline section), so the user's upload stays instant.

The flow has four stages, each a separate action that hands off through portable sidecar files in storage:

```text
ExtractText  ->  ChunkText  ->  GenerateEmbedding  ->  PersistChunks
   |               |                |                     |
{key}.json   {key}.chunks.jsonl   (+embedding)        ChunkStore
```

`ExtractTextAction` writes a `{key}.json` sidecar with the full text plus per-page segments. `ChunkTextAction` splits it into `{key}.chunks.jsonl` (one JSON object per line). `GenerateEmbeddingAction` rewrites that JSONL with an `embedding` field on each chunk. `PersistChunksAction` reads the final JSONL and stores it through the active **ChunkStore** driver. The JSONL is the canonical, portable artifact: if you switch backends later, you can rebuild every store by re-running `PersistChunksAction` over your files without re-extracting or re-embedding.

### Enabling extraction and embeddings

There is a master switch and a per-collection opt-in. Both must be on for a file to be embedded.

First, flip the master switch in `config/laracrate.php` (it defaults to off, because most uploads do not need this):

```php
'embeddings' => [
    'enabled' => true,
    // ...
],
```

Then opt in per collection with `extract` and `embed`:

```php
'collections' => [
    'documents' => [
        'disk'         => 'documents',
        'access'       => 'private',
        'extract' => true, // run the extractor chain, write {key}.json
        'embed'   => true, // chunk + embed + persist to the ChunkStore
    ],
],
```

`extract` alone gives you searchable plain text and the `{key}.json` sidecar (readable via `$file->extractedText()`, see the end of this section) without any embedding API cost. Add `embed` to generate vectors and enable semantic search.

### The extractor chain and fallback

Extraction runs through a **TextExtractorRegistry**: an ordered list of extractors. For a given file, the registry collects every extractor whose `supports($file)` returns true (`chainFor($file)`), then `ExtractTextAction` tries them in order. If an extractor returns fewer characters than `embeddings.min_text_per_file` (default 100), the action keeps the best result so far and tries the next one. The first extractor to clear the threshold wins. This is how a native PDF reader can fall back to OCR for a scanned PDF.

The bundled extractors:

| Extractor | Handles | Engine | Paid API |
| --- | --- | --- | --- |
| `PdfTextExtractor` | `application/pdf` (native, text-based) | `smalot/pdfparser`, per page | No |
| `OcrPdfTextExtractor` | PDF (scanned, no extractable text) | Anthropic or OpenAI, PDF sent as base64 | Yes |
| `PlainTextExtractor` | `text/*`, csv, json, xml, html, markdown | reads bytes directly | No |
| `OcrImageTextExtractor` | jpeg, png, webp, gif, heic, heif | Vision (Anthropic or OpenAI) | Yes |
| `AudioTranscribeExtractor` | `audio/*` | OpenAI Whisper | Yes |
| `VideoTranscribeExtractor` | `video/*` | ffmpeg, then Whisper (visual frames optional) | Yes |

All class names live under `EduLazaro\Laracrate\Extractors\`. The OCR, audio and video extractors call paid third-party APIs and incur per-file cost (the source documents rough estimates, for example a 10-page PDF OCR is around 0.004 USD on Claude Haiku, audio transcription is around 0.006 USD per minute). They also fail safe: if no API key is configured for the selected provider, `supports()` returns false and the chain moves to the next extractor instead of erroring out.

`OcrImageTextExtractor` and `VideoTranscribeExtractor` emit two segments with distinct `context` values, `text` (verbatim OCR or transcript) and `description` (a visual summary), so the chunker produces a separate embedding for each. Video visual frame description is opt-in: set `visual` on the video type (`'always'` or `'on_demand'`), or add the legacy `'video.visual'` extra to the collection's `extract` list (`video.frame_interval` in the config, `LARACRATE_VIDEO_FRAME_INTERVAL`, controls the seconds between frames, default 30).

#### Registering extractors

By default (when `embeddings.extractors` is empty) the registry loads only `PdfTextExtractor` then `PlainTextExtractor`. To control the order and which extractors run, set `embeddings.extractors` to a list of fully qualified class names. A recommended chain for legal or scanned documents:

```php
'embeddings' => [
    'enabled'    => true,
    'extractors' => [
        \EduLazaro\Laracrate\Extractors\PdfTextExtractor::class,    // native PDFs, free
        \EduLazaro\Laracrate\Extractors\OcrPdfTextExtractor::class, // scanned PDFs, paid OCR
        \EduLazaro\Laracrate\Extractors\OcrImageTextExtractor::class,
        \EduLazaro\Laracrate\Extractors\PlainTextExtractor::class,
    ],
],
```

Order matters: put the fast, free extractor first and the paid OCR fallback second, so OCR only runs when the cheap path returns too little text.

To register a custom extractor at runtime instead, resolve the registry and add an instance (for example in a service provider's `boot()`):

```php
use EduLazaro\Laracrate\Support\TextExtractorRegistry;

app(TextExtractorRegistry::class)
    ->add(new \EduLazaro\Laracrate\Extractors\PdfTextExtractor())
    ->add(new \App\Extractors\MyOcrExtractor());
```

A custom extractor implements `EduLazaro\Laracrate\Contracts\TextExtractor` with two methods: `supports(File $file): bool` and `extract(File $file): ExtractedContent`. Build the return value with `ExtractedContent::singlePage($text, $metadata)` or `ExtractedContent::fromPages($pages, $metadata)`, where each page is `['page_number' => int, 'text' => string]` (and optionally `'context' => string`).

### OCR configuration

The OCR extractors pick their provider from `ocr.provider` (`anthropic` by default, or `openai`):

```env
LARACRATE_OCR_PROVIDER=anthropic
LARACRATE_OCR_MODEL=claude-haiku-4-5
LARACRATE_ANTHROPIC_API_KEY=sk-ant-...
# or for OpenAI:
# LARACRATE_OCR_PROVIDER=openai
# LARACRATE_OPENAI_API_KEY=sk-...
```

API key resolution order, per provider, is: the value passed to the extractor constructor, then `config('laracrate.ocr.{provider}.api_key')`, then `LARACRATE_{PROVIDER}_API_KEY`, then the generic `ANTHROPIC_API_KEY` / `OPENAI_API_KEY`. Model resolution is: constructor argument, then `config('laracrate.ocr.{provider}.model')`, then `LARACRATE_OCR_MODEL`, then the provider default (`claude-haiku-4-5` for Anthropic, `gpt-4o-mini` for OpenAI). Audio transcription is OpenAI only (Whisper) and reads `LARACRATE_OPENAI_API_KEY` with model `LARACRATE_AUDIO_MODEL` (default `whisper-1`).

### Chunking knobs

`ChunkTextAction` splits the extracted text into overlapping chunks. The token figures are approximate (the chunker counts roughly four characters per token):

| Config key | Default | Meaning |
| --- | --- | --- |
| `embeddings.chunk_size` | `1000` | Approx tokens per chunk. `0` means one chunk per file (no splitting). |
| `embeddings.chunk_overlap` | `100` | Approx tokens shared between consecutive chunks. |
| `embeddings.batch_size` | `16` | Chunks per request when calling the embedding provider. |

If any extracted page carries a `context`, the chunker splits per page and propagates the context to each chunk; otherwise it concatenates all pages and tracks which source page each chunk spans.

### Embedding providers

The embedding provider is resolved from the `EduLazaro\Laracrate\Contracts\EmbeddingProvider` binding. The contract is `embed(array $texts): array` (a vector per input string, same order), plus `dimensions()`, `model()` and `name()`.

| Provider | When | Notes |
| --- | --- | --- |
| `OpenAiEmbeddingProvider` | default (`embeddings.provider = 'openai'`) | model `text-embedding-3-small`, 1536 dimensions. Reads `laracrate.embeddings.api_key` or `OPENAI_API_KEY`. |
| `NullEmbeddingProvider` | tests, or `provider = 'null'` | throws on `embed()` so misconfiguration is loud instead of silent. |

Both live under `EduLazaro\Laracrate\Embeddings\`. To use your own provider (a self-hosted model, Anthropic, BGE-M3, etc.), bind it in your service provider:

```php
use EduLazaro\Laracrate\Contracts\EmbeddingProvider;

$this->app->bind(EmbeddingProvider::class, \App\Embeddings\MyProvider::class);
```

Note the query side: when you search semantically, the active ChunkStore embeds the query with this same provider, so the query vectors and the stored chunk vectors must come from the same model.

### Chunk stores

A **ChunkStore** is the persistence and search backend. The contract (`EduLazaro\Laracrate\Contracts\ChunkStore`) has five methods:

```php
public function store(File $file, array $chunks): int;
public function getByFile(File $file): \Illuminate\Support\Collection;
public function search(string $query, array $filters = [], array $options = []): \Illuminate\Support\Collection;
public function deleteByFile(File $file): void;
public function driverName(): string;
```

The driver is selected by `chunks.driver` (default `mysql`):

| Driver | Class | Search | Requirements | Scale |
| --- | --- | --- | --- | --- |
| `mysql` | `MysqlChunkStore` | keyword `LIKE` + cosine similarity computed in PHP | none | good up to roughly 5K chunks per scope |
| `meilisearch` | `MeilisearchChunkStore` | native server-side hybrid (BM25 + vector) with `semanticRatio` | `meilisearch/meilisearch-php` + a bound `Meilisearch\Client` | large |

Both classes are under `EduLazaro\Laracrate\Chunks\`. With the `mysql` driver, chunks are rows in `laracrate_file_chunks` (the `text` column has a `FULLTEXT` index, though keyword matching currently uses SQL `LIKE`) and the embedding is stored alongside; search pulls a candidate pool and ranks it in PHP. With `meilisearch`, chunks are pushed as documents into the configured index with embeddings injected as `_vectors.{embedder}` (userProvided mode), and `laracrate_file_chunks` is not written: Meilisearch becomes the single source of chunks while the `.chunks.jsonl` sidecar stays the portable backup.

#### Wiring up Meilisearch

Add the env config:

```env
LARACRATE_CHUNKS_DRIVER=meilisearch
LARACRATE_MEILISEARCH_INDEX=laracrate_file_chunks
LARACRATE_MEILISEARCH_EMBEDDER=default
```

Then bind a `Meilisearch\Client` in your `AppServiceProvider` (Laracrate resolves it from the container and falls back to `MysqlChunkStore` with a warning if it is not bound):

```php
use Meilisearch\Client;

public function register(): void
{
    $this->app->singleton(Client::class, fn () => new Client(
        config('services.meilisearch.host', 'http://127.0.0.1:7700'),
        config('services.meilisearch.key'),
    ));
}
```

The index is created and configured on demand: `MeilisearchSync::ensureIndex()` sets the filterable, sortable and searchable attributes and registers the embedder as `userProvided` with your configured dimensions.

#### A custom store (Qdrant, pgvector, ...)

Implement the five-method contract and bind it directly. Your binding wins over the built-in driver switch:

```php
use EduLazaro\Laracrate\Contracts\ChunkStore;

$this->app->singleton(ChunkStore::class, \App\Chunks\QdrantChunkStore::class);
```

### Searching

Run a search through the active ChunkStore. The signature is `search(string $query, array $filters = [], array $options = [])` and it returns a `Collection` of result rows.

```php
use EduLazaro\Laracrate\Contracts\ChunkStore;

$results = app(ChunkStore::class)->search('termination clause', [
    'fileable_type' => \App\Models\Matter::class,
    'fileable_id'   => $matter->id,
    'tenant_id'     => $org->id,
], [
    'limit'          => 10,
    'semantic_ratio' => 0.7,
]);

foreach ($results as $hit) {
    // $hit['file_id'], $hit['chunk_index'], $hit['text'],
    // $hit['score'], $hit['matched'] ('keyword' | 'semantic' | 'hybrid'),
    // $hit['metadata']
}
```

Supported filter keys: `file_ids` (array of ints), `fileable_type`, `fileable_id`, `tenant_type`, `tenant_id`, `collection`, `context`, `category`. Options are `limit` (default 10) and `semantic_ratio` (a float from 0 to 1, default 0.7).

`semantic_ratio` is the cost and quality dial:

- `0`: keyword only. The query is **not** embedded, so there is no embedding API call. Use this for cheap, exact-match search or when embeddings are off.
- `>0`: the query is embedded with your `EmbeddingProvider` and blended with keyword results. `1.0` is pure semantic. Anything in between is hybrid.

To read the extracted plain text of a single file (no search backend involved), use the model accessor. `extractedText()` returns the full text from the `{key}.json` sidecar, or `null` if extraction has not run:

```php
$text = $file->extractedText();          // full concatenated text, or null
$content = $file->extractedContent();    // ExtractedContent DTO: fullText, pages[], metadata
```

`extractedContent()` returns an `ExtractedContent` object whose `pages` array preserves per-page (or per-segment) text, useful for citing a source page back to the user.

## Access control and authorization

Every collection declares who its files are for: `public` or `private`. It lives in the collection config (see the Configuration section) and is stored on each file as the `access` column, backed by the `EduLazaro\Laracrate\Enums\FileAccess` enum. How a private file reaches the browser is chosen where it is used:

| | Public file | Private file | Sensitive or encrypted file |
| --- | --- | --- | --- |
| `url()` | `Storage::disk()->url()`, permanent, no checks | a presigned GET straight to the disk (a signed local route on a `local` disk), expiring at `expires` or after `laracrate.urls.signed_ttl` | its `streamUrl()` |
| `streamUrl()` / `downloadUrl()` | served through PHP | served through PHP: session, `canView` on every request, log | the same |

`$file->url()` delegates to `StorageManager::urlFor()`: `public` calls `GeneratePublicUrlAction`, a sensitive or encrypted file returns its stream route, and any other private file calls `GenerateSignedUrlAction`. The default signed link is cached server-side (config `laracrate.urls.signed_cache_ttl`, default 4 minutes) so a page rendering many files does not issue one presign per file; a link with its own `expires` is signed every time. If a disk is misconfigured or unreachable, the signed action logs a warning and returns `null` instead of throwing, so one broken file does not break the whole page.

A signed link is checked once, when it is made: whoever holds it can read the file until it expires. The stream route is checked on every request, so it never needs to expire and a revoked permission takes effect at once.

### Declaring authorization rules

Authorization is declared in the **PolicyRegistry** (`EduLazaro\Laracrate\Support\PolicyRegistry`), keyed by the fileable morph alias (`user`, `case`, `property`, and so on). Register your closures from a service provider `boot()` method:

```php
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Database\Eloquent\Model;

public function boot(): void
{
    app(PolicyRegistry::class)
        ->viewable('case', fn (File $file, ?Model $user) => $user?->canAccessCase($file->fileable))
        ->editable('case', fn (File $file, ?Model $user) => $user?->isLawyer())
        ->deletable('case', fn (File $file, ?Model $user) => $user?->isAdmin());
}
```

Each registrar (`viewable`, `editable`, `deletable`) returns `$this`, so calls chain. The closure receives the `File` and the current user (which may be `null` for guests) and returns a boolean.

At decision time, three methods evaluate the rules: `canView()`, `canEdit()`, and `canDelete()`. They are exposed on the registry and mirrored on the model, so `$file->canView($user)`, `$file->canEdit($user)`, and `$file->canDelete($user)` all work in blade and controllers.

### Default policy: deny with exceptions

If no closure is registered for a fileable type, Laracrate applies safe defaults driven by the registry logic:

- The **human creator can always view, edit, and delete** their own file. A file counts as creator-owned when `creator_type` is `user` and `creator_id` matches the current user's key.
- **Public files are always viewable** by anyone (`canView` returns `true` when `access` is `public`), regardless of registered closures.
- For everything else, when no closure exists the answer is `false`. The system is closed by default: you opt files in, you never accidentally leak them.

So a registered `viewable` closure only runs for non-creators viewing a non-public file. Edit and delete have no public exception, only the creator shortcut and your closures.

### The Gate bridge

By default the package binds `EduLazaro\Laracrate\Policies\FilePolicy` to Laravel's Gate, so you get native ergonomics on top of the registry. This is controlled by `laracrate.policies.register_gate` (default `true`):

```blade
@can('view', $file)
    <a href="{{ $file->url() }}">Open</a>
@endcan
```

```php
// In a controller
$this->authorize('update', $file);

// As route middleware
Route::get('/files/{file}', ...)->middleware('can:view,file');
```

The bridge maps Laravel's canonical abilities to the registry methods: Gate `view` calls `canView`, `update` calls `canEdit`, and `delete` calls `canDelete`. If your app already registers its own `File` policy, or you do not want the bridge, set `register_gate` to `false` and call `$file->canView()` (and friends) directly.

## Sensitive content and encryption

For files that must never be served by a direct storage URL, mark the collection `sensitive` (and `encrypt` to store them encrypted). Their `url()` is the stream route, so they are always proxied through `EduLazaro\Laracrate\Http\Controllers\StreamFileController`, which re-checks permissions on every request, logs the access, and (when the file is encrypted) decrypts the binary in memory before sending it. The storage backend URL is never exposed to the client. Any private file can be served the same way with `streamUrl()`.

### The per-request stream flow

`StreamFileController` has two actions, `stream` (inline, `laracrate.files.stream`) and `download` (attachment, `laracrate.files.download`). The routes sit behind `laracrate.stream.middleware` (`['web', 'auth']`) and carry no signature. Every request runs `validateAccess()` before a single byte is sent: `$file->canView($request->user())`, the same PolicyRegistry logic described in the Access control and authorization section. Failure aborts `403`.

Because the policy is checked on every request, these links never expire and can be stored (in a document, an email to a colleague who also has access): a permission revoked later takes effect on the next request.

Files are served with **range support** (`Accept-Ranges: bytes`, `206 Partial Content`), by the stream controller and by the local-disk route alike. Audio and video players depend on it: without ranges a browser cannot seek, cannot start a video whose index sits at the end until the whole file has arrived, and Safari does not play video at all. Only the requested bytes are read, with a ranged GET on S3/R2 or a seek on local disks. An encrypted file is decrypted whole and then sliced.

### Audit and download tracking

`sendFile()` audits before streaming, once per playback: range requests that do not start at the first byte are not counted again. When the request is a `download` and `laracrate.stream.increment_downloads` is on (default `true`), the file's `downloads_count` is incremented and `last_downloaded_at` is stamped with a quiet save (no events fired); showing a file with `stream` is not a download. When `laracrate.stream.log_access` is on (default `true`), an info log records the file id, collection, user id, IP, and method. Streamed responses are sent with `Cache-Control: private, no-store, no-cache, must-revalidate` so sensitive bytes are never cached downstream.

### Encryption at rest

Set `encrypt => true` on a collection to encrypt the binary before it is written to the backend. `CreateFileAction` reads the flag, runs `EncryptFileAction` on the binary, and persists `is_encrypted = true` on the file. Encryption uses Laravel's app key:

- `EncryptFileAction` does `Crypt::encryptString(base64_encode($binary))`.
- `DecryptFileAction` reads the cipher from the backend and returns `base64_decode(Crypt::decryptString($cipher))`.

Encryption requires the binary to be present server-side. If a collection has `encrypt => true` but the upload skipped your server (a direct presigned upload, for example), `CreateFileAction` throws, because there is nothing for PHP to encrypt. Encrypted collections must route the bytes through your app on the way in.

On the way out, the stream controller transparently decrypts: in `sendFile()`, when `$file->is_encrypted` is true it calls `DecryptFileAction::create()->run(['file' => $file])`, otherwise it reads the raw object. An encrypted file never gets a direct link: its `url()` is the stream route, since the binary has to pass through your app to be decrypted anyway.

### Watermarks are baked in, not applied at stream time

Watermarking is not part of the stream flow. The watermark is rendered into the binary of the relevant variant when that variant is generated by the processing pipeline (see the Images, variants and watermarks section), not on each request. The stream controller only validates, decrypts, and serves: it never re-renders pixels. This keeps streaming cheap and makes the watermarked bytes the only bytes that exist for that variant.

## Multi-tenancy, buckets and usage

Laracrate is multi-tenant aware out of the box. Every file carries a `tenant_*` morph (see the Data model section), so a single deployment can serve many organizations, workspaces, or accounts while keeping their files cleanly partitioned. On top of that scope you can give individual tenants their own storage bucket and measure exactly how much each one consumes, without writing a single hand-rolled `SUM()` query.

If you run a single-tenant app, leave `tenant_*` null and skip this section. Everything below is opt-in.

### Scoping files to a tenant

The tenant morph is set automatically when you add a file through your model. How the tenant is resolved is up to you (it comes from `resolveFileTenant()` on the `HasFiles` trait, covered in the Working with files from your models section). Once files are scoped, you can query them with the **`forTenant`** scope on the `File` model:

```php
use EduLazaro\Laracrate\Models\File;

$files = File::forTenant($organization)->get();
```

The scope matches on `tenant_type` (the tenant's morph class) and `tenant_id` (its primary key).

### Per-tenant buckets

By default all tenants share the disks you declare in `config/filesystems.php`. A **tenant bucket** overrides one of those disks for one tenant. This is the foundation for bring-your-own-account (BYOA) setups, data-residency or compliance requirements, and cost attribution, where a tenant's bytes land in a bucket you can bill or audit separately.

Granularity is per disk, not per collection. If your config exposes three disks (`document`, `media`, `attachment`), a tenant can activate a dedicated bucket for any subset of them independently. The disk you override is called the **`base_disk`**.

Declare a tenant bucket by creating a `TenantBucket` row:

```php
use EduLazaro\Laracrate\Models\TenantBucket;

TenantBucket::create([
    'tenant_type' => $organization->getMorphClass(),
    'tenant_id'   => $organization->getKey(),
    'base_disk'   => 'document',                 // the disk in filesystems.php this overrides
    'bucket'      => 'acme-documents',           // bucket name that replaces base_disk's
    'public_url'  => 'https://cdn.acme.example', // optional, overrides the base disk url
    'is_active'   => true,
    'label'       => 'acme-documents (eu-west)', // optional, for your admin UI
]);
```

`TenantBucket` is stored in the `laracrate_tenant_buckets` table and exposes a `tenant()` morph relation back to the owning model. Its columns:

| Column | Type | Purpose |
| --- | --- | --- |
| `tenant_type`, `tenant_id` | string, bigint | The tenant this bucket belongs to. |
| `base_disk` | string | The `config/filesystems.php` disk this row overrides. |
| `bucket` | string | Bucket name that replaces the one from `base_disk`. |
| `public_url` | string, nullable | Overrides the `url` of the base disk if set. |
| `credentials` | encrypted array, nullable | BYOA overrides for `key`, `secret`, `endpoint`, `region`, `driver`. Cast `encrypted:array` (APP_KEY). |
| `is_active` | boolean | Inactive buckets are not resolved and throw if referenced. |
| `label` | string, nullable | Human label for your admin UI. |

There is a unique constraint on `(tenant_type, tenant_id, base_disk)`, so a tenant has at most one bucket per base disk.

Two deployment models are supported:

- **SaaS, single account.** Your R2/S3 credentials live in `.env` and the base disk config. Each tenant bucket only sets `bucket` (and optionally `public_url`); everything else (key, secret, endpoint, region, driver) is inherited from `base_disk`.
- **BYOA, tenant brings their own account.** Put the tenant's `key`, `secret`, `endpoint`, `region`, and `driver` in `credentials`. It is stored encrypted with your APP_KEY and merged on top of the inherited config.

#### How a file picks up its bucket

When you add a file, the `HasFiles` trait calls `resolveTenantBucketDisk()`. If the resolved tenant has an active `TenantBucket` for the collection's `base_disk`, the file is stored with `disk = "tb:{id}"` (the bucket's id), otherwise it keeps the plain disk name. You never write `tb:{id}` by hand.

At read or write time, `StorageManager::diskFor()` (and the lower-level `resolveDisk()`) recognizes the `tb:` prefix, loads the `TenantBucket`, builds the config via `TenantBucket::toDiskConfig()`, and returns the right `Storage::build()` filesystem. The merge cascade is: base disk config, then `bucket` (and `public_url` as `url`), then the BYOA `credentials` overrides. Plain disk names fall straight through to `Storage::disk()`. Every internal operation (`writeBinary`, `moveServerSide`, `batchDelete`, presigned uploads, S3 client lookup) routes through this resolution, so dedicated buckets work everywhere transparently.

If a file references a bucket that no longer exists or has `is_active = false`, resolution throws a `RuntimeException` rather than silently falling back to the shared disk.

### Usage accounting

To enforce quotas or bill by storage, use **`UsageReporter`**, resolved from the container. Each query is a single grouped `SUM(size)` / `COUNT(*)`, so you get totals plus per-collection and per-type breakdowns without scanning rows in PHP.

```php
use EduLazaro\Laracrate\Services\UsageReporter;

$stats = app(UsageReporter::class)->forTenant($organization);

$stats->human();              // "1.42 GB"
$stats->totalFiles;           // 312
$stats->byCollection['gallery']; // ['bytes' => 18234112, 'files' => 45]

if ($stats->exceeds(5 * 1024 ** 3)) {
    // tenant is over its 5 GB quota
}
```

`UsageReporter` has four entry points, each returning a `UsageStats`:

| Method | Scope |
| --- | --- |
| `forTenant(Model $tenant, bool $excludeTrashed = false)` | All files belonging to a tenant. |
| `forCreator(Model $creator, bool $excludeTrashed = false)` | All files created by a given model. |
| `forCollection(string $collection, ?Model $tenant = null, bool $excludeTrashed = false)` | One collection, optionally narrowed to a tenant. |
| `global(bool $excludeTrashed = false)` | Whole system. This can be a heavy scan on large tables, so run it offline or behind a cache. |

By default counts include variants and soft-deleted files, since both still occupy real bytes in the bucket. Pass `excludeTrashed: true` to drop soft-deleted files from the totals.

#### The UsageStats value object

**`UsageStats`** (`EduLazaro\Laracrate\Support\UsageStats`) is an immutable snapshot. Its readonly properties:

| Property | Type | Notes |
| --- | --- | --- |
| `totalBytes` | int | Sum of `size` across the scope. |
| `totalFiles` | int | File count across the scope. |
| `byCollection` | array | `['gallery' => ['bytes' => int, 'files' => int], ...]` |
| `byType` | array | `['image' => ['bytes' => int, 'files' => int], ...]` |

And its methods:

| Method | Returns |
| --- | --- |
| `kilobytes()` / `megabytes()` / `gigabytes()` | float, total in that unit |
| `human(int $precision = 2)` | string like `"1.42 GB"`, `"234 MB"`, `"12 KB"` |
| `exceeds(int $quotaBytes)` | bool, true when over the quota |
| `remaining(int $quotaBytes)` | int bytes left, negative if over |
| `percentageOf(int $quotaBytes)` | float, 0 to 100+ |
| `toArray()` | array with `total_bytes`, `total_files`, `by_collection`, `by_type` |

Store your per-tenant limit however you like (a `quota_bytes` column on your tenant model is a common choice) and gate uploads with `exceeds()`:

```php
$stats = app(UsageReporter::class)->forTenant($organization);

abort_if(
    $stats->exceeds($organization->quota_bytes),
    403,
    'Storage quota exceeded.'
);
```

#### Cached usage counters and recompute

For collections where you want a live counter without aggregating on every request, Laracrate can maintain per-fileable totals (file count and byte size) in the `laracrate_folderables` table, kept up to date by the file observer. To enable this, set `track_usage` on the collection in `config/laracrate.php`.

Because observers can miss writes (manual imports, restores from backup, a failed event), the **`laracrate:recompute-usage`** command rebuilds those counters from the source of truth in `laracrate_files`. It is idempotent and safe to run on a schedule.

```bash
php artisan laracrate:recompute-usage              # every collection with track_usage enabled
php artisan laracrate:recompute-usage drive        # only the "drive" collection
php artisan laracrate:recompute-usage --dry-run    # print deltas without writing
```

It aggregates top-level files (no `parent_id`) per `(fileable_type, fileable_id)`, updates each `laracrate_folderables` row, stamps `last_recomputed_at`, and resets orphaned rows (files all gone) to zero. See the Artisan commands section for the full command list.

## Livewire components and themes

Laracrate ships an **optional** Livewire UI layer. The core (models, storage, pipeline, HTTP endpoints) works without Livewire, so you can ignore this section entirely and build your own front end against the methods in the "Working with files from your models" section. If you do use Livewire, these components wire the model trait, collection config, and upload flow together for you.

The components register only when Livewire is installed (`registerLivewireComponents()` no-ops otherwise). There is one view per family, and the look is CSS: import `resources/css/laracrate.css` once (see "Themes and customising").

Minimal example: a single-file uploader for a user avatar.

```blade
<livewire:laracrate-uploader :model="$user" collection="avatar" />
```

### The three components

All three are registered in `LaracrateServiceProvider`. They split into two families: the **card uploader** (single file, in-place preview) and the **dropzones** (presigned direct upload to R2/S3, see the "Upload modes" and "HTTP endpoints" sections).

| Tag | Class | Files | Use case |
|-----|-------|-------|----------|
| `laracrate-uploader` | `LaracrateUploader` | single, replaces the current one | Avatar, logo, cover image |
| `laracrate-dropzone` | `LaracrateDropzone` | multiple, with per-file and batch events, file slots and a slot picker | Galleries, document libraries, forms where files are tied to slots |
| `laracrate-dropzone-single` | `LaracrateDropzoneSingle` | single, replaces the current one | One large file (a video, a PDF) too big for the card uploader |

Each one uploads as soon as a file is picked, or with `:deferred="true"` stages it for review and only persists when the user confirms. The dropzones upload straight to your disk with a presigned PUT and the binary never touches PHP. `laracrate-uploader` sends the file through PHP (no CORS needed on the bucket, and the only way an `encrypt` collection can be uploaded). Both go through the shared upload queue, so they keep uploading after a `wire:navigate`: see "Upload modes, grants and the upload tray". The deferred uploader stages the file with Livewire's temporary upload instead and saves it on `submit()`; leaving before submitting saves nothing, by design. Pick the dropzones for large files.

2.0 removes the 1.x tags `laracrate-uploader-deferred`, `laracrate-dropzone-deferred` and `laracrate-dropzone-single-deferred`: use the component without the suffix and `:deferred="true"`.

### Props

The card uploader accepts:

| Prop | Type | Default | Purpose |
|------|------|---------|---------|
| `model` | Eloquent model | required | The model the file belongs to (must use the `HasFiles` trait) |
| `collection` | string | required | Collection name as declared on the model |
| `variant` | string | `null` | Variant to show in the preview (falls back to the master, then the collection placeholder) |
| `layout` | string | `row` | `row` or `portrait` |
| `rounded` | string | `null` | Rounds the preview (an avatar in a circle with `full`): `none`, `sm`, `md`, `lg`, `xl`, `2xl`, `3xl`, `full` |
| `deferred` | bool | `false` | Preview the file, then `submit()` to persist or `cancel()` to discard |

Every component also takes the appearance props of "Themes and customising" (`theme`, `rounded`, `bordered`, `shadow`, `size`, `class`, `style`) and `upload-mode` (`presign` or `grant`, see "Upload modes, grants and the upload tray").

The dropzones share `model`, `collection` and `deferred`, plus their own props. Common ones: `contextKey` (an opaque identifier echoed back in the `laracrate-file-uploaded` event so you can route a file when several widgets share a page), `folderId` (target folder, see the "Folders" section), `persistQueue`, `maxFiles`, and `hideActions` (a deferred one without its own buttons, started by a `laracrate-start-batch` event). `laracrate-dropzone` adds slot support (`slots`, `slotOptions`, `slotLabel`, `slotPlaceholder`, `slotOptional`, `selectedSlotId`), a `creator` override (`creator`, or `creatorType` plus `creatorId`), and a `layout` of `grid` or `list`. `laracrate-dropzone-single` adds `hideExisting`. See the "File slots" section for slot semantics.

### Immediate vs deferred

Instant uploader, persists on selection:

```blade
<livewire:laracrate-uploader :model="$user" collection="avatar" theme="ios" variant="medium" />
```

Deferred uploader, shows a preview and waits for the user to confirm:

```blade
<livewire:laracrate-uploader
    :model="$user"
    collection="avatar"
    :deferred="true"
    theme="studio"
    layout="portrait"
/>
```

A deferred multi-file dropzone, queue then batch upload:

```blade
<livewire:laracrate-dropzone :model="$organization" collection="gallery" :deferred="true" theme="studio" />
```

All components dispatch browser events you can listen for: `laracrate-file-uploaded` (per file, with `collection`, `fileId`, and `contextKey`), `laracrate-file-deleted` or `laracrate-file-removed`, and on the dropzones `laracrate-batch-completed` (with `ok` and `error` counts) and `laracrate-file-rejected` (with `collection`, `reason` and `fileName`) when a file is refused by the collection or a slot quota. The upload ones are sent only while the page that started the upload is still open: the page reached after a `wire:navigate` may listen for the same collection with another meaning. See "Upload modes, grants and the upload tray" for the events that always fire, and the "Events" section for the server-side events fired during processing.

### Upload modes, grants and the upload tray

The upload components work in one of two modes, `presign` by default (`laracrate.uploads.mode`, optionally `LARACRATE_UPLOAD_MODE`), and each one can pick its own:

```blade
<livewire:laracrate-dropzone :model="$case" collection="documents" />                     {{-- presign --}}
<livewire:laracrate-dropzone :model="$case" collection="documents" upload-mode="grant" />
```

- **presign**: when the user picks files, the component signs them right there, with the collection and slot rules, and the server keeps where each signed key goes. The queue puts the bytes and registers each object with its key alone (`register`); the browser never gets a grant nor names a target. The whole pick is signed at once, so `laracrate.uploads.presign_minutes` (120) is how long a long batch has.
- **grant**: the component hands the browser an upload grant (below) and the queue signs each file with it when its turn comes.

Either way the uploads live in the page's queue and keep going after a `wire:navigate`. Presign only puts bytes straight in the bucket, so a component that uploads through PHP (an encrypted collection, the single file `laracrate-uploader`) always uses its grant. A key is registered once, only by the user it was signed for and in the same browser session, and with the size the bucket holds.

In grant mode, an upload component builds an **upload grant** when it mounts: its target (model, collection, slots, creator, owner, folder, context key, whether it replaces the current file) and how the bytes travel, bound to the signed in user (their class and id, so a client #5 is not a user #5) and to the browser session it was issued in, and valid for 24 hours. A token copied out of the page does not work in another browser, even with the same account. The browser only gets an encrypted token (`$wire.uploadGrant`) it can neither read nor alter, and the upload endpoints (`sign`, `confirm`, `receive`) take nothing else: the disk, the model and the object key never come from the request. A direct upload gets a receipt for the `temp/` key it was signed, and only that grant can confirm it; the size checked is the one the bucket holds, not the one the browser declared.

The uploads themselves live in one queue for the whole page, `window.Laracrate.uploads` (an Alpine store when Alpine is there), not in the component. That is what lets them survive a `wire:navigate`: the queue keeps going, and a component mounted again for the same target (`$wire.uploadScope`) shows its uploads where they are. Put the tray in your layout to show them all, inside `@persist` so it is not re-created on each navigation:

```blade
@auth
    @persist('laracrate-uploads')
        @include('laracrate::upload-tray')
    @endpersist
@endauth
```

Pass `['position' => 'bottom: 3rem; right: 1rem;']` to move it. It uses its own CSS (your Tailwind build does not scan the package views); the colours are the `--wire-laracrate-tray-*` variables on `.laracrate-tray`. A reload, closing the tab or a link without `wire:navigate` still ends the uploads; while any is running the browser asks first.

Your own front end can use the same queue with a grant built on the server:

```php
use EduLazaro\Laracrate\Uploads\UploadGrant;

$grant = UploadGrant::for($organization, 'drive', folderId: $folder?->id);
// To the view: $grant->token() and $grant->scope()
```

```js
Laracrate.uploads.add(input.files, { grant, scope })
window.addEventListener('laracrate:uploaded', (e) => { /* e.detail: scope, fileId, slug, collection, contextKey, name */ })
```

A grant can cap how many files it signs with `maxFiles`, counted on the server by `sign`, `receive` and the multipart `init`. A front end that knows how many files were dropped issues the grant for exactly that batch, so it is no good for another one later:

```php
$grant = UploadGrant::for($organization, 'drive', folderId: $folder?->id, maxFiles: $count);
```

The queue sends an id with each file (`upload`), so retrying one does not count as another; a request without it always counts as a new file. Without `maxFiles` there is no limit. The components do not set it: their `max-files` is how many files the target holds, not how many uploads a grant signs.

Grants carry a version, and updating to one that changes it invalidates the grants of pages already open: their next upload asks to reload the page.

A page that shows its uploads itself (a chat composer with its attachments) passes `tray: false` to `add()`, and the tray leaves them out.

`laracrate:upload` fires on every change of every upload (`{ type, item }`), and `laracrate:uploaded` once per saved file, whatever page the user is on by then. Logic that must run after an upload no matter what (attach the file somewhere, start a job) belongs on the server, in an observer or a listener of the File, not in a browser listener.

A direct upload that is cancelled or never confirmed leaves its object under `temp/`. Add a lifecycle rule on that prefix in your bucket (R2 and S3 both have one) to delete what is older than a day.

### The viewer

`laracrate::viewer` shows one image, audio or video at a time in a card at the bottom of the page. Images and videos open to full size from there (the same element grows, so a video keeps playing). Put it in your layout inside `@persist`, so what is playing keeps playing across `wire:navigate`:

```blade
@auth
    @persist('laracrate-viewer')
        @include('laracrate::viewer')
    @endpersist
@endauth
```

Open a file with `Laracrate.viewer.open(slug)`, or give any element `data-laracrate-view="{{ $file->slug }}"`: a plain click on it opens the viewer, while Ctrl, Cmd, Shift or the middle button leave a link to do what it does. Opening the file that is already open leaves it as it is (a paused one plays on).

Elements that share a `data-laracrate-gallery="name"` form a gallery: the viewer shows "2 of 5" and moves through them in page order with its arrows and the arrow keys (not while the person types in a field). Hidden elements count too, so a grid that draws four images with "+N" can still reach the rest. From JavaScript: `Laracrate.viewer.open(slug, { gallery: [slug, ...] })`.

The page never holds a link to the file. The viewer asks `GET /laracrate/files/{slug}/viewer` (`laracrate.files.viewer`) when it opens, which answers with the name, the type and a link made at that moment (`url()`), after the file's own view policy. A signed link may run out while a long recording plays; the viewer then asks again, once, and carries on at the same second. Images (whatever their mode) show the variant in `laracrate.viewer.image_variant` (`display`) and videos the one in `laracrate.viewer.video_variant` (none by default), each falling back to the file itself. Other types answer 422, unless a preview is registered for their MIME type.

```php
'viewer' => [
    'image_variant' => 'display',
    'video_variant' => null,       // e.g. 'web', a copy declared in the video variants
],
```

#### Previews for other types

A package or the app can show other files in the viewer as HTML: register a resolver for their MIME type on `ViewerRegistry`, usually from a service provider's `boot()`:

```php
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ViewerRegistry;

app(ViewerRegistry::class)->html('application/x-note', fn (File $file) => [
    'html' => Note::forFile($file)->html,         // what the card shows
    'edit' => route('notes.edit', $file->slug),   // optional: an "Edit" button
]);
```

The resolver runs after the view policy and may return `null` to fall back to the 422. The HTML goes into a sandboxed `iframe` (no scripts, no access to the page), small and scrolling in the card and readable at full size. `edit` adds a pencil that follows the link with `wire:navigate`; `download`, if given, a download button. `laracrate-docs` registers its documents this way.

Pass `['position' => '...']` to move it; the colours are the `--wire-laracrate-viewer-*` variables. Next to the upload tray, stack both in one container of your layout.

### Handoff: editing a file in another site

A file can be opened in an editor of another site and come back as a copy next to the original, with the handoff protocol (windows and `postMessage`, version 1; Auditius publishes it in `src/lib/handoff/SPEC.md`). Declare the editors in config:

```php
'handoff' => [
    'editors' => [
        'auditius' => [
            'name'    => 'Auditius',
            'url'     => 'https://auditius.com/?editor&handoff=1', // its handoff URL; the editor answers from this origin
            'types'   => ['audio'],                                 // file types it opens
            'formats' => ['audio/mpeg'],                            // what the copy may come back as
        ],
    ],
],
```

Include `laracrate::handoff` once in the layout, with the name the editor shows on its "Save to ..." command, and call `Laracrate.handoff.open()` from a click:

```blade
@include('laracrate::handoff', ['appName' => 'Inis Legal'])

<button @click="Laracrate.handoff.open('auditius', '{{ $file->slug }}').catch((e) => e.code !== 'closed' && alert(e.message))">
    Edit in Auditius
</button>
```

`open()` opens the editor window inside the click, reads the file from your site (`GET /laracrate/files/{slug}/handoff/{editor}`, counted as a download) and hands it over once the editor says it is ready. Whoever may see the file may open it; only whoever may edit it gets somewhere to save. The editor saves into `GET /laracrate/files/{slug}/handoff/{editor}/save`, a page of its own that takes the file only from that editor's origin, lets the person name the copy and uploads it with the upload queue under a grant for the original's model, collection and folder, so the collection's rules apply as to any upload. Other tabs of your app get a `laracrate:uploaded` event when the copy is saved.

An editor only saves to `https` (or `localhost` while developing). On any other address, `open()` still works but offers nowhere to save.

### Themes and customising

The look of every component is one stylesheet. Import it once, next to the other wire* packages:

```css
/* resources/css/app.css */
@import '../../vendor/edulazaro/laracrate/resources/css/laracrate.css';
```

**Themes.** `default`, `studio`, `minimal`, `material`, `ios`, `glassmorphism`, `neon`, `neumorphism`, `brutalist`, `claude` and `chatgpt`. A component without `theme=` takes the theme of its page, the `data-wire-theme` attribute the wire* packages put on an ancestor (usually `<html>`); without one, it looks like `default`. There is no theme setting in the config, as in the rest of the wire* family.

```blade
<html data-wire-theme="studio">                      {{-- the whole page --}}
<livewire:laracrate-dropzone ... theme="minimal" />  {{-- this one --}}
```

**A theme of your own** is CSS, no views to publish. A theme only sets variables; everything you leave out keeps the default. The general ones are the wire* family's, so one theme styles Laracrate, the tables and the modals together; the `--wire-laracrate-*` ones are for Laracrate only:

```css
[data-wire-theme="acme"] {
    --wire-font: 'Instrument Sans', sans-serif;
    --wire-accent: #ff4f38;
    --wire-radius: 16px;
    --wire-laracrate-drop-border-color: #1a1719;
}
```

**One rendering.** Parameters on the tag change only that rendering:

| Prop | Values | Effect |
|---|---|---|
| `theme` | a theme name | this component's theme |
| `rounded` | `none`, `sm`, `md`, `lg`, `xl`, `2xl`, `3xl`, `full` | the radius (in the uploaders, the preview's) |
| `bordered` | `false` | no borders |
| `shadow` | `true`, `false` | a soft shadow, or none whatever the theme says |
| `size` | `sm`, `md`, `lg` | scales text, spacing and icons together |
| `class` | classes | on the root, to target this one from your CSS |
| `style` | inline style | on the root, e.g. `--wire-laracrate-drop-padding: 1rem` |

```blade
<livewire:laracrate-dropzone :model="$case" collection="documents"
    theme="studio" rounded="none" :bordered="false" size="sm"
    style="--wire-laracrate-drop-padding: 1rem" />
```

**The variables.** Each one falls back to its wire* family counterpart where there is one (`--wire-font`, `--wire-text`, `--wire-text-muted`, `--wire-accent`, `--wire-radius`), then to the `default` theme.

| Group | Variables (`--wire-laracrate-…`) |
|---|---|
| General | `font`, `mono`, `text`, `text-muted`, `text-faint`, `accent`, `accent-hover`, `accent-text`, `danger`, `bg`, `border-color`, `border-width`, `radius`, `shadow` |
| Drop area | `drop-bg`, `drop-hover-bg`, `drop-border-width`, `drop-border-style`, `drop-border-color`, `drop-hover-border-color`, `drop-radius`, `drop-padding`, `drop-align`, `drop-items`, `drop-shadow`, `drop-hover-shadow`, `drop-hover-transform`, `drop-backdrop`, `dragover-bg`, `dragover-border-color`, `dragover-shadow`, `dragover-transform` |
| Icon | `icon-display`, `icon-box`, `icon-size`, `icon-padding`, `icon-bg`, `icon-color`, `icon-border`, `icon-radius`, `icon-shadow`, `icon-filter`, `icon-gap` |
| Text | `title-size`, `title-weight`, `title-color`, `title-font`, `title-transform`, `title-spacing`, the same for `subtitle-*`, `hint-size`, `hint-color`, `hint-font`, `hint-transform`, `hint-spacing`, the `label-*` ones (status and eyebrows, plus `label-style`), `name-weight` |
| Button and link | `button-bg`, `button-hover-bg`, `button-text`, `button-border-width`, `button-border-color`, `button-radius`, `button-shadow`, `button-hover-shadow`, `button-hover-transform`, `button-size`, `button-weight`, `button-transform`, `button-spacing`, `button-backdrop`, `link-color`, `link-hover-color`, `link-size`, `link-font`, `link-transform`, `link-spacing`, `link-decoration` |
| Progress | `progress-bg`, `progress-fill`, `progress-height`, `progress-radius`, `progress-border`, `progress-glow` |
| Files | `tile-radius`, `tile-border`, `tile-bg`, `tile-placeholder-bg`, `tile-placeholder-color`, `tile-name-bg`, `tile-name-font`, `overlay-uploading`, `overlay-done`, `overlay-error`, `card-bg`, `card-border`, `card-radius`, `card-shadow`, `media-bg`, `preview-radius`, `icon-button-border`, `icon-button-color`, `icon-button-radius`, `icon-button-bg` |

Every part has a stable class (`.laracrate-drop`, `.laracrate-title`, `.laracrate-button`, `.laracrate-tile`, `.laracrate-card`…), and states are `data-state` (`pending`, `uploading`, `done`, `error`) and `is-*` classes (`is-dragover`), for whatever variables do not reach.

## React and Svelte components

The three upload components also come for React (18 or later) and Svelte 5: `Dropzone`, `DropzoneSingle` and `Uploader`. They render the same markup as the Livewire ones, so `laracrate.css`, the themes and the appearance props apply as they are, and they put the files in the same page queue (`window.Laracrate.uploads`), so an upload keeps going when the component unmounts on an Inertia or SPA navigation.

```jsx
<Uploader target="avatar" layout="portrait" rounded="full" />
<Dropzone target="venue.gallery" params={{ venue: venue.id }} />
```

### Setup

They ship as source in `resources/js` and your app's Vite builds them, with the React or Svelte plugin it already has. There is no npm package. An alias keeps the imports short:

```js
// vite.config.js
import path from 'node:path';

export default defineConfig({
    resolve: {
        alias: { '@laracrate': path.resolve(__dirname, 'vendor/edulazaro/laracrate/resources/js') },
    },
});
```

```css
/* resources/css/app.css */
@import '../../vendor/edulazaro/laracrate/resources/css/laracrate.css';
```

```js
import { Dropzone, DropzoneSingle, Uploader } from '@laracrate/react/index.js';   // React
import { Dropzone, DropzoneSingle, Uploader } from '@laracrate/svelte/index.js';  // Svelte
```

The endpoints take the CSRF token from `<meta name="csrf-token">`, as the Livewire components do. If you changed a route prefix, tell the components once with `configure({ signUrl, confirmUrl, registerUrl, targetUrl, removeUrl, statusUrl })`, exported from the same `index.js`.

### Upload targets

A Livewire component builds its own [upload grant](#upload-modes-grants-and-the-upload-tray) on the server. These run in the browser, so the app declares where they may upload, and who may, once, in `routes/uploads.php`, the way it declares its broadcast channels:

```php
// routes/uploads.php
use App\Models\User;
use App\Models\Venue;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use EduLazaro\Laracrate\Uploads\UploadTargets;

UploadTargets::define('avatar', fn (User $user) => UploadGrant::for($user, 'avatar', replace: true));

UploadTargets::define('venue.gallery', fn (User $user, Venue $venue) =>
    $user->can('update', $venue) ? UploadGrant::for($venue, 'gallery') : null);

UploadTargets::define('venue.cover', fn (User $user, Venue $venue) =>
    $user->can('update', $venue) ? UploadGrant::for($venue, 'cover', replace: true) : null);
```

The callback gets the signed in user, then the component's `params` by name: a model by its route key (a slug if the model routes by slug), a scalar cast to its type. It returns a grant, or `null` to refuse. **The params come from the browser**: the callback is where the app checks that this user may upload there, as a channel checks who may listen. A param that does not resolve, a guest when the first argument is not nullable (`?User $user`), or a user of another class refuses the target too.

The file is read the first time a component asks for a target (`laracrate.uploads.targets`); a service provider can call `UploadTargets::define()` too. The endpoint lives in the uploads route group, so it takes its middleware (`auth` by default).

When it mounts, a component reads what to show, `GET /laracrate/uploads/targets/{target}?params[venue]=12`, with no grant yet:

- the collection's **rules**: the mime types for the file picker, the extensions and the largest size for the hint, and the kind of file (`image`, `video`, `audio`, `document` or `mixed`) for the icon. The server checks them again on every upload;
- for a target that replaces its file (`replace: true`), the **current file**;
- the target's **scope**, to show again the uploads still running for it.

The callback runs there too, so a user it refuses sees why when the component mounts, not after picking a file. The **grant** is asked for only when the user picks or drops files (or presses Upload in a deferred one), `POST /laracrate/uploads/targets/{target}`, and used right away: no grant goes out to whoever only looks at the page, and none is ever old. Meanwhile the files wait in the queue with their spinner.

From there it does the rest: it shows the file just uploaded (the upload endpoints answer it whole, as `File::toComponentArray()`), polls `GET /laracrate/files/{slug}/status` while it is processed, and its delete button removes it with `POST /laracrate/uploads/remove`. Only a grant that replaces can remove, and only the current file of its target: that grant deletes it anyway with its next upload.

### `Dropzone`

Several files, as a grid of tiles or a list of rows.

```jsx
<Dropzone target="case.documents" params={{ case: caseId }} layout="list" maxFiles={10}
    onUploaded={(file) => ...} onBatchCompleted={({ ok, error }) => ...} />
```

### `DropzoneSingle`

One file in a card: the drop area, or the current file to replace, view or remove.

```jsx
<DropzoneSingle target="venue.cover" params={{ venue: venue.id }} />
```

### `Uploader`

One file shown big, an avatar or a logo, in a `row` or `portrait` layout. `rounded` rounds the preview, not the card.

```svelte
<Uploader target="avatar" layout="portrait" rounded="full" onuploaded={(file) => (me.avatar = file.preview)} />
```

### Props

Svelte takes the same props, with the callbacks in lower case (`onuploaded`, `onbatchcompleted`) and `class` instead of `className`.

| Prop | Components | Default | |
|---|---|---|---|
| `target`, `params` | all | | the upload target and its params |
| `deferred` | all | `false` | stage the files until the Upload button, or `startBatch()` |
| `hideActions` | `Dropzone`, `DropzoneSingle` | `false` | no Upload and Cancel buttons: the page calls `startBatch()` |
| `multiple` | `Dropzone` | `true` | |
| `maxFiles` | `Dropzone` | | how many files it takes; it closes once it holds them |
| `layout` | `Dropzone`, `Uploader` | `grid`, `row` | `grid` or `list`; `row` or `portrait` |
| `persistQueue` | `Dropzone` | `false` | keep the uploaded files listed |
| `removable` | `DropzoneSingle`, `Uploader` | `true` | the delete button |
| `pollMs` | `DropzoneSingle`, `Uploader` | `1500` | how often a file being processed is asked about |
| `accept`, `maxSizeKb` | all | the target's | to show other rules than the collection's |
| `extensions` | `DropzoneSingle` | the target's | shown in the hint |
| `kind` | `DropzoneSingle`, `Uploader` | the target's | what the collection takes, for the icon and the preview |
| `icon` | `Dropzone`, `DropzoneSingle` | | the drop area's icon: `upload`, `image`, `video`, `audio`, `document`, `file` |
| `locale`, `labels` | all | `en` | `en` or `es`, and any text of your own: `labels={{ upload: 'Add photos' }}` |
| `theme`, `rounded`, `bordered`, `shadow`, `size`, `className`, `style` | all | | as in [Themes and customising](#themes-and-customising) |

| Callback | |
|---|---|
| `onUploaded(file, item)` | once per saved file, as `File::toComponentArray()` plus `collection`, `contextKey` and `scope` |
| `onRejected(item)` | a file the server refused, with its reason in `item.error` |
| `onBatchCompleted({ ok, error })` | when a batch ends |
| `onMaxFiles({ max, rejected })` | `Dropzone`: files left out for `maxFiles` |
| `onRemoved(file)` | `DropzoneSingle`, `Uploader`: the current file, once removed |

A ref in React, or `bind:this` in Svelte, gives `startBatch()` and `clear()`.

What runs after an upload no matter what (attach the file, start a job) belongs on the server, as with the Livewire components, not in `onUploaded`.

### Without a target

An app can also issue the grant itself and pass it as `grant`: `{ grant, scope }`, or a function that takes the files picked and returns them (or a promise of them). Then it also passes the current file as `file`, `File::toComponentArray()` or its own JSON with `url`, `name` and `size` (and `preview`, `type`, `processing_status`, `slug` to be polled):

```php
$grant = UploadGrant::for($user, 'avatar', replace: true);

return Inertia::render('Profile/Edit', [
    'avatarGrant' => ['grant' => $grant->token(), 'scope' => $grant->scope()],
    'avatar' => $user->file('avatar')?->toComponentArray(),
]);
```

```jsx
<Uploader grant={avatarGrant} file={avatar} />
```

Such a grant lives 24 hours. `file` also works with a target, to show another file than the target's (`null` for none).

## Localization

The UI strings used by the Livewire components are translatable through the `laracrate` translation namespace, loaded by the service provider via `loadTranslationsFrom(__DIR__ . '/../lang', 'laracrate')`. The package ships English (`lang/en/uploader.php`) and Spanish (`lang/es/uploader.php`) out of the box, and resolves strings against your app's active `locale`.

Reference a string with the namespaced key:

```blade
{{ __('laracrate::uploader.upload') }}
```

The strings live in the `uploader` group (the viewer's in `viewer`, the handoff pieces' in `handoff`). A sample of the `uploader` keys (see the source files for the full list):

| Key | English | Spanish |
|-----|---------|---------|
| `upload` | Upload file | Subir archivo |
| `select` | Select file | Seleccionar archivo |
| `replace` | Replace | Reemplazar |
| `delete` | Delete | Eliminar |
| `delete_confirm` | Delete this file? | ¿Borrar este archivo? |
| `submit` | Upload | Subir |
| `cancel` | Cancel | Cancelar |
| `uploading` | Uploading... | Subiendo... |
| `processing` | Processing | Procesando |
| `failed` | Error | Error |
| `slot_placeholder` | Unclassified | Sin clasificar |
| `max_size` | max :size MB | máx :size MB |

Keys like `max_size` and `max_size_capital` take a `:size` placeholder.

### Overriding strings

Publish the translation files into your app, then edit them:

```bash
php artisan vendor:publish --tag=laracrate-translations
```

The files land in `lang/vendor/laracrate/{locale}/uploader.php`. Any key you define there overrides the package default for that locale; keys you leave out fall back to the package.

### Adding a locale

Create `lang/vendor/laracrate/{locale}/uploader.php` for the new locale (copy `en/uploader.php` and translate the values, keeping the keys), then set your app locale. For example, to add French, add `lang/vendor/laracrate/fr/uploader.php` and the components will pick it up when `app()->getLocale()` returns `fr`.

## Artisan commands

Laracrate ships three commands for the maintenance work that keeps storage costs and usage counters honest. None of them run on their own: register the ones you need in your scheduler.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('laracrate:abort-stale-multipart')->hourly();
Schedule::command('laracrate:purge-expired')->hourly();
Schedule::command('laracrate:recompute-usage')->daily();
```

### `laracrate:abort-stale-multipart`

Aborts multipart upload sessions that passed their `expires_at` without completing. This is the important one for production: until a multipart session is aborted, the parts already uploaded to S3/R2 keep occupying storage and billing you indefinitely. The command loads stale sessions via the `MultipartUpload::stale()` scope and runs `AbortMultipartUploadAction` on each, marking them `MultipartUploadStatus::EXPIRED`.

```bash
php artisan laracrate:abort-stale-multipart
php artisan laracrate:abort-stale-multipart --dry-run --limit=200
```

| Option | Default | Effect |
| --- | --- | --- |
| `--dry-run` | off | List the stale sessions, abort nothing. |
| `--limit=` | `500` | Maximum sessions to process per run. |

See the Multipart uploads coverage in the Upload modes section for how these sessions are created.

### `laracrate:purge-expired`

Deletes files from collections that declare a TTL and have outlived it. A collection opts in by setting `ttl_hours` in its `config/laracrate.php` entry. The command walks every configured collection, computes a cutoff of `now()->subHours($ttlHours)`, and force-deletes top-level files (`parent_id` null) created before it. It uses `forceDelete()` on purpose so the `FileObserver` purges the binary and its sidecars from the backend and cascades to variants.

```bash
php artisan laracrate:purge-expired
php artisan laracrate:purge-expired --collection=temp_uploads --dry-run
```

| Option | Default | Effect |
| --- | --- | --- |
| `--collection=` | all | Restrict to one collection. |
| `--dry-run` | off | List expired files, delete nothing. |
| `--limit=` | `1000` | Maximum files to process per collection. |

Collections without a positive `ttl_hours` are skipped, so this command is safe to schedule even if only some collections are temporary. See the Configuration section for `ttl_hours`.

### `laracrate:recompute-usage`

Recomputes the `laracrate_folderables` usage counters (`files_count`, `total_size_bytes`) from `laracrate_files`. The per-row counters are kept up to date by the `FileObserver`, but they can drift if the observer fails, you import rows by hand, or you restore from a backup. This command re-aggregates the truth, updates `last_recomputed_at`, and resets orphaned rows to `0/0`. It is idempotent, so running it repeatedly always converges to the correct state.

It only touches collections with `track_usage` enabled in config, unless you name one explicitly.

```bash
php artisan laracrate:recompute-usage
php artisan laracrate:recompute-usage drive
php artisan laracrate:recompute-usage --dry-run
```

| Argument / Option | Default | Effect |
| --- | --- | --- |
| `collection` (argument) | all tracked | Restrict to one collection. |
| `--dry-run` | off | Print the per-row deltas without persisting. |

See the Multi-tenancy, buckets and usage section for how `track_usage` and the usage counters work.

## Events

Laracrate fires plain Laravel events around the processing pipeline so your app can react without coupling to the internals: refresh UI, invalidate caches, notify users, or index vectors into your search engine. Every event uses the `Illuminate\Foundation\Events\Dispatchable` trait, lives under the `EduLazaro\Laracrate\Events` namespace, and exposes its payload as public constructor-promoted properties.

```php
use EduLazaro\Laracrate\Events\FileProcessed;
use Illuminate\Support\Facades\Event;

Event::listen(function (FileProcessed $event) {
    $event->file->fileable->notify(new FileReady($event->file));
});
```

| Event | When it fires | Payload |
| --- | --- | --- |
| `FileProcessingStarted` | Just before the pipeline iterates its steps. The file is already marked `ProcessingStatus::PROCESSING`. | `File $file` |
| `FileProcessed` | The pipeline finished successfully. The file is already marked `ProcessingStatus::COMPLETED`. | `File $file` |
| `FileProcessingFailed` | A step threw. The file is marked `ProcessingStatus::FAILED` and `processing_error` holds the message. The queue retries if the job has tries left. | `File $file`, `Throwable $exception` |
| `VariantGenerated` | A child file (`parent_id` set) is persisted: thumbnail, preview, transcoded copy, watermarked variant, and so on. Fired centrally from the observer, independent of which action created it. | `File $variant`, `?File $parent` |
| `EmbeddingsReady` | The embedding step finished and produced at least one new vector. | `File $file`, `int $count` (chunks that ended up with an embedding in this pass) |

A common use of `EmbeddingsReady` is to mirror the generated vectors into your search backend (pgvector, Meilisearch, Qdrant) from a queued listener:

```php
namespace App\Listeners;

use EduLazaro\Laracrate\Events\EmbeddingsReady;
use Illuminate\Contracts\Queue\ShouldQueue;

class IndexFileVectors implements ShouldQueue
{
    public function handle(EmbeddingsReady $event): void
    {
        // $event->count chunks now carry an embedding
        MyVectorIndex::sync($event->file);
    }
}
```

Register listeners the usual way (an `Event::listen` call in a service provider, an auto-discovered `handle` method, or a `#[AsEventListener]` attribute). See the Processing pipeline section for what runs between `FileProcessingStarted` and `FileProcessed`, and the Text extraction, embeddings and search (RAG) section for the embedding step behind `EmbeddingsReady`.

## API reference

A compact, signature-level reference for the public surface of Laracrate. Every entry below is copied from source. For narrative usage of each piece, see the section it belongs to (working with files from your models, displaying files, processing pipeline, and so on).

### `HasFiles` trait

Add `use EduLazaro\Laracrate\Concerns\HasFiles;` to any model. See the Working with files from your models section for usage.

```php
// Relations and lookups
public function files(?string $collection = null): MorphMany;       // top-level only, ordered by position
public function file(string $collection): ?File;                    // latest, default-first
public function getFile(string $collection): ?File;                 // alias of file()
public function defaultFile(string $collection): ?File;
public function images(?string $collection = null): MorphMany;

// Mutations
public function addFile(
    UploadedFile|\EduLazaro\Laracrate\Support\Binary|FileUpload|string $file,
    string $collection,
    array $data = [],
    array $slots = [],
    ?Model $creator = null,
    ?Model $owner = null,
    ?Folder $folder = null,
): ?File;
public function setFile(
    string $collection,
    UploadedFile|\EduLazaro\Laracrate\Support\Binary|FileUpload|string|null $file,
    array $data = [],
    ?Model $creator = null,
    ?Model $owner = null,
): ?File;                                                            // replaces (force-deletes existing)
public function setDefaultFile(File $file): File;
public function deleteFile(File $file, bool $forceDelete = false): bool;
public function reorderFiles(string $collection, array $orderedIds): void;

// Rendering helpers
public function fileLink(string $collection, ?string $variant = null, ?string $forceType = null): ?string;
public function fileRender(string $collection, ?string $variant = null, array $attrs = []): HtmlString;

// Config and tenant resolution
public function getCollectionConfig(string $collection): array;
public function getDiskFor(string $collection): string;
public function resolveFileTenant(): ?Model;                        // override per app to point at your tenant model
```

### `File` model

`EduLazaro\Laracrate\Models\File`. Table `laracrate_files`. Route key is `slug`.

```php
// Relations
public function fileable(): MorphTo;
public function creator(): MorphTo;
public function owner(): MorphTo;
public function tenant(): MorphTo;
public function parent(): BelongsTo;
public function children(): HasMany;
public function folder(): BelongsTo;
public function chunks(): HasMany;                  // ordered by chunk_index
public function chunk(): HasOne;                    // chunk_index = 0
public function slots(): BelongsToMany;
public function contents(): HasMany;                // @deprecated alias of chunks()
public function content(): HasOne;                  // @deprecated alias of chunk()
public function effectiveOwner(): ?Model;           // explicit owner, else creator

// Variant navigation (dot notation)
public function variant(string $path): self;        // falls back to nearest ancestor, never null
public function variantOrFail(string $path): self;  // throws RuntimeException if a link is missing
public function preview(): ?self;                   // the variant the type's `preview` names, or null
public function createVariant(string $variantName, array $overrides): self;

// Storage key helpers (path stores the full object key)
public function getKeyAttribute(): string;          // $file->key
public function siblingKey(string $newName): string;
public function variantKey(string $newName): string;

// URLs and rendering
public function url(?string $forceType = null, ?\DateTimeInterface $expires = null): ?string; // direct link
public function placeholderFor(string $type): string;
public function getLinkAttribute(): ?string;        // $file->link, alias of url()
public function getPreviewLinkAttribute(): string;  // $file->preview_link: preview()'s image link, or placeholder
public function streamUrl(): string;                // through PHP, inline; session + canView, never expires
public function downloadUrl(): string;              // through PHP, attachment, counted
public function toComponentArray(): array;          // id, slug, name, size, type, url, preview, processing_status: what the JS components show

// Folder
public function moveToFolder(?Folder $folder): void;

// State helpers
public function publish(): self;
public function unpublish(): self;
public function makeDefault(): self;

// Type and state predicates
public function isVariant(): bool;
public function isTopLevel(): bool;
public function isSensitive(): bool;
public function createdByUser(): bool;
public function createdByAgent(): bool;
public function createdAutomatically(): bool;
public function isMultiTenant(): bool;
public function isImage(): bool;                    // type image, whatever its mode
public function isVideo(): bool;
public function isAudio(): bool;
public function isDocument(): bool;
public function isPdf(): bool;

// Extracted text and chunks (sidecar artifacts on the disk)
public function extractedContent(): ?ExtractedContent;
public function extractedText(): ?string;
public function chunkText(int $chunkIndex): ?string;
public function chunksJsonl(): array;
public function hasEmbeddings(): bool;

// Authorization (delegates to PolicyRegistry)
public function canView(?Model $user): bool;
public function canEdit(?Model $user): bool;
public function canDelete(?Model $user): bool;
```

Query scopes:

```php
File::topLevel();                  // whereNull('parent_id')
File::withDescendants($depth = 2); // eager-load children to depth
File::withVariants($depth = 3);    // eager-load the variant tree
File::forTenant($tenant);
File::ordered();                   // position, then id
File::published();
File::unpublished();
File::default();
```

Scopes, and the state helpers that return `self`, chain like any Eloquent call:

```php
File::published()->ordered()->forTenant($org)->get();
$file->makeDefault()->publish();
```

### `StorageManager` service

`EduLazaro\Laracrate\Services\StorageManager`. The package facade over `Storage::disk()`. Resolve it with `app(StorageManager::class)`. See the Upload modes and Multi-tenancy, buckets and usage sections for context.

```php
public function urlFor(File $file, ?\DateTimeInterface $expires = null): ?string; // see File::url()
public function diskFor(File $file): \Illuminate\Contracts\Filesystem\Filesystem;
public function resolveDisk(string $disk): \Illuminate\Contracts\Filesystem\Filesystem;   // honors 'tb:{id}'
public function configFor(string $disk): array;
public function readBinary(File $file): string;
public function writeBinary(string $disk, string $key, string $content, ?string $mime = null): bool;
public function deleteFromBackend(string $disk, string $key): bool;
public function moveServerSide(string $disk, string $fromKey, string $toKey): bool;       // S3 copyObject, no PHP round-trip
public function batchDelete(string $disk, array $keys): int;
public function presignedUpload(string $disk, string $key, string $mime, ?int $maxSize = null, int $minutes = 15): array;
public function withLocalCopy(File $file, callable $fn): mixed;                            // temp file for ffmpeg/Imagick
public function getCollectionConfig(string $collection, array $modelOverride = [], ?string $morphAlias = null): array;
public function getTypeConfig(string $collection, string $type, ?string $morphAlias = null): array;
public function acceptsType(string $collection, string $type, ?string $morphAlias = null): bool;
public function s3ClientOf(string $disk): ?\Aws\S3\S3Client;
public function driverOf(string $disk): string;
```

### `UsageReporter` service

`EduLazaro\Laracrate\Services\UsageReporter`. Storage usage aggregation. Resolve it with `app(UsageReporter::class)`. Each method returns a `UsageStats` DTO. See the Multi-tenancy, buckets and usage section.

```php
public function forTenant(Model $tenant, bool $excludeTrashed = false): UsageStats;
public function forCreator(Model $creator, bool $excludeTrashed = false): UsageStats;
public function forCollection(string $collection, ?Model $tenant = null, bool $excludeTrashed = false): UsageStats;
public function global(bool $excludeTrashed = false): UsageStats;
```

### Contracts

Bind your own implementation in a service provider to swap any of these. The defaults are documented in their feature sections.

```php
// EduLazaro\Laracrate\Contracts\ChunkStore
public function store(File $file, array $chunks): int;
public function getByFile(File $file): \Illuminate\Support\Collection;
public function search(string $query, array $filters = [], array $options = []): \Illuminate\Support\Collection;
public function deleteByFile(File $file): void;
public function driverName(): string;
```

```php
// EduLazaro\Laracrate\Contracts\EmbeddingProvider
public function embed(array $texts): array;          // one vector per text, same order
public function dimensions(): int;
public function model(): string;                     // e.g. "text-embedding-3-small"
public function name(): string;                      // e.g. "openai"
```

```php
// EduLazaro\Laracrate\Contracts\TextExtractor
public function supports(File $file): bool;
public function extract(File $file): \EduLazaro\Laracrate\Support\ExtractedContent;
```

```php
// EduLazaro\Laracrate\Contracts\FileActionInterface
public function handle(File $file): void;
public function priority(): int;
// optional: public function supports(File $file): bool;  // assumed true if absent
```

## Testing

Laracrate ships its own test suite (Testbench plus PHPUnit). It runs against a real **MySQL** database, because the package migrations use MySQL-specific features (FULLTEXT indexes, `information_schema` lookups, `ALTER ... MODIFY`) that SQLite cannot run. Object storage is never touched: the AWS S3 client is mocked and the disks are backed by `Storage::fake()`, so the suite makes no external calls and needs no ffmpeg, imagick, or live S3.

You need PHP with the `pdo_mysql` extension and a MySQL (or MariaDB) server. Use a **dedicated** test database: the suite runs `migrate:fresh`, which drops every table in it.

### 1. Start a throwaway MySQL

The simplest option is a disposable container:

```bash
docker run -d --name laracrate-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=laracrate_test \
  -p 3307:3306 mysql:8
```

### 2. Run the suite

```bash
composer install
vendor/bin/phpunit
```

The test connection defaults to that container (`127.0.0.1:3307`, database `laracrate_test`, user `root`). Point it elsewhere with environment variables:

```bash
LARACRATE_TEST_DB_HOST=127.0.0.1 \
LARACRATE_TEST_DB_PORT=3307 \
LARACRATE_TEST_DB_DATABASE=laracrate_test \
LARACRATE_TEST_DB_USERNAME=root \
LARACRATE_TEST_DB_PASSWORD=root \
vendor/bin/phpunit
```

The schema is built once per run with `migrate:fresh` and each test is wrapped in a transaction, so a full run finishes in a few seconds. The PHPUnit config defines two suites, `Unit` (`tests/Unit`) and `Feature` (`tests/Feature`), keeps the AWS SDK offline, and sets `APP_ENV=testing`. Any feature you contribute should bring its own test under `tests/`.

When you are done, remove the container with `docker rm -f laracrate-mysql`.

### The React and Svelte components

Their tests run on Vitest with jsdom, against a stand-in for the page's upload queue, so they need no server either:

```bash
npm install
npm test
```

## Roadmap

The items below are planned or under consideration. They are distilled from the package backlog and may change. Nothing here is a commitment to ship.

- **Crop step for the deferred uploader**: crop or rotate the staged image before `submit()` stores it.
- **Library uploader Livewire component** (`laracrate-library-uploader`): multi-file collections with a default toggle, drag-and-drop reorder, and per-row delete, the pattern apps build by hand today.
- **`tile` and `banner` layouts**: a square compact layout with hover overlay controls, and a wide cover layout (16:9 or 21:9) for heroes and OG images, both across all uploader themes.
- **Sync processing in dev**: an option to run the processing pipeline inline when there is no queue worker (or when `queue.default` is `sync`), so a freshly uploaded file is not stuck showing its unoptimized original.
- **`php artisan laracrate:doctor`**: an installation diagnostic that checks disks, backend connectivity, ffmpeg/ffprobe, Imagick/GD, the queue worker, and inconsistent rows.
- **`php artisan laracrate:purge-orphan-tmp`**: cleanup of orphaned temporary uploads left behind when an upload fails midway.
- **Docs site**: a dedicated documentation site once the README outgrows a single file.

## Sponsors

Laracrate is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" width="24" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" width="24" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

MIT
