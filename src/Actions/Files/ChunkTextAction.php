<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileContent;
use EduLazaro\Laractions\Action;

/**
 * Trocea el texto de file_contents en N chunks según config.
 *
 * Estrategia simple por longitud de caracteres aproximando tokens (~4 chars/token).
 * Mantiene overlap entre chunks para no perder contexto en los bordes.
 *
 * Reemplaza la fila chunk_index=0 inicial con N filas (una por chunk),
 * borrando primero las antiguas para hacer la action idempotente.
 */
class ChunkTextAction extends Action
{
    public function handle(File $file, ?int $chunkSize = null, ?int $overlap = null): array
    {
        $chunkSize = $chunkSize ?? config('laracrate.embeddings.chunk_size', 1000);
        $overlap   = $overlap   ?? config('laracrate.embeddings.chunk_overlap', 100);

        $source = FileContent::where('file_id', $file->id)
            ->orderBy('chunk_index')
            ->first();

        if (!$source || $source->text === null || $source->text === '') {
            return [];
        }

        $text = $source->text;

        if ($chunkSize <= 0) {
            return [$source];
        }

        $charSize    = max(1, $chunkSize * 4);
        $charOverlap = max(0, min($overlap * 4, $charSize - 1));

        $chunks = [];
        $length = mb_strlen($text);
        $start  = 0;
        $idx    = 0;

        while ($start < $length) {
            $end   = min($start + $charSize, $length);
            $piece = trim(mb_substr($text, $start, $end - $start));
            if ($piece !== '') {
                $chunks[$idx++] = $piece;
            }
            if ($end >= $length) break;
            $start = $end - $charOverlap;
        }

        FileContent::where('file_id', $file->id)->delete();

        $rows = [];
        foreach ($chunks as $i => $piece) {
            $rows[] = FileContent::create([
                'file_id'     => $file->id,
                'chunk_index' => $i,
                'text'        => $piece,
                'tokens'      => (int) ceil(mb_strlen($piece) / 4),
                'status'      => 'completed',
            ]);
        }

        return $rows;
    }
}
