<?php

namespace EduLazaro\Laracrate\Contracts;

use EduLazaro\Laracrate\Models\File;

/**
 * Acción del pipeline de procesamiento de un File.
 *
 * Cada acción es una pieza independiente que decide si aplica al File dado
 * (`supports`) y qué hacer (`handle`). El registry las ejecuta ordenadas
 * por `priority` ascendente. Convención de prioridades:
 *
 *   0-19   : metadata (dimensions, duration)
 *   20-39  : transformación del original (optimize, transcode, encrypt)
 *   40-59  : derivados (variants, previews, thumbnails)
 *   60-79  : extracción semántica (texto, OCR, transcripción)
 *   80-99  : IA (chunking, embeddings, classification)
 *   100+   : app-specific post-processing
 *
 * Las apps registran sus propias acciones declarativamente en la collection
 * de `config/laracrate.php`:
 *
 *   'documents' => [
 *       'actions' => [
 *           \App\FileActions\ClassifyDocumentAction::class,
 *       ],
 *   ]
 */
interface FileActionInterface
{
    /**
     * Ejecuta la acción sobre el file. Lanza excepción si falla —
     * el orquestador decide la política.
     */
    public function handle(File $file): void;

    /**
     * Prioridad ascendente. Acciones con menor número corren primero.
     */
    public function priority(): int;

    /*
     * OPCIONAL: cada implementación puede declarar
     *
     *   public function supports(File $file): bool;
     *
     * para gating per-file extra (ej. solo si hay texto extraído, solo si
     * cierto metadata existe, etc.). Si el método no existe, ProcessFileAction
     * asume true y siempre invoca handle().
     *
     * El scope por fileable y por collection se hace declarativamente en
     * `config.collections.*.actions` y `config.collections.*.models.X.actions`,
     * no en supports(). Las variants tampoco llegan aquí — ProcessFileAction
     * las filtra antes (isVariant check).
     */
}
