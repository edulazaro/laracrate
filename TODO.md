# Laracrate — TODO

Backlog de mejoras y features discutidas pero no implementadas todavía. Ordenado por valor estimado / esfuerzo.

## Componentes Livewire

### `<livewire:laracrate-deferred-uploader>`

Variante del `LaracrateUploader` actual con commit diferido. El upload a R2 (y la creación de la fila en `laracrate_files`) NO ocurre al seleccionar el archivo, sino al pulsar un botón explícito de "Confirmar". Patrón útil para:

- Avatar con crop UI antes de subir.
- Modales tipo "elige tu foto" donde la confirmación es parte de la UX.
- Forms multi-paso donde la imagen es opcional hasta el submit final.
- Reduce uploads inútiles a R2 si el user cancela.

**Diferencia con el `LaracrateUploader` actual**:
- El actual es **inmediato**: en `updatedUpload()` ya llama a `setFile()`, sube a R2 y dispatcha el `ProcessFileJob`. Sin confirmación.
- El nuevo es **diferido**: `updatedUpload()` solo valida y guarda en un buffer interno `$pendingUpload`. La persistencia se desacopla a un método `confirm()` invocado por click. Hay un `discard()` para cancelar.

**Tres estados visuales** que cada theme/layout debe pintar:

1. **idle** — dropzone vacío. Igual que el componente actual.
2. **pending** — preview del archivo en tmp local + botones "Confirmar" / "Descartar".
3. **committed** — file ya en R2 con su file-card. Igual al estado "with file" del componente actual.

**Preview del pending state**:
- Usar `$pendingUpload->temporaryUrl()` de Livewire para mostrar el preview desde el tmp local. Solo funciona para imágenes (Livewire restringe).
- Para archivos no-imagen, mostrar icon + nombre + tamaño.
- Opcional v2: hybrid con Blob URL del navegador (`URL.createObjectURL`) para preview instantáneo antes incluso de que termine el upload a tmp.

**Plan de implementación**:

1. **Componente**: `packages/.../src/Http/Livewire/LaracrateDeferredUploader.php`.
   - Mismas props que `LaracrateUploader` (`model`, `collection`, `variant`, `theme`, `layout`).
   - Prop nueva: `public $pendingUpload = null` (el `TemporaryUploadedFile` en buffer).
   - `updatedUpload()`: valida y mueve a `$pendingUpload`. NO llama a `setFile()`.
   - Método `confirm()`: ejecuta `$model->setFile($collection, $pendingUpload)` y resetea el buffer.
   - Método `discard()`: `$this->reset('pendingUpload', 'upload')`. Livewire libera el tmp con su cron.

2. **Vistas**: `packages/.../resources/views/uploader-deferred/themes/{theme}/{layout}.blade.php`.
   - 8 themes (default, brutalist, material, ios, glassmorphism, neon, minimal, neumorphism) × 2 layouts (row, portrait) = **16 vistas**.
   - Mismo contrato de variables que el componente actual + `$pendingUpload` y `$pendingPreviewUrl`.
   - Bloque adicional para el estado **pending** con dos botones (confirm primario + discard secundario).

3. **Registro** en `LaracrateServiceProvider`:
   ```php
   Livewire::component('laracrate-deferred-uploader', LaracrateDeferredUploader::class);
   ```

4. **README** actualizado con la sección "Optional Livewire component" añadiendo el patrón deferred y cuándo usarlo vs el inmediato.

**Reutilización**: la mayoría del HTML/CSS de las 16 vistas del deferred es idéntico al del immediate (drop-zone, file-card, buttons). Para DRY, extraer partials a `resources/views/uploader/_partials/{dropzone,file-card,replace-buttons,confirm-buttons}.blade.php` y `@include` desde ambas familias.

**Coste estimado**: ~80 líneas PHP + 16 vistas × ~60 líneas ≈ 1100 líneas nuevas, mucho menos si se extraen partials compartidos con el immediate.

### `<livewire:laracrate-library-uploader>`

Variante para library mode (múltiples files en la misma collection con default toggle, reorder drag-drop, delete por fila). Hoy ese caso (típico de CVs) se construye a mano en cada app. Discutido brevemente como roadmap.

API esperada:
```blade
<livewire:laracrate-library-uploader :model="$user" collection="cv" />
```

Comportamiento:
- Muestra lista de files existentes con badge "default" en el seleccionado.
- Drop zone arriba para añadir nuevo (mismo modal de upload + processing badge).
- Botones por fila: "Marcar default", "Borrar".
- Drag handles para reorder (llama a `$model->reorderFiles($collection, $orderedIds)`).

