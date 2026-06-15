<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default collection / context
    |--------------------------------------------------------------------------
    |
    | Valor que aplica el schema cuando un File se inserta sin especificar
    | collection o context (p. ej. variants creados sin override). Cualquier
    | string vale; la convención es 'default'. Cambiarlo aquí re-migrando
    | actualiza la DEFAULT del schema.
    |
    */

    'default_collection' => 'default',
    'default_context'    => 'default',

    /*
    |--------------------------------------------------------------------------
    | Defaults por tipo de archivo
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        // Listas "safe by default" por type. Cualquier collection que NO
        // declare `accepted_extensions` / `accepted_mime_types` hereda éstas.
        // Excluidas a propósito: SVG (puede llevar <script>), ICO (CVEs
        // legacy), HTML/JS/PHP/EXE/BAT/SH (ejecutables), ZIP/RAR/7Z
        // (contenedores con vectores de zip-slip / contenido oculto). Para
        // permitirlos, override explícito en la collection con su propia
        // política de validación/sanitización.
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
    | Colecciones
    |--------------------------------------------------------------------------
    |
    | Cada colección define disk, access, single, sensitive, variants override.
    | Los modelos pueden sobreescribir vía $fileCollections.
    |
    | Opcional: `ttl_hours` declara TTL para los Files de la colección. El
    | comando `laracrate:purge-expired` (programable hourly) borra los Files
    | con created_at < now() - ttl_hours, cascadeando a variants y al binario
    | en backend. Útil para colecciones tipo `temp_uploads`, exports caducos,
    | drafts pendientes de promoción.
    |
    | Opcional: `quota_bytes` para que la app consulte vía `UsageReporter`
    | si un tenant excede su cuota antes de aceptar más uploads. El paquete
    | NO impone cuotas — la app valida con `UsageReporter` + esta constante
    | en el endpoint de presign.
    |
    */

    'collections' => [
        // Avatar: solo image, defaults globales (string suelto = sin override)
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

        // Galería: image + video con config propia para cada uno
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
                'video' => [
                    'preview' => [
                        'frame_at' => '00:00:01',
                        'variants' => [
                            'thumbnail' => ['width' => 300,  'height' => 300, 'fit' => true],
                            'medium'    => ['width' => 800,  'height' => 800],
                        ],
                    ],
                ],
            ],
        ],

        // Documentos: PDF con preview de primera página, signed URL
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

        // Identidad: stream + bind viewer + encrypt. La marca de agua se
        // configura per-variant (ver bloque 'watermark' al final del config).
        'identity' => [
            'disk'      => 'documents',
            'access'    => 'stream',
            'sensitive' => true,
            'encrypt'   => true,
            'types'     => [
                'image' => [
                    'variants' => [
                        'display' => ['width' => 1200, 'watermark' => true],
                    ],
                ],
                'document' => [
                    'preview' => [
                        'page'  => 1,
                        'width' => 2000,
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
    | Cadena de fallback cuando un File / variant no existe o no es del tipo
    | esperado por el render. Resolución (más específico → más general):
    |
    |   1. config('laracrate.collections.{name}.placeholder')
    |   2. config('laracrate.placeholders.{type}')
    |   3. config('laracrate.placeholders.default')
    |
    | Cada app sobreescribe en su config publicado.
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
    | signed_ttl:           TTL en minutos para signed URLs de R2 (defecto 5).
    | signed_cache_ttl:     TTL del cache server-side de la signed URL (defecto 4 min).
    | sensitive_redirect_ttl: TTL ultra corto para signed URL después de validar
    |                        en el stream controller (segundos).
    | route_signed_ttl:     TTL en minutos del HMAC de la ruta /files/{slug}/stream.
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
    | El paquete usa `PolicyRegistry` como sitio canónico para declarar la
    | lógica de autorización por `fileable_type`. Si `register_gate` está
    | activo (default), el ServiceProvider además ata `FilePolicy` al Gate
    | de Laravel para que la app pueda usar las ergonomías nativas:
    |
    |   @can('view', $file)
    |   $user->can('update', $file)
    |   $this->authorize('delete', $file)
    |   Route::middleware('can:view,file')
    |
    | Mapping: Gate `view`/`update`/`delete` ↔ registry `canView`/`canEdit`/`canDelete`.
    |
    | Apps que ya tengan su propia FilePolicy registrada o no quieran el
    | bridge ponen `register_gate => false`.
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
        // Prefijo "laracrate/" para evitar colisiones con rutas existentes del
        // proyecto bajo /files/... — muy común en apps que ya tienen un
        // FileController propio. Cámbialo solo si sabes que no choca.
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
    | Endpoints para consultar estado de procesamiento tras un upload async.
    |   GET  /laracrate/files/{slug}/status   → un archivo
    |   POST /laracrate/files/status          → batch (varios slugs)
    |
    */

    'status' => [
        'route_prefix' => 'laracrate/files',
        'middleware'   => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multipart upload (archivos grandes a S3/R2)
    |--------------------------------------------------------------------------
    |
    | threshold:        umbral en bytes para que el cliente decida usar
    |                   multipart. < umbral → single PUT con `presignedUpload`,
    |                   >= umbral → multipart vía endpoints /laracrate/multipart.
    |                   El servidor NO impone esto — lo decide el frontend
    |                   según `file.size`. Aquí solo es la sugerencia oficial.
    | part_size:        tamaño de cada parte en bytes (mín. 5 MB en S3).
    |                   10 MB es buen balance: 100 partes para 1 GB, 800 para 8 GB.
    | expire_minutes:   TTL de la sesión multipart. Tras esto, el cron
    |                   `laracrate:abort-stale-multipart` la aborta.
    | url_ttl_minutes:  TTL de las presigned URLs de cada parte. Si una parte
    |                   no se sube en ese tiempo, el cliente debe pedir nuevas
    |                   URLs vía POST /multipart/{id}/parts.
    | route_prefix:     prefijo URL de los endpoints multipart.
    | middleware:       middleware aplicado al grupo de rutas. Si null, hereda
    |                   del bloque uploads.
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
    | Image (procesamiento de imágenes)
    |--------------------------------------------------------------------------
    |
    | driver:             'imagick' (recomendado) | 'gd'
    | optimize_originals: true → re-encodea originales a webp con max dims
    | max_width/height:   límite del original tras optimize
    | quality:            calidad webp/jpeg del original
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
    | PDF preview (rasterizado de la primera página a imagen)
    |--------------------------------------------------------------------------
    |
    | Motor que convierte una página de PDF en PNG para la variante 'preview'.
    | No confundir con 'image.driver' (ese es para variantes/optimización).
    |
    |   'pdftoppm' → binario poppler-utils (apt install poppler-utils).
    |                NO requiere Ghostscript ni tocar policy.xml de ImageMagick.
    |   'imagick'  → extensión PHP imagick + binario Ghostscript (gs) + el
    |                coder PDF habilitado en la policy.xml de ImageMagick.
    |   'auto'     → intenta pdftoppm y, si no está disponible, cae a imagick.
    |
    | Override puntual por colección dentro del bloque 'preview':
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
    | Soporte de extracción de texto + embeddings vectoriales por collection.
    | Activación opt-in (la mayoría de uploads no lo necesitan).
    |
    | Para activarlo en una collection:
    |   'collections' => [
    |       'documents' => [
    |           'extract_text' => true,    // extrae texto del PDF/text
    |           'embed'        => true,    // genera embedding del texto
    |           ...
    |       ],
    |   ]
    |
    | Las apps registran su propio EmbeddingProvider en su ServiceProvider:
    |   $this->app->bind(
    |       EduLazaro\Laracrate\Contracts\EmbeddingProvider::class,
    |       MyCustomProvider::class
    |   );
    |
    | El package incluye OpenAiEmbeddingProvider como default.
    |
    | Igual con extractors de texto:
    |   $registry = app(EduLazaro\Laracrate\Support\TextExtractorRegistry::class);
    |   $registry->add(new MyOcrExtractor());
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Chunks backend (persistencia + búsqueda)
    |--------------------------------------------------------------------------
    |
    | Driver del store de chunks (ChunkStore contract):
    |
    |   - 'mysql'        → MysqlChunkStore. Persiste en `laracrate_file_chunks`
    |                       (FULLTEXT keyword + cosine similarity en PHP).
    |                       Sin dependencias externas. Escala bien hasta ~5K
    |                       chunks por scope.
    |
    |   - 'meilisearch'  → MeilisearchChunkStore. Sincroniza chunks a un
    |                       índice Meilisearch con embeddings inyectados
    |                       (modo userProvided). Búsqueda híbrida nativa con
    |                       `semanticRatio` (BM25 + vector) server-side.
    |                       Requiere `meilisearch/meilisearch-php` y un
    |                       binding de Meilisearch\Client en la app.
    |
    | Apps custom (Qdrant, pgvector) pueden bindear ChunkStore directamente.
    */
    'chunks' => [
        'driver' => env('LARACRATE_CHUNKS_DRIVER', 'mysql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch (cuando chunks.driver = meilisearch)
    |--------------------------------------------------------------------------
    */
    'meilisearch' => [
        'index'    => env('LARACRATE_MEILISEARCH_INDEX', 'laracrate_file_chunks'),
        'embedder' => env('LARACRATE_MEILISEARCH_EMBEDDER', 'default'),
    ],

    'embeddings' => [
        // Master switch. Si false, ningún archivo se procesa para embedding
        // aunque la collection lo pida.
        'enabled' => false,

        // Provider implementation (clase que implementa EmbeddingProvider).
        // El registro real se hace en LaracrateServiceProvider.
        'provider' => 'openai',

        // API key del provider (para OpenAI). Si null, lee OPENAI_API_KEY del env.
        'api_key' => env('LARACRATE_EMBEDDINGS_API_KEY'),

        // Modelo del provider. Puede variar por entorno (dev/prod), por eso
        // permite override desde env.
        'model' => env('LARACRATE_EMBEDDINGS_MODEL', 'text-embedding-3-small'),

        // Dimensiones del vector. Fijado por el modelo — solo cambiar si
        // se cambia el modelo a uno con otras dimensiones.
        'dimensions' => 1536,

        // Tokens aprox por chunk. 0 = sin chunking (1 fila por File).
        'chunk_size' => 1000,

        // Solapamiento entre chunks consecutivos (en tokens).
        'chunk_overlap' => 100,

        // Tamaño de batch al llamar al provider (chunks por request).
        'batch_size' => 16,

        // Chain de extractors de texto. La extracción itera por orden y, si un
        // extractor devuelve menos texto del que define `min_text_per_file`,
        // intenta con el siguiente. Vacío = defaults built-in (Pdf + Plain).
        //
        // Patrón típico para PDFs escaneados:
        //   PdfTextExtractor       (smalot, gratis y rápido — PDFs nativos)
        //   OcrPdfTextExtractor    (OCR con Claude/OpenAI — PDFs escaneados)
        'extractors' => [
            // \EduLazaro\Laracrate\Extractors\PdfTextExtractor::class,
            // \EduLazaro\Laracrate\Extractors\OcrPdfTextExtractor::class,
            // \EduLazaro\Laracrate\Extractors\PlainTextExtractor::class,
        ],

        // Umbral mínimo de chars que un extractor debe producir para considerarse
        // exitoso. Si está por debajo, se intenta con el siguiente extractor
        // de la chain. 100 chars cubre PDFs vacíos / sólo metadata.
        'min_text_per_file' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR (PDF scanning fallback)
    |--------------------------------------------------------------------------
    |
    | Config del OcrPdfTextExtractor. Provider seleccionable via env.
    | API keys con prefijo LARACRATE_ y fallback a la key genérica del provider.
    */
    'ocr' => [
        // 'anthropic' | 'openai'
        'provider' => env('LARACRATE_OCR_PROVIDER', 'anthropic'),

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
    | Marca de agua que se incrusta en el binario de variants concretas.
    | El original (master) NUNCA lleva watermark — solo las variants que
    | lo declaren explícitamente.
    |
    | Activación per-variant en la config de la colección:
    |   'collections' => [
    |       'identity' => [
    |           'types' => [
    |               'image' => [
    |                   'variants' => [
    |                       'thumbnail' => ['width' => 300, ...],         // sin watermark
    |                       'display'   => ['width' => 1200, 'watermark' => true],  // CON
    |                   ],
    |               ],
    |           ],
    |       ],
    |   ],
    |
    */

    'watermark' => [
        // Path absoluto o relativo a public_path() de la PNG a superponer.
        // null = no se aplica imagen (puede ir solo el texto si está configurado).
        // Se mantiene en env porque el path real cambia por entorno (dev/prod).
        'image_path' => env('LARACRATE_WATERMARK_IMAGE', null),

        // Ancho del watermark como fracción del ancho de la variant (0.0 - 1.0).
        // 0.40 = ocupa el 40% del ancho de la imagen, escala proporcional.
        'size' => 0.40,

        // Opacidad de la PNG superpuesta (0-100). Convención de Intervention.
        'opacity' => 30,

        // Posición de la PNG. 'center' | 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right'.
        'position' => 'center',

        // Texto auxiliar opcional (incrustado además de la PNG).
        'text' => [
            // Contenido del texto:
            //   - null  → sin texto
            //   - string → texto fijo
            //   - closure(File): ?string → texto dinámico por File
            // (Como esto no se puede declarar en env, se override en
            // app/Providers o publicando el config y usando una closure.)
            'content' => null,

            // Tamaño de fuente como fracción del ancho de la imagen (1.95% por defecto).
            'font_size_ratio' => 0.0195,

            // Color en formato rgba CSS.
            'color' => 'rgba(255, 255, 255, 0.60)',

            // Posición. 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'.
            'position' => 'bottom-left',

            // Padding desde el borde, en píxeles.
            'padding' => 20,

            // Path al .ttf de la fuente. null = fuente del sistema.
            'font_path' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI (Livewire uploader)
    |--------------------------------------------------------------------------
    |
    | Tema por defecto para `<livewire:laracrate-uploader>` cuando no se pasa
    | la prop `theme=`. Temas built-in:
    |   default · brutalist · material · ios · glassmorphism · neon · minimal · neumorphism
    |
    | Para temas custom: `vendor:publish --tag=laracrate-views` y crea el
    | blade en `resources/views/vendor/laracrate/uploader/themes/{nombre}.blade.php`.
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
