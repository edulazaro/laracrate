![Laracrate](art/banner.png)

# Laracrate

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
17. [Video and PDF previews](#video-and-pdf-previews)
18. [Text extraction, embeddings and search (RAG)](#text-extraction-embeddings-and-search-rag)
19. [Access control and authorization](#access-control-and-authorization)
20. [Sensitive content and encryption](#sensitive-content-and-encryption)
21. [Multi-tenancy, buckets and usage](#multi-tenancy-buckets-and-usage)
22. [Livewire components and themes](#livewire-components-and-themes)
23. [Localization](#localization)
24. [Artisan commands](#artisan-commands)
25. [Events](#events)
26. [API reference](#api-reference)
27. [Testing](#testing)
28. [Roadmap](#roadmap)
29. [Sponsors](#sponsors)
30. [Author](#author)
31. [License](#license)

## What is Laracrate?

**Laracrate** is a polymorphic file storage layer for Laravel built for object storage (Cloudflare R2, AWS S3) and local disks. It lets any Eloquent model own collections of files, uploads the binary straight from the browser to your bucket (the file never passes through PHP), then runs an asynchronous pipeline that derives everything else: image variants, video and PDF previews, extracted text, vector embeddings, and a searchable chunk store for retrieval-augmented generation. The backend is fully decoupled from any frontend, and an optional set of Livewire upload components ships in the box if you want them.

What you get:

- **Direct upload to R2 and S3.** Presigned single-part PUT for normal files, and multipart upload for large files (parallel parts with per-part retries and ETag assembly).
- **Polymorphic ownership.** Three independent morphs per file (`fileable`, `creator`/`owner`, `tenant`) so a file knows what it belongs to, who created it, and which tenant scopes it.
- **Asynchronous processing pipeline.** Creating a file dispatches a queued job that runs ordered steps by file type. Your user's upload stays instant.
- **Image variants and per-variant watermarks.** Automatic resized derivatives with optional watermarking configured per variant.
- **Video and PDF previews.** Transcoding, dimension extraction, and rasterized preview frames.
- **Three access modes per collection.** `public` (direct CDN), `signed` (temporary signed URL), and `stream` (controller with audit and viewer binding).
- **Text extraction, embeddings and hybrid search.** Opt-in extraction, chunking, embedding generation, and a pluggable `ChunkStore` (MySQL or Meilisearch) for keyword plus semantic search.
- **Folders and slots.** A folder tree for organizing files, and file slots for "fill in this required document" workflows with quotas.
- **Multi-tenant buckets and usage accounting.** Optional dedicated bucket per tenant and storage usage reporting for quotas.
- **Optional Livewire UI.** Six upload components across eleven visual themes, all publishable. None of it is required to use the package.

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
    video  : extract dimensions -> transcode -> extract preview
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
5. **Access is decided per collection.** Each collection declares one of three access modes (`public`, `signed`, `stream`), so the right URL strategy and audit behavior come from configuration, not scattered checks.
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

You only need the config tag to get started. The package loads its migrations, views, and translations directly from the package directory, so publishing those is optional and only needed when you want to customize them.

| Tag | What it copies | Destination |
| --- | --- | --- |
| `laracrate-config` | The config file | `config/laracrate.php` |
| `laracrate-migrations` | The migration files | `database/migrations` |
| `laracrate-views` | The Blade views (uploader themes, etc.) | `resources/views/vendor/laracrate` |
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

**Collection.** The business grouping a file belongs to, stored in the `collection` column (for example `avatar`, `documents`, `lawsuit-document`). A collection is declared in `config('laracrate.collections.*')` and decides the disk, the access mode, the accepted types, the variants to generate, and whether text extraction or embeddings run. When you attach a file to one of your models you always name its collection (see the Working with files from your models section).

**Type.** A coarse media class stored in the `type` column and cast to the `EduLazaro\Laracrate\Enums\FileType` enum: `IMAGE`, `VIDEO`, `AUDIO`, or `DOCUMENT`. Laracrate derives it from the MIME with `FileType::fromMime()` (anything that is not `image/*`, `video/*`, or `audio/*` becomes `DOCUMENT`). The pipeline picks which steps run per type.

**File vs variant.** A top-level **file** has `parent_id = null`. A **variant** is a derived file (a thumbnail, an optimized copy, a video or PDF preview) linked to its parent by `parent_id` and named by the `variant` column. Variants are real `File` rows, created with `$file->createVariant($name, $overrides)`, and they inherit the parent scope (fileable, creator, tenant, disk, collection, access). You navigate them with dot notation: `$file->variant('preview.thumbnail')` walks the tree and falls back to the nearest existing ancestor instead of returning null, while `$file->variantOrFail('preview.thumbnail')` throws if any link is missing. Helpers: `$file->isTopLevel()`, `$file->isVariant()`. See the Images, variants and watermarks section.

**The four polymorphic morphs.** A file carries four independent `morphTo` relations. They are orthogonal, not a hierarchy, and you set only the ones your app needs.

| Morph | Columns | Meaning |
| --- | --- | --- |
| `fileable` | `fileable_type`, `fileable_id` | What the file belongs to (a `Property`, `User`, `Service`). |
| `creator` | `creator_type`, `creator_id` | Who created it. Null for system-generated files. |
| `owner` | `owner_type`, `owner_id` | The semantic owner when it differs from the creator (uploaded on behalf of someone). Null when it matches the creator. |
| `tenant` | `tenant_type`, `tenant_id` | The multi-tenant scope. Leave null in single-tenant apps. |

Use `$file->effectiveOwner()` to get the explicit owner or fall back to the creator. See the Multi-tenancy, buckets and usage section.

**path is the full object key.** The `path` column stores the complete object key on the disk (directories, filename, and extension together), for example `user/1/avatar/01J...webp`. It is not just the directory, and you never concatenate it with `name` to build a key. Always read the key through the accessor `$file->key`, which trims a stray leading slash. Derived keys come from `$file->siblingKey($name)` (same directory) and `$file->variantKey($name)` (sibling `variants/` subdirectory). The `name` column is just `basename($path)`.

**Access mode.** The `access` column, cast to `EduLazaro\Laracrate\Enums\FileAccess`, decides how a file is served:

| Case | How it is served |
| --- | --- |
| `PUBLIC` | Direct CDN URL via `Storage::url()`. No signature, no audit. |
| `SIGNED` | Temporary signed URL via `Storage::temporaryUrl()`, cached server-side. |
| `STREAM` | Served through the package controller: per-request authorization, audit, optional viewer binding, encryption, and watermark. |

Each collection declares its access mode. See the Upload modes and Access control and authorization sections.

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
| `variant` | string(50), nullable | Variant name (`thumbnail`, `preview`, `small`, ...). Unique together with `parent_id`. |
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
| `category` | string, nullable | Free-form app category. Indexed. |

**Access flags**

| Column | Type | Notes |
| --- | --- | --- |
| `access` | enum | `public`, `signed`, `stream`. Defaults to `signed`. Cast to `FileAccess`. Indexed. |
| `visibility` | string, nullable | Free-form visibility label. The `FileVisibility` enum (`owner`, `group`, `tenant`, `world`) is available if you want to use its values. Indexed. |
| `sensitive` | boolean | Defaults to false. Indexed. |
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
| `access` | string | `public` (CDN-direct), `signed` (temporary signed URL), or `stream` (controller with audit and viewer bind). See the Upload modes and Access control sections. |
| `single` | bool | `true` keeps one file per owner. Replacing the file removes the previous one. |
| `sensitive` | bool | Binds access to the authenticated viewer and re-validates on each request. See the Sensitive content section. |
| `encrypt` | bool | Encrypts the binary before it is stored on the backend. See the Sensitive content and encryption section. |
| `ttl_hours` | int | Files in the collection expire after this many hours. The `laracrate:purge-expired` command (run hourly) deletes them, cascading to variants and the backend binary. Useful for `temp_uploads`, expiring exports, or unpromoted drafts. |
| `quota_bytes` | int | Storage limit your app can check with `UsageReporter` before accepting more uploads. The package does not enforce quotas itself. See the Multi-tenancy, buckets and usage section. |
| `track_usage` | bool | Maintain live per-owner usage counters (file count and total bytes) for this collection in the `laracrate_folderables` table, updated by the file observer on create and delete, and rebuildable with `laracrate:recompute-usage`. See the Multi-tenancy, buckets and usage section. |
| `component` | string | Blade component used for default rendering. |
| `placeholder` | string | Fallback asset when the file does not exist (highest priority in placeholder resolution, below). |
| `types` | array | Per-type processing config (`image`, `document`, `audio`, `video`), keyed by type. Each type block can carry `accepted_mime_types`, `accepted_extensions`, `max_file_size`, `variants`, `preview`, and so on, overriding the global `defaults`. |
| `variants` | array | Image variant definitions: `['name' => ['width' => ..., 'height' => ..., 'fit' => bool, 'watermark' => bool]]`. See the Images, variants and watermarks section. |
| `preview` | array | Rasterized preview config for documents and videos. Documents accept `page`, `width`, `engine`, and nested `variants`. Videos accept `frame_at` and nested `variants`. See the Video and PDF previews section. |
| `extract` / `extract_text` | bool or array | Whether to extract text from the file (`extract_text` is a legacy alias for `extract`). See the per-type extraction note below and the Text extraction section. |
| `embed` | bool or array | Whether to generate embeddings. See the Text extraction, embeddings and search section. |
| `actions` | array | Custom actions to attach to the collection. |
| `models` | array | Per-model scoping (covered next). |

#### The `models` block: per-model scoping

By default any model using `HasFiles` can write to a collection with the same config. Declaring `models` restricts the collection to specific owner types and merges a per-model override on top of the base config. Resolution lives in `EduLazaro\Laracrate\Support\CollectionConfig::resolve()`.

```php
'documents' => [
    'disk'   => 'documents',
    'access' => 'signed',
    'models' => [
        // Cases get the stricter, viewer-bound stream access.
        'case'         => ['access' => 'stream', 'sensitive' => true],
        // Organizations keep signed access but skip PDF previews.
        'organization' => ['types' => ['document' => ['preview' => false]]],
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

The `extract` and `embed` keys accept either a boolean or an array, resolved by `EduLazaro\Laracrate\Support\ExtractionResolver`:

```php
'extract' => true,                 // all types in the collection
'extract' => ['document', 'image'] // only these file types
'extract' => ['video.visual']      // an opt-in extra, matched by prefix
```

When the value is an array, it matches the file's type by exact value or by the `type.` prefix (so `video` matches `video.visual` and vice versa). The legacy boolean key `extract_text` is still honored for backward compatibility. Apps can register a per-file override with `ExtractionResolver::setOverrideResolver(callable)`, where the callable receives the `File` and returns an override array (or null). See the Text extraction section.

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

### URL strategy

TTLs and cache windows for the URL accessors. See the Displaying files and Access control sections for how each mode uses them.

```php
'urls' => [
    'signed_ttl'             => 5,
    'signed_cache_ttl'       => 4,
    'sensitive_redirect_ttl' => 10,
    'route_signed_ttl'       => 15,
    'bind_to_user'           => true,
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `signed_ttl` | `5` | Minutes a backend signed URL stays valid (for `access: signed`). |
| `signed_cache_ttl` | `4` | Minutes the server-side cache of a signed URL is kept. |
| `sensitive_redirect_ttl` | `10` | Seconds for the ultra-short signed URL issued after validation in the stream controller. |
| `route_signed_ttl` | `15` | Minutes the route HMAC for `/laracrate/files/{slug}/stream` stays valid. |
| `bind_to_user` | `true` | Binds stream URLs to the user identity, re-validating on demand. |

### Policies

The package uses `PolicyRegistry` as the canonical place to declare authorization per `fileable_type`. When `register_gate` is `true` (default), the service provider also binds `FilePolicy` to the Laravel Gate so you can use the native ergonomics.

```php
'policies' => [
    'register_gate' => true,
],
```

With the bridge on, the Gate abilities `view`, `update`, and `delete` map to the registry methods `canView`, `canEdit`, and `canDelete`, so `@can('view', $file)`, `$user->can('update', $file)`, and `Route::middleware('can:view,file')` all work. Set `register_gate => false` if your app already registers its own `FilePolicy`. See the Access control and authorization section.

### Streaming

Routing and audit options for the controller that serves `access: stream` files.

```php
'stream' => [
    'route_prefix'        => 'laracrate/files',
    'route_name_prefix'   => 'laracrate.files',
    'middleware'          => ['web', 'auth'],
    'increment_downloads' => true,
    'log_access'          => true,
],
```

The `laracrate/files` prefix avoids collisions with an existing `FileController` under `/files/...`, which is common. `increment_downloads` bumps the file download counter, and `log_access` audits each download. See the HTTP endpoints section.

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
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `route_prefix` | `laracrate/uploads` | URL prefix for the presign and cancel endpoints. |
| `middleware` | `['web', 'auth']` | Middleware for the upload route group. Authorization is your app's responsibility. |
| `allowed_disks` | `[]` | Allowlist of disks a client may upload to directly, enforced in the presign and multipart init endpoints. Empty means no restriction. |

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

`driver` is `imagick` (recommended) or `gd`. When `optimize_originals` is `true`, the original is re-encoded to webp within `max_width`/`max_height` at `quality`. See the Images, variants and watermarks section.

### PDF preview engine

Selects the engine that rasterizes a PDF page into a PNG for the `preview` variant.

```php
'pdf_preview_engine' => 'auto',
```

| Value | Requirements | Notes |
| --- | --- | --- |
| `pdftoppm` | poppler-utils (`apt install poppler-utils`) | Does not need Ghostscript or any change to ImageMagick `policy.xml`. |
| `imagick` | PHP imagick extension, Ghostscript (`gs`), and the PDF coder enabled in ImageMagick `policy.xml` | Heavier setup. |
| `auto` | tries `pdftoppm`, falls back to `imagick` | Default. |

You can override the engine per collection inside the `preview` block:

```php
'preview' => ['page' => 1, 'width' => 600, 'engine' => 'pdftoppm'],
```

See the Video and PDF previews section.

### Video

Defaults for ffmpeg-based transcoding when a collection does not override them.

```php
'video' => [
    'max_width'    => 1920,
    'max_height'   => 1920,
    'bitrate_kbps' => 2500,
],
```

See the Video and PDF previews section.

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

`provider` is `anthropic` (default, model `claude-haiku-4-5`) or `openai` (model `gpt-4o-mini`). See the Text extraction section.

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

### UI

Default theme for the `<livewire:laracrate-uploader>` component when no `theme=` prop is passed.

```php
'ui' => [
    'default_theme' => env('LARACRATE_THEME', 'default'),
],
```

The built-in themes are `default`, `brutalist`, `material`, `ios`, `glassmorphism`, `neon`, `minimal`, `neumorphism`, `chatgpt`, `claude`, and `studio`. For a custom theme, publish the views with `vendor:publish --tag=laracrate-views` and add your blade under `resources/views/vendor/laracrate/uploader/themes/`. See the Livewire components and themes section.

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

Once a file exists, you render it from a URL. Every `File` resolves its own URL based on the collection access mode (public CDN, signed URL, or streamed), so you do not build paths by hand. The same call works for every access mode (see the Access control section for what each mode does).

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
| `$file->preview_link` | `$file->variant('preview.thumbnail')->url('image')`, falling back to the image placeholder |

```blade
<img src="{{ $file->link }}">
<img src="{{ $file->preview_link }}"> {{-- thumbnail for video, PDF, audio, image --}}
```

`preview_link` never throws: if the disk is unreachable or the variant is missing, it returns the configured placeholder so one broken file cannot break the page.

### Navigating variants

A file can have derived variants (a `thumbnail`, a `small` image, a video `preview`, and so on, created by the pipeline). Reach them with **`variant()`** using dot notation:

```php
$file->variant('thumbnail')->url();
$file->variant('preview.small')->url('image');
```

`variant()` never returns `null`. If any link in the chain is missing it falls back to the closest existing ancestor, so `$file->variant('preview.small')` returns the `preview` (or the original) when `small` was not generated yet. When that silent fallback would be a bug, use **`variantOrFail()`** instead, which throws `RuntimeException` if any segment is missing:

```php
$thumb = $file->variantOrFail('preview.thumbnail'); // throws if not generated
```

To avoid lazy-loading each variant on access, eager load the tree with the `withVariants()` scope on the `File` model:

```php
$files = File::withVariants()->get(); // loads file -> preview -> thumbnail|small|...
```

### `url()` and forced types

`url()` returns the real URL, or `null` when no valid backend resolves. Pass a type to force a fallback: if the file is not that type, or its URL cannot be built, you get the configured placeholder for that type instead of `null`.

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
<img src="{{ $user->fileLink('cover', 'preview.thumbnail') }}">
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

`presignAndUpload(file, opts)` options: `disk` (required), `collection`, `fileable` (`{ type, id }`), `maxSizeKb`, `presignUrl` (override the presign route), and `onProgress` (a `0..1` callback). It returns `{ key, disk, original_name, mime_type, size }`.

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

`FileUpload::fromArray()` accepts `disk`, `key`, `original_name` (or `originalName`), `mime_type` (or `mimeType`), `size`, and optional `width`, `height`, `duration`, `digest`.

**Where the file lands.** The presign endpoint chooses the key two ways. If you pass `fileable_type`, `fileable_id`, and `collection`, the file is uploaded straight to its **canonical key** (`{fileable_type}/{fileable_id}/{collection}/{ulid}_{name}`), so no move is needed afterward. If you do not (the common case for a creation form with no model yet), it lands under `temp/`. When you then call `addFile()` with that `temp/` key, Laracrate moves the object to its canonical key. On S3-compatible disks the move is a server-side `copyObject` plus `deleteObject` (`StorageManager::moveServerSide()`): the bytes never come back through PHP.

If the user cancels before confirming, delete the orphaned `temp/` object with `deleteTemp(disk, key)` from the JS helper. It only deletes keys that start with `temp/`.

### Multipart (large files)

For files past the threshold, S3/R2 multipart splits the upload into parts that the browser `PUT`s in parallel, each returning an `ETag`. Laracrate exposes the orchestration endpoints; there is no bundled multipart JS helper, so you drive the four endpoints yourself based on `file.size`:

1. **Init.** `POST /laracrate/multipart/init` with `disk`, `expected_size`, and optionally `mime`, `file_name`, `part_size`, `fileable_type`, `fileable_id`, `collection`. The response gives you `upload_id`, `id`, `key`, `part_size`, `total_parts`, `expires_at`, and `parts` (an array of `{ part_number, url, method }`).
2. **Upload parts.** `PUT` each part's bytes to its `url`. Capture the `ETag` response header for every part.
3. **Reissue (optional).** If a part URL expires before you use it, `POST /laracrate/multipart/{id}/parts` with `part_numbers` to get fresh URLs.
4. **Complete.** `POST /laracrate/multipart/{id}/complete` with `parts` as `[{ part_number, etag }, ...]`. S3 assembles the object at `key`. To cancel instead, `DELETE /laracrate/multipart/{id}`.

After `complete`, the binary exists at `key` but no `File` row has been created yet. Persist it the same way as a presigned upload, by calling `addFile(FileUpload::fromArray([...]), $collection)` with the final `disk` and `key`. Multipart sessions are tracked in `laracrate_multipart_uploads`; only the creator can complete or abort a session, and stale ones are reaped by `laracrate:abort-stale-multipart` (see the artisan commands section).

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
| POST | `laracrate/multipart/init` | `laracrate.multipart.init` | Start a multipart session, returns `upload_id`, `total_parts`, and part URLs |
| POST | `laracrate/multipart/{multipart}/parts` | `laracrate.multipart.parts` | Reissue presigned URLs for specific parts |
| POST | `laracrate/multipart/{multipart}/complete` | `laracrate.multipart.complete` | Assemble the final object from uploaded part ETags |
| DELETE | `laracrate/multipart/{multipart}` | `laracrate.multipart.abort` | Abort and clean up a multipart session |
| GET | `laracrate/files/{file:slug}/stream` | `laracrate.files.stream` | Stream a protected file inline (audit + viewer bind) |
| GET | `laracrate/files/{file:slug}/preview` | `laracrate.files.preview` | Stream the file's preview variant |
| GET | `laracrate/files/{file:slug}/download` | `laracrate.files.download` | Download a protected file as an attachment |
| GET | `laracrate/files/{file:slug}/status` | `laracrate.files.status` | Processing status for one file (JSON) |
| POST | `laracrate/files/status` | `laracrate.files.status.batch` | Processing status for many files in one request |
| POST | `_laracrate/local/upload` | `laracrate.local.upload` | Receive bytes for the local-driver presigned fallback (signed) |
| GET | `_laracrate/local/serve/{file:slug}` | `laracrate.local.serve` | Serve a local-disk file after verifying the URL signature (signed) |

**Prefixes and middleware.** Each group reads its own config, so prefixes and route names shown above are the defaults:

| Group | Prefix config | Middleware config | Name prefix |
| --- | --- | --- | --- |
| Presigned uploads | `laracrate.uploads.route_prefix` (`laracrate/uploads`) | `laracrate.uploads.middleware` (`['web', 'auth']`) | `laracrate.uploads.` |
| Multipart | `laracrate.multipart.route_prefix` (`laracrate/multipart`) | `laracrate.multipart.middleware` (falls back to the uploads middleware when null) | `laracrate.multipart.` |
| Stream / preview / download | `laracrate.stream.route_prefix` (`laracrate/files`) | `laracrate.stream.middleware` (`['web', 'auth']`) | `laracrate.stream.route_name_prefix` (`laracrate.files`) |
| Status | `laracrate.status.route_prefix` (`laracrate/files`) | `laracrate.status.middleware` (`['web', 'auth']`) | `laracrate.files.` |
| Local driver | fixed `_laracrate/local` | `signed` | `laracrate.local.` |

A few details worth knowing:

- **Authorization is yours.** The upload and multipart groups only apply the configured middleware. Restricting which disks a user may write to is enforced separately by `config('laracrate.uploads.allowed_disks')`, checked in the presign and init endpoints (empty means no restriction).
- **`cancel` only touches `temp/`.** The DELETE endpoint refuses any key that does not start with `temp/`, so it cannot be used to delete canonical objects. The `deleteTemp()` JS helper builds the `{encodedKey}` segment for you.
- **Multipart ownership.** `parts`, `complete`, and `abort` verify the caller is the session's creator (when a creator was recorded at init), returning 403 otherwise.
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
| 10 | `Image\ExtractImageDimensionsStep` | `type` is `image` |
| 10 | `Video\ExtractVideoDimensionsStep` | `type` is `video` |
| 20 | `Image\OptimizeImageStep` | `type` is `image` and optimization is enabled (collection `optimize`, type `optimize`, or `laracrate.image.optimize_originals`) |
| 25 | `Video\TranscodeVideoStep` | `type` is `video` and the video type config sets `transcode` |
| 40 | `Image\GenerateImageVariantsStep` | `type` is `image` and the image type config declares `variants` |
| 45 | `Video\ExtractVideoPreviewStep` | `type` is `video` and the video type config sets `preview` |
| 45 | `Document\ExtractPdfPreviewStep` | `type` is `document`, `mime_type` is `application/pdf`, and the document type config sets `preview` |
| 60 | `Text\ExtractTextStep` | embeddings enabled, the collection should extract or embed, and a text extractor exists for the file |
| 70 | `Text\ChunkTextStep` | embeddings enabled, the collection should embed, and the `{key}.json` sidecar exists |
| 80 | `Text\GenerateEmbeddingStep` | embeddings enabled, the collection should embed, and the `{key}.chunks.jsonl` sidecar exists |
| 90 | `Text\PersistChunksStep` | the `{key}.chunks.jsonl` sidecar exists |

The text steps write two **sidecar artifacts** next to the binary on the same disk: `{key}.json` (extracted full text plus per-page content) and `{key}.chunks.jsonl` (chunks and embeddings). Each later step gates on the artifact the previous one produced, so the chain stops cleanly if extraction yields nothing. These sidecars are purged when the file is force deleted. See the Images, variants and watermarks, Video and PDF previews, and Text extraction, embeddings and search (RAG) sections for what each step actually does and the config keys it reads.

### Priority bands

`priority()` returns an ascending integer. Lower numbers run first. Follow this convention so your steps slot in at the right point:

| Band | Purpose |
|------|---------|
| 0-19 | Metadata (dimensions, duration) |
| 20-39 | Transforming the original (optimize, transcode, encrypt) |
| 40-59 | Derivatives (variants, previews, thumbnails) |
| 60-79 | Semantic extraction (text, OCR, transcription) |
| 80-99 | AI (chunking, embeddings, classification) |
| 100+ | App-specific post-processing |

### Failure and retry behavior

The pipeline is **fail-fast**. If any step throws, `ProcessFileAction` marks the file `failed`, stores the exception message in `processing_error`, fires `FileProcessingFailed`, and rethrows. Later steps do not run.

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

You declare variants per file type inside a collection's config. The orchestrator (`GenerateImageVariantsAction`) iterates `types.image.variants` for the collection and dispatches `GenerateImageVariantAction` once per definition. A failure in one variant is logged and skipped, the rest still generate.

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
        'disk'   => 'documents',
        'access' => 'stream',
        'types'  => [
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

## Video and PDF previews

For non-image files Laracrate can generate an image **preview** so you have something to render in a gallery or list. Both video and PDF previews work the same way: the package produces a child `File` with `variant = 'preview'` and `type = image`, then optionally generates its own image variants (thumbnail, medium, and so on) from that preview. Both run in the pipeline and depend on external binaries, so they are opt-in per collection.

### Video

Video handling has two independent steps. Dimensions and duration are read with `ffprobe` (`ExtractVideoDimensionsAction`, stored on `width`, `height`, `duration`). Preview frame extraction (`ExtractVideoPreviewAction`) pulls a single frame with `ffmpeg` at the configured timestamp and writes it as a JPEG preview. Both require `ffmpeg` and `ffprobe` on the server path.

```php
// config/laracrate.php
'collections' => [
    'gallery' => [
        'disk'   => 'media',
        'access' => 'public',
        'types'  => [
            'video' => [
                'preview' => [
                    'frame_at' => '00:00:01', // timestamp of the frame to grab
                    'variants' => [
                        'thumbnail' => ['width' => 300, 'height' => 300, 'fit' => true],
                        'medium'    => ['width' => 800, 'height' => 800],
                    ],
                ],
            ],
        ],
    ],
],
```

`frame_at` defaults to `00:00:01` when not set. The extracted frame becomes a `preview` child of type image, and the `variants` you list under `preview` are generated from it (each one is in turn a child of the preview), so a video ends up with a preview frame plus its own thumbnail and medium.

**Transcoding** is separate and costly, so enable it only on collections that need it. `TranscodeVideoAction` re-encodes the original to H.264 / AAC MP4 with `ffmpeg`, scaling it down to fit a bounding box, and replaces the original binary in place (then re-reads dimensions). It runs when the collection declares `transcode => true`.

```php
// config/laracrate.php
'video' => [
    'max_width'    => 1920,
    'max_height'   => 1920,
    'bitrate_kbps' => 2500,
],
```

### PDF

`ExtractPdfPreviewAction` rasterizes one page of a PDF (page 1 by default) to a PNG and stores it as a `preview` child of type image. As with video, any `variants` you declare under `preview` are generated from that PNG (a thumbnail and a medium of page 1).

```php
// config/laracrate.php
'collections' => [
    'documents' => [
        'disk'   => 'documents',
        'access' => 'signed',
        'types'  => [
            'document' => [
                'preview' => [
                    'page'  => 1,
                    'width' => 2000,
                    'variants' => [
                        'thumbnail' => ['width' => 300],
                        'medium'    => ['width' => 800],
                    ],
                ],
            ],
        ],
    ],
],
```

The rasterization engine is selectable with `pdf_preview_engine` (or a per-collection `engine` key inside the `preview` block):

| Engine | Requires | Notes |
|--------|----------|-------|
| `pdftoppm` | poppler-utils (`pdftoppm` binary) | No Ghostscript and no ImageMagick `policy.xml` changes. Downscaling falls back to GD. |
| `imagick` | PHP `imagick` extension, Ghostscript (`gs`), and PDF enabled in ImageMagick's `policy.xml` | Reads the PDF through Ghostscript. |
| `auto` | either of the above | Tries `pdftoppm` first, falls back to `imagick` if it is not available. |

```php
// config/laracrate.php
'pdf_preview_engine' => 'auto',
```

When the engine is forced to `pdftoppm` and the binary is missing or fails, the action logs and returns null rather than falling back. Set the engine per collection when one disk or document type needs a specific renderer:

```php
'preview' => ['page' => 1, 'width' => 600, 'engine' => 'pdftoppm'],
```

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
        'access'       => 'signed',
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

`OcrImageTextExtractor` and `VideoTranscribeExtractor` emit two segments with distinct `context` values, `text` (verbatim OCR or transcript) and `description` (a visual summary), so the chunker produces a separate embedding for each. Video visual frame description is opt-in: add the pseudo-type `'video.visual'` to the collection's `extract` array to enable it (`LARACRATE_VIDEO_FRAME_INTERVAL` controls the seconds between frames, default 30).

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

app(TextExtractorRegistry::class)->add(new \App\Extractors\MyOcrExtractor());
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

Every collection declares an **access mode** that controls how its files reach the browser. The mode lives in the collection config (see the Configuration section) and is stored on each file as the `access` column, backed by the `EduLazaro\Laracrate\Enums\FileAccess` enum. When you call `$file->url()`, Laracrate resolves the right strategy for you, you never build storage URLs by hand.

The three modes are:

| Mode | Enum case | How `url()` resolves it | Audit | Use for |
| --- | --- | --- | --- | --- |
| `public` | `FileAccess::PUBLIC` | `Storage::disk()->url()`, direct CDN link, no signature | No | Avatars, logos, public marketing assets |
| `signed` | `FileAccess::SIGNED` | `Storage::disk()->temporaryUrl()`, short-lived presigned GET, cached server-side | No | Private-ish files where a temporary direct link is fine |
| `stream` | `FileAccess::STREAM` | Signed Laravel route to the package stream controller, the binary is proxied through your app | Yes | Sensitive content, anything that needs per-request checks |

`$file->url()` delegates to `StorageManager::urlFor()`, which switches on the access value: `public` calls `GeneratePublicUrlAction`, `stream` calls `GenerateSensitiveStreamUrlAction`, and everything else falls back to `GenerateSignedUrlAction`. Signed URLs are cached server-side (config `laracrate.urls.signed_cache_ttl`, default 4 minutes) so a page rendering many files does not issue one presign per file. If a disk is misconfigured or unreachable, the signed action logs a warning and returns `null` instead of throwing, so one broken file does not break the whole page.

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

For files that must never be served by a direct storage URL, set the collection's access mode to `stream` and mark it `sensitive`. Streamed files are proxied through `EduLazaro\Laracrate\Http\Controllers\StreamFileController`, which re-checks permissions on every request, audits the access, and (when the collection is encrypted) decrypts the binary in memory before sending it. The storage backend URL is never exposed to the client.

### The per-request stream flow

When `access` is `stream`, `$file->url()` returns a temporary signed route built by `GenerateSensitiveStreamUrlAction`. The controller exposes `stream`, `preview`, `download`, and `link` actions, each routed under the `laracrate.files.*` names (`laracrate.files.stream`, `laracrate.files.preview`, `laracrate.files.download`). Every request runs through `validateAccess()` before a single byte is sent:

1. **Signature check.** The route must carry a valid Laravel signature (`hasValidSignature()`), otherwise the controller aborts `403`. URLs are signed with a TTL from `laracrate.urls.route_signed_ttl` (default 15 minutes).
2. **Viewer bind (sensitive only).** If `$file->isSensitive()` and `laracrate.urls.bind_to_user` is on (default `true`), the request must be authenticated, and the `u` query parameter (the user id baked into the URL when it was generated) must match the current `Auth::id()`. A leaked URL pasted into another session aborts `403`. Generation only adds `u` when a user is logged in, see `GenerateSensitiveStreamUrlAction`.
3. **Policy check.** `$file->canView($request->user())` runs the same PolicyRegistry logic described in the Access control and authorization section. Failure aborts `403`.

Only after all three pass does the controller serve the file. The signed route in your HTML is what makes sensitive links safe to render: even if the page is cached, the link expires on its own and is bound to the viewer.

### Audit and download tracking

`sendFile()` audits before streaming. When the request is a `stream` or `download` (not `preview`) and `laracrate.stream.increment_downloads` is on (default `true`), the file's `downloads_count` is incremented and `last_downloaded_at` is stamped with a quiet save (no events fired). When `laracrate.stream.log_access` is on (default `true`), an info log records the file id, collection, user id, IP, and method. Streamed responses are sent with `Cache-Control: private, no-store, no-cache, must-revalidate` so sensitive bytes are never cached downstream.

### Encryption at rest

Set `encrypt => true` on a collection to encrypt the binary before it is written to the backend. `CreateFileAction` reads the flag, runs `EncryptFileAction` on the binary, and persists `is_encrypted = true` on the file. Encryption uses Laravel's app key:

- `EncryptFileAction` does `Crypt::encryptString(base64_encode($binary))`.
- `DecryptFileAction` reads the cipher from the backend and returns `base64_decode(Crypt::decryptString($cipher))`.

Encryption requires the binary to be present server-side. If a collection has `encrypt => true` but the upload skipped your server (a direct presigned upload, for example), `CreateFileAction` throws, because there is nothing for PHP to encrypt. Encrypted collections must route the bytes through your app on the way in.

On the way out, the stream controller transparently decrypts: in `sendFile()`, when `$file->is_encrypted` is true it calls `DecryptFileAction::create()->run(['file' => $file])`, otherwise it reads the raw object. Encryption pairs naturally with `access => stream`, since the binary has to pass through your app to be decrypted anyway.

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

The components register only when Livewire is installed (`registerLivewireComponents()` no-ops otherwise). Each one resolves a Blade theme at render time, so the look is fully swappable.

Minimal example: a single-file uploader for a user avatar.

```blade
<livewire:laracrate-uploader :model="$user" collection="avatar" />
```

### The six components

All six are registered in `LaracrateServiceProvider` with the tag names below. They split into two families: **card uploaders** (single file, in-place preview) and **dropzones** (presigned direct upload to R2/S3, see the "Upload modes" and "HTTP endpoints" sections). Each family has an instant variant and a deferred variant. Deferred means selecting a file stages it for review and only persists when the user confirms.

| Tag | Class | Files | Behavior | Use case |
|-----|-------|-------|----------|----------|
| `laracrate-uploader` | `LaracrateUploader` | single | Instant: selecting a file calls `setFile()` immediately | Avatar, logo, cover image |
| `laracrate-uploader-deferred` | `LaracrateUploaderDeferred` | single | Deferred: preview, then `submit()` to persist or `cancel()` to discard | Same as above when you want an explicit confirm step |
| `laracrate-dropzone` | `LaracrateDropzone` | multiple | Instant direct upload per file, fires per-file and batch events | Galleries, document libraries |
| `laracrate-dropzone-deferred` | `LaracrateDropzoneDeferred` | multiple | Deferred: queue files, review, then upload the whole batch. Supports file slots and an integrated slot picker | Forms where files are tied to slots or categories |
| `laracrate-dropzone-single` | `LaracrateDropzoneSingle` | single | Instant direct upload, one file, in-place preview | One large file (a video, a PDF) too big for the card uploader |
| `laracrate-dropzone-single-deferred` | `LaracrateDropzoneSingleDeferred` | single | Deferred single direct upload, in-place preview | Same, with an explicit confirm step |

The card uploaders (`laracrate-uploader`, `laracrate-uploader-deferred`) push the file through Livewire's temporary upload, so the binary passes through PHP. The dropzones upload straight to your disk with a presigned PUT and the binary never touches PHP. Pick the dropzone variants for large files.

### Props

Both card uploaders accept the same props:

| Prop | Type | Default | Purpose |
|------|------|---------|---------|
| `model` | Eloquent model | required | The model the file belongs to (must use the `HasFiles` trait) |
| `collection` | string | required | Collection name as declared on the model |
| `variant` | string | `null` | Variant to show in the preview (falls back to the master, then the collection placeholder) |
| `theme` | string | `null` | Theme name (falls back to `laracrate.ui.default_theme`) |
| `layout` | string | `row` | `row` or `portrait` |
| `rounded` | string | `null` | Override preview rounding: `none`, `sm`, `md`, `lg`, `xl`, `2xl`, `3xl`, `full` |

The dropzone components share `model`, `collection`, and `theme`, plus their own props. Common ones across the dropzones: `contextKey` (an opaque identifier echoed back in the `laracrate-file-uploaded` event so you can route a file when several widgets share a page), `folderId` (target folder, see the "Folders" section), `persistQueue`, and `maxFiles`. `LaracrateDropzoneDeferred` adds slot support (`slots`, `slotOptions`, `slotLabel`, `slotPlaceholder`, `slotOptional`, `selectedSlotId`), a `creator` override (`creator`, or `creatorType` plus `creatorId`), `hideActions`, and a `layout` of `grid` or `list`. The single dropzones add `hideExisting`; the single deferred adds `hideActions`. See the "File slots" section for slot semantics.

### Immediate vs deferred

Instant uploader, persists on selection:

```blade
<livewire:laracrate-uploader :model="$user" collection="avatar" theme="ios" variant="medium" />
```

Deferred uploader, shows a preview and waits for the user to confirm:

```blade
<livewire:laracrate-uploader-deferred
    :model="$user"
    collection="avatar"
    theme="studio"
    layout="portrait"
/>
```

A deferred multi-file dropzone, queue then batch upload:

```blade
<livewire:laracrate-dropzone-deferred :model="$organization" collection="gallery" theme="studio" />
```

All components dispatch browser events you can listen for: `laracrate-file-uploaded` (per file, with `collection`, `fileId`, and `contextKey`), `laracrate-file-deleted` or `laracrate-file-removed`, and on the dropzones `laracrate-batch-completed` (with `ok` and `error` counts). The deferred dropzone also dispatches `laracrate-file-rejected` when a file is refused by the collection or a slot quota. See the "Events" section for the server-side events fired during processing.

### Themes and layouts

There are **11 themes**: `default`, `brutalist`, `material`, `ios`, `glassmorphism`, `neon`, `minimal`, `neumorphism`, `chatgpt`, `claude`, and `studio`. Each card-uploader theme ships in **2 layouts**, `row` and `portrait`, as `resources/views/uploader/themes/{theme}/{row,portrait}.blade.php`. The dropzone families resolve a single Blade per theme (for example `dropzone/themes/{theme}.blade.php`) and fall back to `default` when a theme has no matching view.

Set the project-wide default in config (the `theme` prop overrides it per component):

```php
// config/laracrate.php
'ui' => [
    'default_theme' => env('LARACRATE_THEME', 'default'),
],
```

```env
LARACRATE_THEME=studio
```

### Customizing views

Publish the package views to override any theme or write a new one:

```bash
php artisan vendor:publish --tag=laracrate-views
```

The views land in `resources/views/vendor/laracrate/`. Edit a theme in place, or add a new theme by creating `uploader/themes/{name}/row.blade.php` and `uploader/themes/{name}/portrait.blade.php` (create matching files under `dropzone/themes/`, `dropzone-single/themes/`, etc. if you want the new theme available to those components too). Run `php artisan view:clear` after editing Blade files. The view contract (the variables each theme receives, such as `$config`, `$file`, `$state`, `$previewUrl`, `$acceptAttr`, `$maxSizeKb`, `$pollMs`, `$roundedClass`) is the same for every theme; copy an existing theme as your starting point.

## Localization

The UI strings used by the Livewire components are translatable through the `laracrate` translation namespace, loaded by the service provider via `loadTranslationsFrom(__DIR__ . '/../lang', 'laracrate')`. The package ships English (`lang/en/uploader.php`) and Spanish (`lang/es/uploader.php`) out of the box, and resolves strings against your app's active `locale`.

Reference a string with the namespaced key:

```blade
{{ __('laracrate::uploader.upload') }}
```

The strings live in the `uploader` group. A sample of the keys (see the source files for the full list):

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
public function createVariant(string $variantName, array $overrides): self;

// Storage key helpers (path stores the full object key)
public function getKeyAttribute(): string;          // $file->key
public function siblingKey(string $newName): string;
public function variantKey(string $newName): string;

// URLs and rendering
public function url(?string $forceType = null): ?string;            // public/signed/stream per access
public function placeholderFor(string $type): string;
public function getLinkAttribute(): ?string;        // $file->link, alias of url()
public function getPreviewLinkAttribute(): string;  // $file->preview_link, thumbnail or placeholder
public function streamUrl(): string;                // signed package route, TTL config laracrate.urls.route_signed_ttl
public function downloadUrl(): string;
public function previewUrl(): string;

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
public function isImage(): bool;
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

### `StorageManager` service

`EduLazaro\Laracrate\Services\StorageManager`. The package facade over `Storage::disk()`. Resolve it with `app(StorageManager::class)`. See the Upload modes and Multi-tenancy, buckets and usage sections for context.

```php
public function urlFor(File $file): ?string;        // public/signed/stream based on $file->access
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

Laracrate ships its own test suite. It uses `Storage::fake()` and an in-memory SQLite connection, so there is no Docker, no live S3, and no external services to stand up.

Run it from inside the package directory:

```bash
composer install
vendor/bin/phpunit
```

The PHPUnit config defines two suites, `Unit` (`tests/Unit`) and `Feature` (`tests/Feature`), and sets `APP_ENV=testing` and `DB_CONNECTION=testing`. Any new feature you contribute should bring its own test under `tests/`.

## Roadmap

The items below are planned or under consideration. They are distilled from the package backlog and may change. Nothing here is a commitment to ship.

- **Deferred uploader Livewire component** (`laracrate-deferred-uploader`): select and preview a file, then commit to storage only on an explicit confirm, with a discard path. Useful for crop UIs, multi-step forms, and avoiding wasted uploads.
- **Library uploader Livewire component** (`laracrate-library-uploader`): multi-file collections with a default toggle, drag-and-drop reorder, and per-row delete, the pattern apps build by hand today.
- **`tile` and `banner` layouts**: a square compact layout with hover overlay controls, and a wide cover layout (16:9 or 21:9) for heroes and OG images, both across all uploader themes.
- **Sync processing in dev**: an option to run the processing pipeline inline when there is no queue worker (or when `queue.default` is `sync`), so a freshly uploaded file is not stuck showing its unoptimized original.
- **`php artisan laracrate:doctor`**: an installation diagnostic that checks disks, backend connectivity, ffmpeg/ffprobe, Imagick/GD, the queue worker, and inconsistent rows.
- **`php artisan laracrate:purge-orphan-tmp`**: cleanup of orphaned temporary uploads left behind when an upload fails midway.
- **Publishable upgrade migrations**: shipping the `path` backfill and the table-rename migrations as optional publishable migrations for apps coming from older versions.
- **Docs**: a "Migrating from older versions" README section, a `CHANGELOG.md` tracking breaking changes, and eventually a dedicated docs site.

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
