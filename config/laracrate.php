<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default collection / context
    |--------------------------------------------------------------------------
    |
    | Value the schema applies when a File is inserted without an explicit
    | collection or context (e.g. variants created without an override). Any
    | string works; the convention is 'default'. Changing it here and
    | re-migrating updates the schema DEFAULT.
    |
    */

    'default_collection' => 'default',
    'default_context'    => 'default',

    /*
    |--------------------------------------------------------------------------
    | Per file type defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        // "Safe by default" lists per type. Any collection that does NOT
        // declare `accepted_extensions` / `accepted_mime_types` inherits these.
        // Excluded on purpose: SVG (can carry <script>), ICO (legacy CVEs),
        // HTML/JS/PHP/EXE/BAT/SH (executables), ZIP/RAR/7Z (containers with
        // zip-slip vectors / hidden content). To allow them, override
        // explicitly in the collection with its own validation/sanitization
        // policy.
        'image' => [
            'accepted_mime_types' => [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'image/heic', 'image/heif', 'image/bmp', 'image/tiff',
            ],
            'accepted_extensions' => [
                'jpeg', 'jpg', 'png', 'gif', 'webp',
                'heic', 'heif', 'bmp', 'tiff',
            ],
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
            'accepted_mime_types' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.oasis.opendocument.text',
                'application/rtf',
                'text/plain',
                'text/markdown',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.oasis.opendocument.spreadsheet',
                'text/csv',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.presentation',
                'application/epub+zip',
            ],
            'accepted_extensions' => [
                'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md',
                'xls', 'xlsx', 'ods', 'csv',
                'ppt', 'pptx', 'odp',
                'epub',
            ],
            'max_file_size'       => 20480,
        ],
        'audio' => [
            'accepted_mime_types' => [
                'audio/mpeg', 'audio/wav', 'audio/x-wav',
                'audio/ogg', 'audio/mp4',
                'audio/flac', 'audio/aac',
                'audio/opus', 'audio/webm',
            ],
            'accepted_extensions' => [
                'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'opus', 'webm',
            ],
            'max_file_size'       => 5120,
        ],
        'video' => [
            'accepted_mime_types' => [
                'video/mp4', 'video/quicktime', 'video/webm',
                'video/x-msvideo', 'video/x-matroska', 'video/ogg',
            ],
            'accepted_extensions' => [
                'mp4', 'mov', 'webm', 'm4v', 'mkv', 'avi', 'ogv',
            ],
            'max_file_size'       => 102400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | Each collection defines disk, access, single, sensitive, variants
    | override. Models can override via $fileCollections.
    |
    | Optional: `ttl_hours` declares a TTL for the collection's Files. The
    | `laracrate:purge-expired` command (schedulable hourly) deletes Files
    | with created_at < now() - ttl_hours, cascading to variants and the
    | backend binary. Useful for collections like `temp_uploads`, expiring
    | exports, drafts pending promotion.
    |
    | Optional: `quota_bytes` so the app can check via `UsageReporter` whether
    | a tenant exceeds its quota before accepting more uploads. The package
    | does NOT enforce quotas, the app validates with `UsageReporter` plus this
    | constant in the presign endpoint.
    |
    | These 2 are EXAMPLE collections, not the config of any specific app.
    | They use standard Laravel disks (`public`, `s3`) so the package boots
    | as-is after install. Copy them and adapt to your real disks /
    | collections in your app's published config.
    |
    */

    'collections' => [
        // Example 1: user avatar. Public image on the `public` disk (present
        // out-of-the-box in any Laravel), one per model, with cropped square
        // variants.
        'avatar' => [
            'disk'   => 'public',
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

        // Example 2: private PDF documents on the `s3` disk (already present in
        // Laravel's default filesystems.php), served via a temporary signed URL
        // with a rasterized preview of the first page.
        'documents' => [
            'disk'   => 's3',
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

    /*
    |--------------------------------------------------------------------------
    | Placeholders
    |--------------------------------------------------------------------------
    |
    | Fallback chain when a File / variant does not exist or is not the type
    | expected by the render. Resolution (most specific to most general):
    |
    |   1. config('laracrate.collections.{name}.placeholder')
    |   2. config('laracrate.placeholders.{type}')
    |   3. config('laracrate.placeholders.default')
    |
    | Each app overrides these in its published config.
    |
    */

    'placeholders' => [
        'default'  => '/img/laracrate/file.svg',
        'image'    => '/img/laracrate/image.svg',
        'video'    => '/img/laracrate/video.svg',
        'audio'    => '/img/laracrate/audio.svg',
        'document' => '/img/laracrate/document.svg',
    ],

    /*
    |--------------------------------------------------------------------------
    | URL strategy
    |--------------------------------------------------------------------------
    |
    | signed_ttl:           TTL in minutes for R2 signed URLs (default 5).
    | signed_cache_ttl:     TTL of the server-side cache of the signed URL (default 4 min).
    | sensitive_redirect_ttl: ultra short TTL for the signed URL after validating
    |                        in the stream controller (seconds).
    | route_signed_ttl:     TTL in minutes of the HMAC of the /files/{slug}/stream route.
    |
    */

    'urls' => [
        'signed_ttl'             => 5,
        'signed_cache_ttl'       => 4,
        'sensitive_redirect_ttl' => 10,
        'route_signed_ttl'       => 15,
        'bind_to_user'           => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies (Gate bridge)
    |--------------------------------------------------------------------------
    |
    | The package uses `PolicyRegistry` as the canonical place to declare
    | authorization logic per `fileable_type`. If `register_gate` is active
    | (default), the ServiceProvider also binds `FilePolicy` to Laravel's Gate
    | so the app can use the native ergonomics:
    |
    |   @can('view', $file)
    |   $user->can('update', $file)
    |   $this->authorize('delete', $file)
    |   Route::middleware('can:view,file')
    |
    | Mapping: Gate `view`/`update`/`delete` to registry `canView`/`canEdit`/`canDelete`.
    |
    | Apps that already have their own FilePolicy registered, or that do not
    | want the bridge, set `register_gate => false`.
    |
    */

    'policies' => [
        'register_gate' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming
    |--------------------------------------------------------------------------
    */

    'stream' => [
        // "laracrate/" prefix to avoid collisions with existing project routes
        // under /files/..., very common in apps that already have their own
        // FileController. Change it only if you know it does not clash.
        'route_prefix'        => 'laracrate/files',
        'route_name_prefix'   => 'laracrate.files',
        'middleware'          => ['web', 'auth'],
        'increment_downloads' => true,
        'log_access'          => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Status polling
    |--------------------------------------------------------------------------
    |
    | Endpoints to query processing status after an async upload.
    |   GET  /laracrate/files/{slug}/status   -> a single file
    |   POST /laracrate/files/status          -> batch (several slugs)
    |
    */

    'status' => [
        'route_prefix' => 'laracrate/files',
        'middleware'   => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Direct uploads (presigned to R2/S3)
    |--------------------------------------------------------------------------
    |
    | route_prefix:  URL prefix of the presign / cancel endpoints.
    | middleware:    middleware of the uploads route group. The app is
    |                responsible for authorization (auth, throttle, etc.).
    | allowed_disks: allow-list of disks that direct uploads are permitted to
    |                (validated on presign and multipart init). Empty = no
    |                restriction. The multipart block inherits this middleware
    |                when its own is null.
    |
    */

    'uploads' => [
        'route_prefix'  => 'laracrate/uploads',
        'middleware'    => ['web', 'auth'],
        'allowed_disks' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multipart upload (large files to S3/R2)
    |--------------------------------------------------------------------------
    |
    | threshold:        threshold in bytes for the client to decide to use
    |                   multipart. < threshold -> single PUT with `presignedUpload`,
    |                   >= threshold -> multipart via /laracrate/multipart endpoints.
    |                   The server does NOT enforce this, the frontend decides
    |                   based on `file.size`. This is just the official hint.
    | part_size:        size of each part in bytes (min. 5 MB in S3).
    |                   10 MB is a good balance: 100 parts for 1 GB, 800 for 8 GB.
    | expire_minutes:   TTL of the multipart session. After this, the
    |                   `laracrate:abort-stale-multipart` cron aborts it.
    | url_ttl_minutes:  TTL of each part's presigned URLs. If a part is not
    |                   uploaded in that time, the client must request new URLs
    |                   via POST /multipart/{id}/parts.
    | route_prefix:     URL prefix of the multipart endpoints.
    | middleware:       middleware applied to the route group. If null, inherits
    |                   from the uploads block.
    |
    */

    'multipart' => [
        'threshold'       => 100 * 1024 * 1024,  // 100 MB
        'part_size'       => 10  * 1024 * 1024,  // 10 MB
        'expire_minutes'  => 60,
        'url_ttl_minutes' => 60,
        'route_prefix'    => 'laracrate/multipart',
        'middleware'      => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Image (image processing)
    |--------------------------------------------------------------------------
    |
    | driver:             'imagick' (recommended) | 'gd'
    | optimize_originals: true -> re-encodes originals to webp with max dims
    | max_width/height:   limit of the original after optimize
    | quality:            webp/jpeg quality of the original
    |
    */

    'image' => [
        'driver'             => 'imagick',
        'optimize_originals' => false,
        'max_width'          => 1920,
        'max_height'         => 1920,
        'quality'            => 85,
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF preview (rasterize the first page to an image)
    |--------------------------------------------------------------------------
    |
    | Engine that converts a PDF page into a PNG for the 'preview' variant.
    | Not to be confused with 'image.driver' (that one is for variants/optimization).
    |
    |   'pdftoppm' -> poppler-utils binary (apt install poppler-utils).
    |                Does NOT require Ghostscript or touching ImageMagick's policy.xml.
    |   'imagick'  -> PHP imagick extension + Ghostscript binary (gs) + the PDF
    |                coder enabled in ImageMagick's policy.xml.
    |   'auto'     -> tries pdftoppm and, if unavailable, falls back to imagick.
    |
    | Per-collection override inside the 'preview' block:
    |   'preview' => ['page' => 1, 'width' => 600, 'engine' => 'pdftoppm'],
    |
    */

    'pdf_preview_engine' => 'auto',

    /*
    |--------------------------------------------------------------------------
    | Video (transcoding)
    |--------------------------------------------------------------------------
    */

    'video' => [
        'max_width'    => 1920,
        'max_height'   => 1920,
        'bitrate_kbps' => 2500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    */

    'encryption' => [
        'driver' => 'laravel',
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Support for text extraction + vector embeddings per collection. Opt-in
    | activation (most uploads do not need it).
    |
    | To enable it on a collection:
    |   'collections' => [
    |       'documents' => [
    |           'extract_text' => true,    // extracts text from the PDF/text
    |           'embed'        => true,    // generates an embedding of the text
    |           ...
    |       ],
    |   ]
    |
    | Apps register their own EmbeddingProvider in their ServiceProvider:
    |   $this->app->bind(
    |       EduLazaro\Laracrate\Contracts\EmbeddingProvider::class,
    |       MyCustomProvider::class
    |   );
    |
    | The package includes OpenAiEmbeddingProvider as the default.
    |
    | Same goes for text extractors:
    |   $registry = app(EduLazaro\Laracrate\Support\TextExtractorRegistry::class);
    |   $registry->add(new MyOcrExtractor());
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Chunks backend (persistence + search)
    |--------------------------------------------------------------------------
    |
    | Driver of the chunk store (ChunkStore contract):
    |
    |   - 'mysql'        -> MysqlChunkStore. Persists to `laracrate_file_chunks`
    |                       (FULLTEXT keyword + cosine similarity in PHP).
    |                       No external dependencies. Scales well up to ~5K
    |                       chunks per scope.
    |
    |   - 'meilisearch'  -> MeilisearchChunkStore. Syncs chunks to a Meilisearch
    |                       index with injected embeddings (userProvided mode).
    |                       Native hybrid search with `semanticRatio`
    |                       (BM25 + vector) server-side. Requires
    |                       `meilisearch/meilisearch-php` and a binding of
    |                       Meilisearch\Client in the app.
    |
    | Custom apps (Qdrant, pgvector) can bind ChunkStore directly.
    */
    'chunks' => [
        'driver' => env('LARACRATE_CHUNKS_DRIVER', 'mysql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch (when chunks.driver = meilisearch)
    |--------------------------------------------------------------------------
    */
    'meilisearch' => [
        'index'    => env('LARACRATE_MEILISEARCH_INDEX', 'laracrate_file_chunks'),
        'embedder' => env('LARACRATE_MEILISEARCH_EMBEDDER', 'default'),
    ],

    'embeddings' => [
        // Master switch. If false, no file is processed for embedding even if
        // the collection requests it.
        'enabled' => false,

        // Provider implementation (class implementing EmbeddingProvider). The
        // actual registration happens in LaracrateServiceProvider.
        'provider' => 'openai',

        // Provider API key (for OpenAI). If null, reads OPENAI_API_KEY from env.
        'api_key' => env('LARACRATE_EMBEDDINGS_API_KEY'),

        // Provider model. Can vary per environment (dev/prod), hence it allows
        // an override from env.
        'model' => env('LARACRATE_EMBEDDINGS_MODEL', 'text-embedding-3-small'),

        // Vector dimensions. Fixed by the model, only change it if you switch
        // the model to one with different dimensions.
        'dimensions' => 1536,

        // Approx tokens per chunk. 0 = no chunking (1 row per File).
        'chunk_size' => 1000,

        // Overlap between consecutive chunks (in tokens).
        'chunk_overlap' => 100,

        // Batch size when calling the provider (chunks per request).
        'batch_size' => 16,

        // Text extractor chain. Extraction iterates in order and, if an
        // extractor returns less text than `min_text_per_file` defines, it
        // tries the next one. Empty = built-in defaults (Pdf + Plain).
        //
        // Typical pattern for scanned PDFs:
        //   PdfTextExtractor       (smalot, free and fast, native PDFs)
        //   OcrPdfTextExtractor    (OCR with Claude/OpenAI, scanned PDFs)
        'extractors' => [
            // \EduLazaro\Laracrate\Extractors\PdfTextExtractor::class,
            // \EduLazaro\Laracrate\Extractors\OcrPdfTextExtractor::class,
            // \EduLazaro\Laracrate\Extractors\PlainTextExtractor::class,
        ],

        // Minimum char threshold an extractor must produce to be considered
        // successful. Below it, the next extractor in the chain is tried.
        // 100 chars covers empty / metadata-only PDFs.
        'min_text_per_file' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR (PDF scanning fallback)
    |--------------------------------------------------------------------------
    |
    | Config for OcrPdfTextExtractor. Provider selectable via env. API keys
    | prefixed with LARACRATE_ with a fallback to the provider's generic key.
    */
    'ocr' => [
        // 'anthropic' | 'openai'
        'provider' => env('LARACRATE_OCR_PROVIDER', 'anthropic'),

        // Locale ('en', 'es', 'fr', ...) for the auto-generated image
        // description when the image has no visible text to infer the language
        // from (image OCR only). The description follows the visible text
        // language when present; this is just the fallback. Apps serving a
        // non-English audience set it directly here, e.g. 'es'.
        'locale' => 'en',

        'anthropic' => [
            'api_key' => env('LARACRATE_ANTHROPIC_API_KEY') ?: env('ANTHROPIC_API_KEY'),
            'model'   => env('LARACRATE_OCR_ANTHROPIC_MODEL', env('LARACRATE_OCR_MODEL', 'claude-haiku-4-5')),
        ],

        'openai' => [
            'api_key' => env('LARACRATE_OPENAI_API_KEY') ?: env('OPENAI_API_KEY'),
            'model'   => env('LARACRATE_OCR_OPENAI_MODEL', env('LARACRATE_OCR_MODEL', 'gpt-4o-mini')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Watermark
    |--------------------------------------------------------------------------
    |
    | Watermark embedded into the binary of specific variants. The original
    | (master) NEVER carries a watermark, only the variants that declare it
    | explicitly.
    |
    | Per-variant activation in the collection config:
    |   'collections' => [
    |       'identity' => [
    |           'types' => [
    |               'image' => [
    |                   'variants' => [
    |                       'thumbnail' => ['width' => 300, ...],         // no watermark
    |                       'display'   => ['width' => 1200, 'watermark' => true],  // WITH
    |                   ],
    |               ],
    |           ],
    |       ],
    |   ],
    |
    */

    'watermark' => [
        // Absolute path or path relative to public_path() of the PNG to
        // overlay. null = no image applied (text only can be used if
        // configured). Kept in env because the real path changes per
        // environment (dev/prod).
        'image_path' => env('LARACRATE_WATERMARK_IMAGE', null),

        // Watermark width as a fraction of the variant width (0.0 - 1.0).
        // 0.40 = takes 40% of the image width, scaled proportionally.
        'size' => 0.40,

        // Opacity of the overlaid PNG (0-100). Intervention convention.
        'opacity' => 30,

        // PNG position. 'center' | 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right'.
        'position' => 'center',

        // Optional auxiliary text (embedded in addition to the PNG).
        'text' => [
            // Text content:
            //   - null  -> no text
            //   - string -> fixed text
            //   - closure(File): ?string -> dynamic text per File
            // (Since this cannot be declared in env, override it in
            // app/Providers or by publishing the config and using a closure.)
            'content' => null,

            // Font size as a fraction of the image width (1.95% by default).
            'font_size_ratio' => 0.0195,

            // Color in CSS rgba format.
            'color' => 'rgba(255, 255, 255, 0.60)',

            // Position. 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'.
            'position' => 'bottom-left',

            // Padding from the edge, in pixels.
            'padding' => 20,

            // Path to the font .ttf. null = system font.
            'font_path' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI (Livewire uploader)
    |--------------------------------------------------------------------------
    |
    | Default theme for `<livewire:laracrate-uploader>` when the `theme=` prop
    | is not passed. Built-in themes:
    |   default · brutalist · material · ios · glassmorphism · neon · minimal · neumorphism
    |
    | For custom themes: `vendor:publish --tag=laracrate-views` and create the
    | blade in `resources/views/vendor/laracrate/uploader/themes/{name}.blade.php`.
    |
    */

    'ui' => [
        'default_theme' => env('LARACRATE_THEME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('LARACRATE_QUEUE_CONNECTION', null),
        'name'       => env('LARACRATE_QUEUE_NAME', 'default'),
    ],

];
