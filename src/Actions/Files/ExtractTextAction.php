<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileContent;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Extrae texto plano del File usando el TextExtractorRegistry. Crea o actualiza
 * UNA fila en file_contents con chunk_index=0 y status='extracting' →
 * 'completed' si va bien, 'failed' si peta.
 *
 * Si después corre ChunkTextAction + GenerateEmbeddingAction, esa fila
 * inicial se reemplaza por N filas (una por chunk).
 */
class ExtractTextAction extends Action
{
    public function handle(File $file): ?FileContent
    {
        $registry = app(TextExtractorRegistry::class);
        $extractor = $registry->for($file);

        if (!$extractor) {
            return null;
        }

        $content = FileContent::firstOrNew([
            'file_id'     => $file->id,
            'chunk_index' => 0,
        ]);

        $content->status = 'extracting';
        $content->save();

        try {
            $text = $extractor->extract($file);

            $content->fill([
                'text'   => $text,
                'status' => 'completed',
                'error'  => null,
            ])->save();

            return $content;
        } catch (Throwable $e) {
            $content->fill([
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ])->save();

            logger()->error('Laracrate: ExtractTextAction failed', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
