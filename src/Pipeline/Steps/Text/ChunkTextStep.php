<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\ChunkTextAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;

class ChunkTextStep implements FileActionInterface
{
    public function supports(File $file): bool
    {
        if (!config('laracrate.embeddings.enabled', false)) {
            return false;
        }

        if (! ExtractionResolver::shouldEmbed($file)) {
            return false;
        }

        // Solo si ExtractTextStep dejó el sidecar `.json` en storage.
        // El texto vive en storage (canonical) y se duplica en MySQL chunk
        // por chunk durante este step (para keyword search FULLTEXT).
        if (! $file->disk || ! $file->path) {
            return false;
        }

        return \Illuminate\Support\Facades\Storage::disk($file->disk)
            ->exists($file->path . '.json');
    }

    public function priority(): int
    {
        return 70;
    }

    public function handle(File $file): void
    {
        ChunkTextAction::create()->run(['file' => $file]);
    }
}
