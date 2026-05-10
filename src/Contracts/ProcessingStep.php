<?php

namespace EduLazaro\Laracrate\Contracts;

use EduLazaro\Laracrate\Models\File;

/**
 * Paso del pipeline de procesamiento de un File.
 *
 * Cada step es una pieza independiente que decide si aplica al File dado
 * (`supports`) y qué hacer (`handle`). El registry los ejecuta ordenados
 * por `priority` ascendente. Convención de prioridades:
 *
 *   0-19   : metadata (dimensions, duration)
 *   20-39  : transformación del original (optimize, transcode, encrypt)
 *   40-59  : derivados (variants, previews, thumbnails)
 *   60-79  : extracción semántica (texto, OCR, transcripción)
 *   80-99  : IA (chunking, embeddings, classification)
 *
 * Las apps registran sus propios steps en el ServiceProvider:
 *
 *   app(ProcessingPipelineRegistry::class)->add(new MyVirusScanStep());
 */
interface ProcessingStep
{
    /**
     * ¿Aplica este step al File? Se evalúa en tiempo de ejecución, justo
     * antes de invocar `handle`, así que puede inspeccionar estado
     * producido por steps anteriores (file_contents, variants, etc.).
     */
    public function supports(File $file): bool;

    /**
     * Ejecuta el step. Puede asumir que `supports($file)` devolvió true.
     * Lanza excepción si falla — el orquestador decide la política.
     */
    public function handle(File $file): void;

    /**
     * Prioridad ascendente. Steps con menor número corren primero.
     */
    public function priority(): int;
}