Consideraciones:
- Compartir themes con el `LaracrateUploader` (cada item de la lista es un mini file-card del theme correspondiente).
- Layout adicional: `list` (filas verticales) ya implícito; `grid` opcional para galerías.

Coste similar al deferred (~80 PHP + n vistas).

### Layout `tile`

Tercer layout además de `row` y `portrait`, ya discutido.

```
┌────────────────┐
│                │
│    [imagen     │
│      fill]     │
│         [↑][🗑]│ ← overlay esquina al hover
└────────────────┘
```

Square compact con controles overlay sobre la imagen al hover. Útil para galerías densas (varios items en grid), miniaturas de chat attachments, listas de empresas.

Coste: 8 themes × 1 layout = 8 vistas nuevas. ~40-60 líneas blade cada una.

### Layout `banner`

Cuarto layout, también discutido.

```
┌──────────────────────────────────────┐
│         [imagen cover wide]          │
│                              [↑] [🗑]│
└──────────────────────────────────────┘
   nombre.jpg · 240 KB
```

Wide & short, ratio 16:9 o 21:9. Para covers/heroes (cabecera de empresa, hero de oferta, OG images custom de blog). Imagen ancha y bajita, controles esquina + meta debajo.

Coste similar a `tile`.

## DX y core del paquete

### Sync mode para dev sin queue worker

El patrón actual "upload + queue → variants + webp" mata la DX en local cuando no tienes worker. El user sube avatar, ve el `.png` original y cree que el paquete está roto.

Propuesta: flag `'queue' => ['sync_in_dev' => true]` en config. Si está activo y `app()->environment('local')`, `FileObserver::created` ejecuta `ProcessFileAction` inline en vez de dispatchar el job.

Alternativa más automática: detectar `config('queue.default') === 'sync'` y ejecutar inline sin necesidad de flag explícita.

Coste: ~10 líneas en FileObserver + nota en README.

### Comando `php artisan laracrate:doctor`

Diagnóstico de la instalación. Verifica:

- Disks declarados en `config/filesystems.php` para cada collection que usa el paquete.
- Conectividad R2 (`Storage::disk($disk)->put('.doctor', 'ok')`).
- ffmpeg/ffprobe en path (si hay collections con video).
- Imagick/GD habilitados.
- Queue worker corriendo (chequeo cron-style).
- Jobs en `failed_jobs` que apuntan a Files de laracrate.
- Filas con paths inconsistentes (`basename(path) !== name`).

Output formateado con check/cross + sugerencias de fix. Lo equivalente a `composer diagnose` para laracrate.

Coste: ~150 líneas comando + tests.

### Self-deletion de tmp huérfanos en `livewire-tmp/`

Livewire ya tiene un cron para limpiar tmp uploads viejos, pero a veces queda residuo cuando el upload a R2 falla a mitad. Comando:

```bash
php artisan laracrate:purge-orphan-tmp
```

Borra ficheros en `storage/app/livewire-tmp/` con mtime < now() - 24h que no estén referenciados por ningún `laracrate_files.path`. Programable hourly junto a `laracrate:abort-stale-multipart` y `laracrate:purge-expired`.

### DX más conciso para `single-collection`

Discutido y descartado: el user prefiere los arrays planos en config/laracrate.php. El builder fluent (`Collection::avatar(...)`) no se implementará.

## Migración / upgrade

### Migración oficial de upgrade `path = directorio` → `path = key entera`

La migración ya está empaquetada en la app (`database/migrations/2026_05_09_120000_backfill_laracrate_files_path_to_full_key.php`). Mover al paquete como migración publishable opcional con tag `laracrate-upgrade-paths`. Apps en upgrade hacen `vendor:publish --tag=laracrate-upgrade-paths` + `migrate`.

### Migración oficial de rename `files` → `laracrate_files`

Misma idea que la anterior. La migración del rename ya está en la app (`2026_05_10_120000_rename_laracrate_tables.php`). Empaquetarla como publishable opcional para apps que vienen de versiones anteriores donde el paquete creaba tablas sin prefijo.

## Documentación

### Section "Migrating from older versions" en README

Cubrir los dos patrones de upgrade (path format + table rename) para que cualquiera que adopte una versión nueva sepa qué hacer en su prod.

### CHANGELOG.md

Mantener un CHANGELOG con cambios breaking entre versiones. Crítico una vez se empiece a publicar a Packagist con tags semver.

### Wiki / docs site

A medida que el paquete crezca, el README quedará pequeño. Considerar Vitepress o similar con secciones por feature.
