<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileChunk;
use EduLazaro\Laracrate\Support\ExtractedContent;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;

/**
 * Trocea el contenido extraído (storage `{path}.json`) en N chunks según
 * config y persiste:
 *  - Una fila por chunk en `laracrate_file_chunks` con chunk_index, text,
 *    tokens, metadata (page_number, page_numbers). Embedding queda NULL —
 *    lo añade GenerateEmbeddingAction después.
 *  - `{path}.chunks.jsonl` con cada chunk como línea JSON (backup portable).
 *
 * Idempotente: borra filas previas y reescribe el JSONL.
 */
class ChunkTextAction extends Action
{
    public function handle(File $file, ?int $chunkSize = null, ?int $overlap = null): array
    {
        $chunkSize = $chunkSize ?? config('laracrate.embeddings.chunk_size', 1000);
        $overlap   = $overlap   ?? config('laracrate.embeddings.chunk_overlap', 100);

        $jsonPath = $file->path . '.json';
        $disk = Storage::disk($file->disk);

        if (! $disk->exists($jsonPath)) {
            return [];
        }

        $data = json_decode((string) $disk->get($jsonPath), true);
        if (! is_array($data)) {
            return [];
        }

        $extracted = ExtractedContent::fromArray($data);
        if ($extracted->isEmpty()) {
            return [];
        }

        // Si CUALQUIER página declara `context`, troceamos por página y
        // propagamos el context al chunk. Cada sección semántica (OCR text,
        // descripción visual, transcript de audio segmentado, etc.) produce
        // su(s) propio(s) chunk(s) con embedding independiente. Si ninguna
        // página tiene context, comportamiento legacy: concatenamos todas y
        // chunkeamos el texto completo (PDFs, transcripciones lineales).
        $hasContext = collect($extracted->pages)->contains(fn ($p) => !empty($p['context'] ?? null));

        FileChunk::where('file_id', $file->id)->delete();

        $rows = [];
        $jsonlLines = [];
        $globalIndex = 0;

        if ($hasContext) {
            foreach ($extracted->pages as $pageIdx => $page) {
                $text    = trim((string) ($page['text'] ?? ''));
                if ($text === '') continue;

                $context = $page['context'] ?? null;
                $pageNum = (int) ($page['page_number'] ?? ($pageIdx + 1));

                foreach ($this->chunkString($text, $chunkSize, $overlap) as $piece) {
                    [$row, $line] = $this->persistChunk(
                        $file,
                        $globalIndex++,
                        $piece,
                        $context,
                        ['page_number' => $pageNum, 'page_numbers' => [$pageNum]],
                    );
                    $rows[] = $row;
                    $jsonlLines[] = $line;
                }
            }
        } else {
            [$fullText, $charToPage] = $this->buildCharToPageMap($extracted);

            foreach ($this->chunkRanges($fullText, $chunkSize, $overlap) as $chunk) {
                $pageNumbers = $this->pagesForRange($charToPage, $chunk['start'], $chunk['end']);
                $primaryPage = $pageNumbers[0] ?? 1;

                [$row, $line] = $this->persistChunk(
                    $file,
                    $globalIndex++,
                    $chunk['text'],
                    null,
                    ['page_number' => $primaryPage, 'page_numbers' => $pageNumbers],
                );
                $rows[] = $row;
                $jsonlLines[] = $line;
            }
        }

        $disk->put($file->path . '.chunks.jsonl', implode("\n", $jsonlLines));

        return $rows;
    }

    /**
     * Trocea un texto en chunks (sin tracking de posición). Devuelve array
     * de strings ya trimmed. chunkSize=0 → un único chunk con todo el texto.
     *
     * @return array<int,string>
     */
    protected function chunkString(string $text, int $chunkSize, int $overlap): array
    {
        if ($chunkSize <= 0) {
            return [$text];
        }

        $charSize    = max(1, $chunkSize * 4);
        $charOverlap = max(0, min($overlap * 4, $charSize - 1));

        $pieces = [];
        $length = mb_strlen($text);
        $start  = 0;

        while ($start < $length) {
            $end   = min($start + $charSize, $length);
            $piece = trim(mb_substr($text, $start, $end - $start));
            if ($piece !== '') {
                $pieces[] = $piece;
            }
            if ($end >= $length) break;
            $start = $end - $charOverlap;
        }

        return $pieces;
    }

    /**
     * Trocea con tracking de rango (start, end) para el mapeo a páginas.
     *
     * @return array<int,array{text:string,start:int,end:int}>
     */
    protected function chunkRanges(string $text, int $chunkSize, int $overlap): array
    {
        if ($chunkSize <= 0) {
            return [['text' => $text, 'start' => 0, 'end' => mb_strlen($text)]];
        }

        $charSize    = max(1, $chunkSize * 4);
        $charOverlap = max(0, min($overlap * 4, $charSize - 1));

        $chunks = [];
        $length = mb_strlen($text);
        $start  = 0;

        while ($start < $length) {
            $end   = min($start + $charSize, $length);
            $piece = trim(mb_substr($text, $start, $end - $start));
            if ($piece !== '') {
                $chunks[] = ['text' => $piece, 'start' => $start, 'end' => $end];
            }
            if ($end >= $length) break;
            $start = $end - $charOverlap;
        }

        return $chunks;
    }

    /**
     * Persiste una fila de FileChunk y devuelve la fila + su línea JSONL.
     *
     * @return array{0:FileChunk,1:string}
     */
    protected function persistChunk(File $file, int $index, string $text, ?string $context, array $metadata): array
    {
        $tokens = (int) ceil(mb_strlen($text) / 4);

        $row = FileChunk::create([
            'file_id'     => $file->id,
            'chunk_index' => $index,
            'context'     => $context,
            'text'        => $text,
            'tokens'      => $tokens,
            'metadata'    => $metadata,
        ]);

        $line = json_encode(array_merge(
            [
                'chunk_index' => $index,
                'context'     => $context,
                'text'        => $text,
                'tokens'      => $tokens,
            ],
            $metadata,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [$row, $line];
    }

    /** @return array{0:string,1:array<int,int>} */
    protected function buildCharToPageMap(ExtractedContent $extracted): array
    {
        if (empty($extracted->pages)) {
            return [$extracted->fullText, []];
        }

        $separator = "\n\n";
        $sepLen = mb_strlen($separator);
        $parts = [];
        $charToPage = [];
        $offset = 0;

        foreach ($extracted->pages as $i => $page) {
            $text = (string) ($page['text'] ?? '');
            $charToPage[$offset] = (int) ($page['page_number'] ?? ($i + 1));
            $parts[] = $text;
            $offset += mb_strlen($text);
            if ($i < count($extracted->pages) - 1) {
                $offset += $sepLen;
            }
        }

        return [implode($separator, $parts), $charToPage];
    }

    /** @return array<int> */
    protected function pagesForRange(array $charToPage, int $start, int $end): array
    {
        if (empty($charToPage)) {
            return [1];
        }

        $pages = [];
        $boundaries = array_keys($charToPage);
        sort($boundaries);

        foreach ($boundaries as $idx => $boundary) {
            $next = $boundaries[$idx + 1] ?? PHP_INT_MAX;
            if ($boundary < $end && $next > $start) {
                $pages[] = $charToPage[$boundary];
            }
        }

        return array_values(array_unique($pages));
    }
}
