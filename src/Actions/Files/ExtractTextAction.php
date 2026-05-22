<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileContent;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Extrae texto plano del File iterando la chain de extractors registrados.
 *
 * Itera por orden de prioridad. Si un extractor devuelve texto por debajo
 * del umbral mínimo (`embeddings.min_text_per_file`), prueba con el
 * siguiente extractor (típicamente el OCR caro como fallback para PDFs
 * escaneados que smalot no puede leer).
 *
 * Crea o actualiza UNA fila en file_contents con chunk_index=0 y
 * status='extracting' → 'completed' si va bien, 'failed' si peta o no
 * hay extractor con resultado suficiente.
 *
 * Si después corre ChunkTextAction + GenerateEmbeddingAction, esa fila
 * inicial se reemplaza por N filas (una por chunk).
 */
class ExtractTextAction extends Action
{
    public function handle(File $file): ?FileContent
    {
        $registry = app(TextExtractorRegistry::class);
        $chain = $registry->chainFor($file);

        if (empty($chain)) {
            return null;
        }

        $content = FileContent::firstOrNew([
            'file_id'     => $file->id,
            'chunk_index' => 0,
        ]);

        $content->status = 'extracting';
        $content->save();

        $minText = (int) config('laracrate.embeddings.min_text_per_file', 100);
        $bestText = '';
        $lastError = null;
        $usedExtractor = null;

        foreach ($chain as $extractor) {
            try {
                $text = $extractor->extract($file);
                $textLength = mb_strlen(trim($text));

                if ($textLength >= $minText) {
                    $bestText = $text;
                    $usedExtractor = $extractor;
                    break;
                }

                // Texto por debajo del umbral: lo guardamos como mejor parcial
                // y probamos el siguiente extractor de la chain.
                if ($textLength > mb_strlen(trim($bestText))) {
                    $bestText = $text;
                    $usedExtractor = $extractor;
                }

                logger()->info('Laracrate: extractor returned text below threshold, trying next', [
                    'file_id'    => $file->id,
                    'extractor'  => $extractor::class,
                    'chars'      => $textLength,
                    'min_chars'  => $minText,
                ]);
            } catch (Throwable $e) {
                $lastError = $e;
                logger()->warning('Laracrate: extractor failed, trying next', [
                    'file_id'   => $file->id,
                    'extractor' => $extractor::class,
                    'error'     => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (mb_strlen(trim($bestText)) === 0) {
            $content->fill([
                'status' => 'failed',
                'error'  => $lastError?->getMessage() ?? 'No extractor returned usable text',
            ])->save();

            if ($lastError) {
                throw $lastError;
            }

            return $content;
        }

        $content->fill([
            'text'   => $bestText,
            'status' => 'completed',
            'error'  => null,
            'metadata' => array_merge(
                (array) ($content->metadata ?? []),
                ['extractor' => $usedExtractor ? $usedExtractor::class : null],
            ),
        ])->save();

        return $content;
    }
}
