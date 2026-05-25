<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;

/**
 * Trocea el contenido extraído (storage `{path}.json`) en N chunks según
 * config y los persiste como `.chunks.jsonl` (artefacto canónico de la
 * pipeline, portable en R2).
 *
 * NO escribe a BD ni a Meili. El persist al backend final lo hace
 * `PersistChunksAction` al final de la pipeline a través del
 * `ChunkStore` driver activo.
 *
 * Devuelve la lista de chunks generados (en memoria) por si el caller
 * quiere usarlos directo sin re-leer JSONL.
 *
 * @return array<int,array{chunk_index:int,context:?string,text:string,tokens:int,metadata:array}>
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
        // propagamos el context al chunk. Si ninguna lo tiene, concatenamos.
        $hasContext = collect($extracted->pages)->contains(fn ($p) => !empty($p['context'] ?? null));

        $chunks = [];
        $globalIndex = 0;

        if ($hasContext) {
            foreach ($extracted->pages as $pageIdx => $page) {
                $text = trim((string) ($page['text'] ?? ''));
                if ($text === '') continue;

                $context = $page['context'] ?? null;
                $pageNum = (int) ($page['page_number'] ?? ($pageIdx + 1));

                foreach ($this->chunkString($text, $chunkSize, $overlap) as $piece) {
                    $chunks[] = $this->makeChunk(
                        $globalIndex++,
                        $piece,
                        $context,
                        ['page_number' => $pageNum, 'page_numbers' => [$pageNum]],
                    );
                }
            }
        } else {
            [$fullText, $charToPage] = $this->buildCharToPageMap($extracted);

            foreach ($this->chunkRanges($fullText, $chunkSize, $overlap) as $chunk) {
                $pageNumbers = $this->pagesForRange($charToPage, $chunk['start'], $chunk['end']);
                $primaryPage = $pageNumbers[0] ?? 1;

                $chunks[] = $this->makeChunk(
                    $globalIndex++,
                    $chunk['text'],
                    null,
                    ['page_number' => $primaryPage, 'page_numbers' => $pageNumbers],
                );
            }
        }

        // Persistimos el JSONL (canónico). Cada línea es un chunk completo.
        $jsonl = collect($chunks)
            ->map(fn ($c) => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->implode("\n");

        $disk->put($file->path . '.chunks.jsonl', $jsonl);

        return $chunks;
    }

    /**
     * @return array{chunk_index:int,context:?string,text:string,tokens:int,metadata:array}
     */
    protected function makeChunk(int $index, string $text, ?string $context, array $metadata): array
    {
        return [
            'chunk_index' => $index,
            'context'     => $context,
            'text'        => $text,
            'tokens'      => (int) ceil(mb_strlen($text) / 4),
            'metadata'    => $metadata,
        ];
    }

    /**
     * Trocea un texto en chunks. chunkSize=0 → un único chunk.
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
