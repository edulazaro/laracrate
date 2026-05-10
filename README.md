# Laracrate

Almacenamiento polimórfico de archivos para Laravel con upload directo a R2/S3, control de acceso granular, streaming de contenido sensible, image variants automáticos, preview de vídeo y PDF, watermark per-variant, multipart uploads, extracción de texto y embeddings vectoriales.

## Tabla de contenidos

1. [Filosofía](#filosofía)
2. [Instalación](#instalación)
3. [Modelo de datos](#modelo-de-datos)
4. [Configuración](#configuración)
5. [Uso desde el modelo](#uso-desde-el-modelo)
6. [Pipeline de procesamiento](#pipeline-de-procesamiento)
7. [Variants](#variants)
8. [Modos de upload](#modos-de-upload)
9. [Endpoints HTTP](#endpoints-http)
10. [Sensitive content](#sensitive-content)
11. [Comandos artisan](#comandos-artisan)
12. [Componente Livewire opcional](#componente-livewire-opcional)
13. [API completa](#api-completa)
14. [Tests](#tests)
15. [Dependencias](#dependencias)
16. [License](#license)

## Filosofía

1. **Backend agnóstico al frontend.** El core no depende de Livewire ni Alpine. Solo expone endpoints, un trait, y un servicio.
2. **Reutiliza `Storage::disk()` de Laravel.** Las credenciales de los disks viven en `config/filesystems.php` (single source of truth). El paquete no duplica configuración.
3. **Pipeline de Actions.** Cada operación es una clase aislada (`edulazaro/laractions`), testeable y queueable.
4. **Procesado async.** Variants, preview de vídeo y PDF, extracción de texto, embeddings, todo en cola. El upload del usuario es instantáneo.
5. **3 modos de acceso por colección**: `public` (CDN directo), `signed` (URL firmada temporal), `stream` (controller con audit y viewer bind).
6. **Convención `path = key entera`.** El campo `path` del File guarda la key completa del objeto en disk (directorios, filename, extensión). El campo `name` es denormalización de `basename($path)`.

## Instalación

### 1. Path repository en `composer.json` de la app (mientras no esté en Packagist)

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

### 2. Instalar y publicar

```bash
composer require edulazaro/laracrate
php artisan vendor:publish --tag=laracrate-config
php artisan migrate
```

`migrate` crea 3 tablas, todas con prefijo `laracrate_`:

- `laracrate_files`, tabla principal de top-level y variants.
- `laracrate_file_contents`, chunks de texto extraído y embeddings (opt-in).
- `laracrate_multipart_uploads`, sesiones de multipart upload activas.

El prefijo `laracrate_` evita choques con tablas legacy `files` que existen en muchas apps Laravel.

### 3. Disks en `config/filesystems.php`

Añade los disks que vayas a usar (R2/S3 para storage real, `local` para dev):

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

## Modelo de datos

### Tabla `laracrate_files`

47 columnas core más JSON:

```
id, slug (ulid)
parent_id, variant                            (jerarquía variants/preview)
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

### Tablas auxiliares

- `laracrate_file_contents`, una fila por chunk de texto extraído. Si la collection define `chunk_size: 0`, una fila por File con todo el texto.
- `laracrate_multipart_uploads`, sesiones activas de multipart upload S3/R2. Vida típica de minutos a horas. El cron `laracrate:abort-stale-multipart` aborta las que pasan de `expires_at`.

### Conceptos clave

- **`path` es la key entera del objeto en disk.** No se concatena con `name`. Acceso recomendado: `$file->key` (accessor que hace `ltrim($file->path, '/')`).
- **`name` es denormalización** del basename (con extensión). Útil para queries y display, jamás se concatena.
- **`parent_id` y `variant`**: cualquier File hijo (thumbnail, preview, transcoded) tiene `parent_id` apuntando al padre y `variant` con el rol (`thumbnail`, `medium`, `preview`, `display`...). Es recursivo: el preview de un vídeo tiene sus propios variants hijos.
- **3 polymorphic ortogonales**: `fileable` (a qué pertenece), `creator` (quién lo creó), `tenant` (scope multi-tenant).
- **`access`**: `public` produce URL directa CDN, `signed` produce URL firmada con TTL, `stream` produce ruta del paquete con re-validación por request.
- **`processing_status`**: `pending`, `processing`, `completed`, `failed`. Enum `EduLazaro\Laracrate\Enums\ProcessingStatus`.

## Configuración

Todo vive en `config/laracrate.php` (publicado con `vendor:publish`).

### `default_collection` y `default_context`

Valores por defecto del schema cuando un File se inserta sin especificarlos.

```php
'default_collection' => 'default',
'default_context'    => 'default',
```

### `defaults`, defaults por tipo de archivo

Aplican a todas las colecciones salvo override. Cada type define mime types aceptados, tamaño máximo, calidad, dimensiones máximas y variants default.

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

### `collections`, definición de cada colección

Cada collection define disk, access mode, types aceptados con su config, y opcionalmente `single`, `sensitive`, `encrypt`, `ttl_hours`, `quota_bytes`, `component`, `placeholder`.

```php
'collections' => [

    'avatar' => [
        'disk'      => 'media',
        'access'    => 'public',
        'single'    => true,                   // solo 1 file por owner
        'component' => 'user-avatar',          // componente blade default (opcional)
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
        'sensitive' => true,                   // bind URL al user
        'encrypt'   => true,                   // cifra binario en reposo
        'types'     => [
            'image' => [
                'variants' => [
                    'thumbnail' => ['width' => 300, 'height' => 300],            // sin watermark
                    'display'   => ['width' => 1200, 'watermark' => true],       // con watermark
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
        'ttl_hours' => 24,                     // se purgan via comando
    ],

],
```

**Reglas de `types`:**

- Lista blanca de qué types acepta la colección, más config de qué hacer con cada uno.
- Cada entrada puede ser string suelto (`'image'`, hereda defaults globales) o array (`'image' => [override]`).
- `variants` siempre dentro de un type (`types.image.variants`).
- `preview` para document y video genera un variant especial; sus propios variants hijos van en `preview.variants`.
- Los defaults globales del type se mergean con el override de la collection. Solo declaras lo que quieres cambiar.

### `placeholders`, fallback cuando no hay archivo

Resolución (más específico al más general):

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

Cada slot acepta string fijo o closure dinámica:

```php
'image' => fn ($collection, $type, $model) => "/api/avatars/{$model->id}.svg",
```

### `urls`, política de URLs

```php
'urls' => [
    'signed_ttl'             => 5,    // TTL minutos signed URL R2
    'signed_cache_ttl'       => 4,    // TTL cache server-side de la signed URL
    'sensitive_redirect_ttl' => 10,   // TTL ultra corto post-validación (segundos)
    'route_signed_ttl'       => 15,   // TTL minutos del HMAC de /files/{slug}/stream
    'bind_to_user'           => true, // amarrar URL al user actual cuando sensitive
],
```

### `policies`, bridge al Gate de Laravel

```php
'policies' => [
    'register_gate' => true,
],
```

Si `register_gate` está activo, puedes usar las ergonomías nativas:

```php
@can('view', $file)
$user->can('update', $file)
$this->authorize('delete', $file)
Route::middleware('can:view,file')
```

Mapping: `view`/`update`/`delete` van al registry `canView`/`canEdit`/`canDelete`.

### `stream`, endpoints de streaming

```php
'stream' => [
    'route_prefix'        => 'files',
    'route_name_prefix'   => 'laracrate.files',
    'middleware'          => ['web', 'auth'],
    'increment_downloads' => true,
    'log_access'          => true,
],
```

### `status`, endpoints de polling

```php
'status' => [
    'route_prefix' => 'laracrate/files',
    'middleware'   => ['web', 'auth'],
],
```

Endpoints:

- `GET /laracrate/files/{slug}/status`, estado de un archivo.
- `POST /laracrate/files/status`, batch de varios slugs.

### `multipart`, uploads grandes

```php
'multipart' => [
    'threshold'       => 100 * 1024 * 1024,  // 100 MB; el frontend decide cuándo usar multipart
    'part_size'       => 10  * 1024 * 1024,  // 10 MB por parte (mín. 5 MB en S3)
    'expire_minutes'  => 60,                 // TTL sesión multipart
    'url_ttl_minutes' => 60,                 // TTL URLs presigned de cada parte
    'route_prefix'    => 'laracrate/multipart',
    'middleware'      => null,               // null hereda de uploads
],
```

### `image`, procesamiento de imágenes

```php
'image' => [
    'driver'             => 'imagick',  // 'imagick' (recomendado) o 'gd'
    'optimize_originals' => false,      // re-encodea original a webp con max dims
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

Requiere `ffmpeg` y `ffprobe` en el path del servidor.

### `encryption`, cifrado de binarios sensibles

```php
'encryption' => [
    'driver' => 'laravel',
],
```

Si una collection declara `'encrypt' => true`, el binario se cifra con `EncryptFileAction` antes de subirse al backend, y se desencripta on-the-fly al servir desde `StreamFileController`.

### `embeddings`, extracción de texto y vectores

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

Activación per-collection:

```php
'collections' => [
    'documents' => [
        'extract_text' => true,
        'embed'        => true,
        // ...
    ],
],
```

Provider custom:

```php
// AppServiceProvider::register()
$this->app->bind(
    \EduLazaro\Laracrate\Contracts\EmbeddingProvider::class,
    \App\Embeddings\MyCustomProvider::class
);
```

Text extractor custom:

```php
// AppServiceProvider::boot()
$registry = app(\EduLazaro\Laracrate\Support\TextExtractorRegistry::class);
$registry->add(new \App\Extractors\MyOcrExtractor());
```

El paquete incluye:

- `OpenAiEmbeddingProvider` (default).
- `NullEmbeddingProvider` (no-op para testing).
- `PdfTextExtractor` (PDFs vía `smalot/pdfparser`).
- `PlainTextExtractor` (text/*).

### `watermark`, marca de agua per-variant

Se incrusta en el binario de variants concretas. **El original (master) NUNCA lleva watermark.** Solo las variants que lo declaren explícitamente.

```php
'watermark' => [
    'image_path' => env('LARACRATE_WATERMARK_IMAGE', null),  // PNG a superponer
    'size'       => 0.40,                                    // 40% del ancho de la variant
    'opacity'    => 30,                                      // 0 a 100
    'position'   => 'center',

    'text' => [
        'content'         => null,                           // null, string, o closure(File): ?string
        'font_size_ratio' => 0.0195,
        'color'           => 'rgba(255, 255, 255, 0.60)',
        'position'        => 'bottom-left',
        'padding'         => 20,
        'font_path'       => null,
    ],
],
```

Activación per-variant:

```php
'collections' => [
    'identity' => [
        'types' => [
            'image' => [
                'variants' => [
                    'thumbnail' => ['width' => 300, 'height' => 300],            // sin watermark
                    'display'   => ['width' => 1200, 'watermark' => true],       // con watermark
                ],
            ],
        ],
    ],
],
```

Si cambias la PNG o ajustas tamaños, regeneras los variants y el master sigue intacto.

### `ui`, tema por defecto del componente Livewire opcional

```php
'ui' => [
    'default_theme' => env('LARACRATE_THEME', 'default'),
],
```

Solo aplica si usas el componente Livewire opcional. Detalles en su sección.

### `queue`

```php
'queue' => [
    'connection' => env('LARACRATE_QUEUE_CONNECTION', null),  // null usa default Laravel
    'name'       => env('LARACRATE_QUEUE_NAME', 'default'),
],
```

Útil para aislar el procesamiento de archivos de otras colas.

## Uso desde el modelo

### Trait `HasFiles`

```php
use EduLazaro\Laracrate\Concerns\HasFiles;

class Property extends Model
{
    use HasFiles;

    // Override per-modelo (opcional). Mergea con la collection global.
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

### Subir archivo via server (request normal)

```php
$property->addFile($request->file('image'), 'gallery', [
    'title' => 'Fachada principal',
    'label' => 'facade',
]);
```

### Subir directo a R2 (presigned, recomendado)

JS cliente:

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

Backend del confirm:

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

### Multipart (archivos grandes, mayores a 100 MB)

El JS helper detecta el tamaño y usa multipart automáticamente si pasa el `threshold`. Backend ya cubierto por las rutas del paquete.

### Mostrar en blade

```blade
{{-- URL del File --}}
<img src="{{ $property->file('gallery')?->variant('medium')->url() }}">

{{-- Variant con dot notation --}}
<img src="{{ $videoFile->variant('preview.thumbnail')->url('image') }}">

{{-- Helpers con fallback automático al placeholder --}}
<img src="{{ $user->fileLink('avatar', 'medium') }}">

{{-- Render con componente blade configurable --}}
{{ $user->fileRender('avatar', 'medium', ['class' => 'w-12 h-12 rounded-full']) }}

{{-- Link directo al stream (collections con access=stream) --}}
<a href="{{ $file->link }}">Descargar</a>
<img src="{{ $file->preview_link }}">
```

`$file->variant('preview.thumbnail')` navega con dot notation y **cae al ancestro real** si la cadena se rompe (nunca devuelve null). Si necesitas fallar fuerte, usa `variantOrFail()`.

### Helper `fileLink()` y `fileRender()`

Eliminan el boilerplate de null-checks:

```php
$user->fileLink('avatar')                          // URL o placeholder configurado
$user->fileLink('avatar', 'medium')                // variant medium
$user->fileLink('cover', 'preview.thumbnail')      // dot notation
$user->fileLink('cover', 'preview.small', 'image') // forzar tipo

$user->fileRender('avatar', 'medium', ['class' => 'w-12 h-12'])
// produce <x-{component} :model="$user" :url="..." class="w-12 h-12" />
```

`fileLink()` devuelve `string|null`. `fileRender()` devuelve `HtmlString`.

#### Componente blade default per-collection

```php
'collections' => [
    'avatar' => [
        'component' => 'user-avatar',
        // ...
    ],
],
```

Componente en la app:

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

### Borrar, reordenar, publicar

```php
$property->deleteFile($file);
$property->reorderFiles('gallery', $request->input('ids'));
$file->makeDefault();
$file->publish();
$file->unpublish();
```

### Policies (autorización)

```php
use EduLazaro\Laracrate\Support\PolicyRegistry;

// AppServiceProvider::boot()
app(PolicyRegistry::class)
    ->viewable('property',   fn ($file, $user) => $user && $file->fileable->isOwnedBy($user))
    ->editable('property',   fn ($file, $user) => $user && $file->fileable->canEdit($user))
    ->deletable('property',  fn ($file, $user) => $user && $file->fileable->canEdit($user));
```

Defaults sin policy registrada:

- El **creador humano** del File siempre puede ver, editar y borrar.
- Files con `access='public'` siempre pueden ver.
- Resto: deny.

## Pipeline de procesamiento

Al crear un File top-level (sin `parent_id`), `FileObserver::created` dispatcha `ProcessFileJob` (queue). El job orquesta `ProcessFileAction`, que itera los **Steps** del `ProcessingPipelineRegistry` en orden de prioridad ascendente.

Steps por defecto del paquete:

| Priority | Step | Cuándo aplica |
|---:|---|---|
| 10 | `ExtractImageDimensions` | type === image |
| 10 | `ExtractVideoDimensions` | type === video (requiere ffprobe) |
| 20 | `OptimizeImage` | type === image y collection.optimize_originals === true |
| 25 | `TranscodeVideo` | type === video y collection.types.video.transcode === true |
| 40 | `GenerateImageVariants` | type === image y hay `variants` config |
| 45 | `ExtractVideoPreview` | type === video y hay `preview` config |
| 45 | `ExtractPdfPreview` | type === document y mime === application/pdf |
| 60 | `ExtractText` | extract_text o embed, y hay TextExtractor para el mime |
| 70 | `ChunkText` | embed === true y hay texto extraído |
| 80 | `GenerateEmbedding` | embeddings.enabled, embed === true, y hay chunks |

Convención de prioridades:

- 0 a 19: metadata (dimensions, duration).
- 20 a 39: transformación del original (optimize, transcode, encrypt).
- 40 a 59: derivados (variants, previews, thumbnails).
- 60 a 79: extracción semántica (texto, OCR, transcripción).
- 80 a 99: IA (chunking, embeddings, classification).

Eventos:

- `FileProcessingStarted`, antes del primer step.
- `FileProcessed`, todos los steps completados OK.
- `FileProcessingFailed`, un step lanzó.
- `VariantGenerated`, al crear un variant.
- `EmbeddingsReady`, al generar embeddings.

Política fail-fast: si un step lanza, el File queda en `processing_status = FAILED` y `ProcessFileJob` reintenta con backoff (3 tries: 10s, 30s, 60s). Steps posteriores no se ejecutan en ese intento.

Si el File se borra antes de que el worker llegue al job (típico cuando `setFile()` reemplaza un avatar), Laravel descarta el job en silencio gracias a `$deleteWhenMissingModels = true`. Cero entradas zombi en `failed_jobs`.

### Extender el pipeline desde la app

```php
// AppServiceProvider::boot()
$registry = app(\EduLazaro\Laracrate\Support\ProcessingPipelineRegistry::class);

// Añadir un step propio
$registry->add(new \App\Files\Pipeline\VirusScanStep());

// Quitar uno default
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

Los variants son rows hijos de `laracrate_files` con `parent_id` y `variant`. La FK con cascade los borra cuando borras el padre. El `FileObserver` borra el binario en R2 al borrar la fila (force delete).

### Convención de paths

- `path` del original: `{fileable_type}/{fileable_id}/{collection}/{ulid_filename.ext}`.
- `path` de un variant: `{parentDir}/variants/{baseName}_{variantName}.{ext}`.
- `path` de un sibling (ejemplo, transcoded `mp4` que reemplaza al `mov`): `{parentDir}/{newName}.{ext}`.

Helpers en el modelo `File`:

```php
$file->key                                  // ltrim($file->path, '/'), la key entera
$file->variantKey($newName)                 // construye key para un variant (subdir variants/)
$file->siblingKey($newName)                 // construye key para un sibling (mismo dir)
$file->createVariant($name, $overrides)     // crea fila variant heredando scope del padre
```

### Watermark per-variant

El watermark se incrusta en el binario del variant **al generarlo**. El original siempre queda limpio. Si cambias la PNG o el texto mañana, regeneras variants y listo.

Ver bloque `watermark` en config.

## Modos de upload

| Modo | Cuándo usar | Pros | Contras |
|---|---|---|---|
| **Via server** (`addFile($uploadedFile)`) | archivos pequeños, validación estricta server-side | encrypt en reposo posible, validación PHP | binario pasa por PHP |
| **Presigned directo** (PUT a R2) | flujo normal | sin PHP en el flujo, escala bien | sin encrypt en reposo |
| **Multipart** (mayor a 100 MB) | vídeos grandes, datasets | partes paralelizables, reanudable | más complejidad cliente |

El presign acepta `fileable_type`, `fileable_id` y `collection` para generar la **key canónica directa**. Si no se conoce el modelo al subir, el binario va a `temp/` y `CreateFileAction` lo mueve con `copyObject` server-side de S3 (cero descarga al PHP).

## Endpoints HTTP

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/laracrate/uploads/presign` | Genera presigned URL (single PUT) |
| DELETE | `/laracrate/uploads/{disk}/{encodedKey}` | Cancela upload `temp/` |
| POST | `/laracrate/multipart/init` | Inicia multipart upload |
| POST | `/laracrate/multipart/{id}/parts` | Re-emite presigned URLs de partes |
| POST | `/laracrate/multipart/{id}/complete` | Ensambla partes y registra el File |
| DELETE | `/laracrate/multipart/{id}` | Aborta sesión multipart |
| GET | `/files/{slug}/stream` | Stream con audit (collections `access=stream`) |
| GET | `/files/{slug}/preview` | Stream sin incrementar `last_downloaded_at` |
| GET | `/files/{slug}/download` | Forzar descarga (Content-Disposition: attachment) |
| GET | `/laracrate/files/{slug}/status` | Estado del File para polling tras upload async |
| POST | `/laracrate/files/status` | Estado batch de varios slugs |
| POST | `/_laracrate/local/upload` | Upload del local driver (ruta firmada Laravel) |
| GET | `/_laracrate/local/serve/{slug}` | Sirve File del local disk |

## Sensitive content

Para collections con `access=stream`, flujo por request:

1. URL del paquete firmada con Laravel (TTL `route_signed_ttl`).
2. Controller valida la firma.
3. Si `sensitive=true`, valida `Auth::id() === query('u')` (URL bind).
4. Policy chain, `FilePolicy::view($file, $user)` lee `PolicyRegistry`.
5. Si `is_encrypted=true`, `DecryptFileAction` desencripta antes de servir.
6. Audit, incrementa `last_downloaded_at`, opcional log con IP y user_id.

El watermark **no se aplica aquí**, está horneado en el variant.

## Comandos artisan

```bash
# Aborta sesiones multipart colgadas (programable hourly)
php artisan laracrate:abort-stale-multipart

# Borra Files con TTL expirado y sus binarios (programable hourly)
php artisan laracrate:purge-expired
```

Programación recomendada en `app/Console/Kernel.php`:

```php
$schedule->command('laracrate:abort-stale-multipart')->hourly();
$schedule->command('laracrate:purge-expired')->hourly();
```

## Componente Livewire opcional

El paquete incluye un componente Livewire `LaracrateUploader` listo para usar como uploader visual de cualquier collection. Es **totalmente opcional**: el core del paquete funciona sin Livewire, y la app puede escribir su propio uploader o consumir directamente `addFile()`/`setFile()` desde sus formularios.

```blade
<livewire:laracrate-uploader :model="$user" collection="avatar" />
<livewire:laracrate-uploader :model="$user" collection="avatar" theme="ios" layout="portrait" />
```

Soporta 8 themes (`default`, `brutalist`, `material`, `ios`, `glassmorphism`, `neon`, `minimal`, `neumorphism`) y 2 layouts (`row`, `portrait`). El theme global se configura en `config('laracrate.ui.default_theme')`.

Para customizar las vistas:

```bash
php artisan vendor:publish --tag=laracrate-views
```

Si no usas Livewire, simplemente ignora esta sección. Los themes y el componente no se cargan a menos que los renderices.

## API completa

### Trait `HasFiles`

```php
$model->files(?$collection = null)              // MorphMany (top-level only)
$model->file($collection)                       // primer file ordenado por default → latest
$model->images($collection)                     // shortcut de files($collection)->where(type, image)
$model->getFile($collection)                    // primer file (alias)
$model->defaultFile($collection)                // file con default=true

$model->addFile($upload, $collection, $metadata = [])
$model->setFile($collection, $upload, $metadata = [])  // single, reemplaza el existente
$model->deleteFile($file, $forceDelete = false)
$model->reorderFiles($collection, $orderedIds)
$model->setDefaultFile($file)

$model->fileLink($collection, $variant = null, $forceType = null): ?string
$model->fileRender($collection, $variant = null, $attrs = []): HtmlString

$model->getCollectionConfig($collection): array
$model->getDiskFor($collection): string
$model->resolveFileTenant(): ?Model
```

### Modelo `File`

```php
// Relaciones
$file->parent
$file->children
$file->fileable
$file->creator
$file->tenant
$file->contents                                  // chunks de laracrate_file_contents

// Variants
$file->variant('preview.thumbnail')              // dot notation, fallback al ancestro real
$file->variantOrFail('preview.thumbnail')        // lanza si la cadena se rompe

// URLs
$file->url($forceType = null)                    // URL real o placeholder
$file->link                                      // accessor: alias de url()
$file->preview_link                              // accessor: variant('preview.thumbnail')->url('image')
$file->streamUrl()
$file->downloadUrl()
$file->placeholderFor('image')

// Storage
$file->key                                       // accessor: ltrim(path, '/')
$file->variantKey($newName)
$file->siblingKey($newName)
$file->createVariant($variantName, $overrides)

// Estado
$file->makeDefault()
$file->publish() / unpublish()
$file->isVariant() / isTopLevel() / isSensitive()
$file->isImage() / isVideo() / isAudio() / isDocument()
$file->createdByUser() / createdByAgent() / createdAutomatically()

// Texto extraído (si embed)
$file->extractedText(): ?string                  // une todos los chunks
$file->hasEmbeddings(): bool

// Authorization (delega a PolicyRegistry)
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

### Servicio `StorageManager`

```php
$manager = app(\EduLazaro\Laracrate\Services\StorageManager::class);

$manager->urlFor($file)                                       // delega en GeneratePublicUrl/Signed/Stream
$manager->diskFor($file)                                      // Storage::disk del File
$manager->readBinary($file)                                   // contenido binario completo
$manager->writeBinary($disk, $key, $content, $mime)
$manager->deleteFromBackend($disk, $key)
$manager->moveServerSide($disk, $fromKey, $toKey)             // S3 copyObject
$manager->batchDelete($disk, $keys)                           // hasta 1000 keys/request
$manager->presignedUpload($disk, $key, $mime, $maxSize, $minutes = 15)
$manager->withLocalCopy($file, $callback)                     // descarga temporal segura

$manager->getCollectionConfig($collection): array
$manager->getTypeConfig($collection, $type): array
$manager->acceptsType($collection, $type): bool
$manager->driverOf($disk): string
$manager->s3ClientOf($disk): ?S3Client
```

## Tests

`Storage::fake()` y SQLite in-memory, sin Docker ni servicios externos:

```bash
cd packages/edulazaro/laracrate
composer install
vendor/bin/phpunit
```

Tests cubren modelo, trait, observer, manager, policies, presigned controller, stream controller, multipart, embeddings.

## Dependencias

- Laravel 11+ y PHP 8.2+
- `intervention/image` (procesado de imágenes, watermark)
- `aws/aws-sdk-php` (presign y multipart S3/R2)
- `edulazaro/laractions` (clase base de Actions)
- `smalot/pdfparser` (opcional, para `PdfTextExtractor`)
- `imagick` extension PHP (recomendado) o `gd`
- `ffmpeg`, `ffprobe` en path (solo si usas vídeo)

## License

MIT
