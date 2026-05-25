<?php

namespace EduLazaro\Laracrate\Chunks;

use EduLazaro\Laracrate\Contracts\ChunkStore;
use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Driver default: persiste chunks (texto + embedding + metadata) en
 * `laracrate_file_chunks` y busca con keyword LIKE + cosine similarity
 * en PHP. Sin dependencias externas.
 */
class MysqlChunkStore implements ChunkStore
{
    private const SEMANTIC_CANDIDATE_POOL = 500;
    private const KEYWORD_SCORE = 1.0;

    public function driverName(): string
    {
        return 'mysql';
    }

    public function store(File $file, array $chunks): int
    {
        // Idempotente: borra chunks viejos y reescribe. Mantenemos los
        // chunk_index del JSONL (estables, no auto-increment).
        FileChunk::where('file_id', $file->id)->delete();

        $count = 0;
        foreach ($chunks as $c) {
            FileChunk::create([
                'file_id'     => $file->id,
                'chunk_index' => (int) ($c['chunk_index'] ?? $count),
                'context'     => $c['context'] ?? null,
                'text'        => (string) ($c['text'] ?? ''),
                'embedding'   => isset($c['embedding']) && is_array($c['embedding']) ? $c['embedding'] : null,
                'tokens'      => (int) ($c['tokens'] ?? 0),
                'metadata'    => is_array($c['metadata'] ?? null) ? $c['metadata'] : [],
            ]);
            $count++;
        }

        $file->forceFill(['mysql_indexed_at' => now()])->save();

        return $count;
    }

    public function getByFile(File $file): Collection
    {
        return FileChunk::where('file_id', $file->id)
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'context', 'text', 'tokens', 'metadata'])
            ->map(fn (FileChunk $c) => [
                'chunk_index' => $c->chunk_index,
                'context'     => $c->context,
                'text'        => (string) $c->text,
                'tokens'      => (int) ($c->tokens ?? 0),
                'metadata'    => is_array($c->metadata) ? $c->metadata : [],
            ]);
    }

    public function deleteByFile(File $file): void
    {
        FileChunk::where('file_id', $file->id)->delete();
    }

    public function search(string $query, array $filters = [], array $options = []): Collection
    {
        $limit         = max(1, (int) ($options['limit'] ?? 10));
        $semanticRatio = (float) ($options['semantic_ratio'] ?? 0.7);

        $base = FileChunk::query();

        if (! empty($filters['file_ids'])) {
            $base->whereIn('file_id', (array) $filters['file_ids']);
        }

        $needsFileJoin = array_intersect(
            array_keys($filters),
            ['fileable_type', 'fileable_id', 'tenant_type', 'tenant_id', 'collection', 'context', 'category'],
        );

        if ($needsFileJoin) {
            $base->whereExists(function ($q) use ($filters) {
                $q->selectRaw('1')
                  ->from('laracrate_files')
                  ->whereColumn('laracrate_files.id', 'laracrate_file_chunks.file_id');

                foreach (['fileable_type', 'fileable_id', 'tenant_type', 'tenant_id', 'collection', 'category'] as $col) {
                    if (isset($filters[$col])) {
                        $q->where("laracrate_files.{$col}", $filters[$col]);
                    }
                }
            });

            if (isset($filters['context'])) {
                $base->where('laracrate_file_chunks.context', $filters['context']);
            }
        }

        // ===== Keyword =====
        $escaped = addcslashes($query, '\\%_');
        $keywordHits = (clone $base)
            ->where('text', 'like', '%' . $escaped . '%')
            ->limit(self::SEMANTIC_CANDIDATE_POOL)
            ->get(['id', 'file_id', 'chunk_index', 'text', 'metadata']);

        $scored = [];
        foreach ($keywordHits as $c) {
            $scored[$c->id] = [
                'chunk'   => $c,
                'score'   => self::KEYWORD_SCORE,
                'matched' => 'keyword',
            ];
        }

        // ===== Semántica =====
        if ($semanticRatio > 0) {
            $queryEmbedding = $this->embedQuery($query);

            if ($queryEmbedding !== null) {
                $candidates = (clone $base)
                    ->whereNotNull('embedding')
                    ->limit(self::SEMANTIC_CANDIDATE_POOL)
                    ->get(['id', 'file_id', 'chunk_index', 'text', 'embedding', 'metadata']);

                foreach ($candidates as $c) {
                    if (! is_array($c->embedding) || empty($c->embedding)) continue;

                    $sim = $this->cosineSimilarity($queryEmbedding, $c->embedding);

                    if (isset($scored[$c->id])) {
                        $scored[$c->id]['score']   = self::KEYWORD_SCORE + ($sim * $semanticRatio);
                        $scored[$c->id]['matched'] = 'hybrid';
                    } else {
                        $scored[$c->id] = [
                            'chunk'   => $c,
                            'score'   => $sim * $semanticRatio,
                            'matched' => 'semantic',
                        ];
                    }
                }
            }
        }

        if (empty($scored)) {
            return collect();
        }

        uasort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return collect(array_slice($scored, 0, $limit, preserve_keys: true))
            ->values()
            ->map(function (array $entry) {
                /** @var FileChunk $c */
                $c = $entry['chunk'];

                return [
                    'file_id'     => $c->file_id,
                    'chunk_index' => $c->chunk_index,
                    'text'        => (string) $c->text,
                    'score'       => round((float) $entry['score'], 4),
                    'matched'     => $entry['matched'],
                    'metadata'    => is_array($c->metadata) ? $c->metadata : [],
                ];
            });
    }

    protected function embedQuery(string $query): ?array
    {
        try {
            $provider = app(EmbeddingProvider::class);
            $vectors = $provider->embed([$query]);
            return $vectors[0] ?? null;
        } catch (\Throwable $e) {
            Log::warning('MysqlChunkStore: query embedding failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) return 0.0;

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0, $n = count($a); $i < $n; $i++) {
            $dot   += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $den = sqrt($normA) * sqrt($normB);
        return $den > 0 ? $dot / $den : 0.0;
    }
}
