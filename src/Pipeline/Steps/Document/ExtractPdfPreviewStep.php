<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Document;

use EduLazaro\Laracrate\Actions\Files\Document\ExtractPdfPreviewAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;

/**
 * Pipeline step that generates preview images for PDF documents.
 */
class ExtractPdfPreviewStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::DOCUMENT) {
            return false;
        }

        if ($file->mime_type !== 'application/pdf') {
            return false;
        }

        return !empty($this->previewConfig($file));
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 45;
    }

    /** Runs the PDF preview extraction for the file. */
    public function handle(File $file): void
    {
        $preview = $this->previewConfig($file);

        ExtractPdfPreviewAction::create()->run([
            'file'            => $file,
            'page'            => ($preview['page'] ?? 1) - 1,
            'width'           => $preview['width'] ?? 2000,
            'previewVariants' => $preview['variants'] ?? [],
            'engine'          => $preview['engine'] ?? config('laracrate.pdf_preview_engine', 'auto'),
            'resolution'      => $preview['resolution'] ?? 150,
        ]);
    }

    /** Resolves the preview configuration for the file's collection. */
    protected function previewConfig(File $file): array
    {
        $config = app(StorageManager::class)->getTypeConfig($file->collection, 'document', $file->fileable_type);

        return $config['preview'] ?? [];
    }
}
