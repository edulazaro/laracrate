<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Document;

use EduLazaro\Laracrate\Actions\Files\Document\ExtractPdfPreviewAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;

class ExtractPdfPreviewStep implements FileActionInterface
{
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

    public function priority(): int
    {
        return 45;
    }

    public function handle(File $file): void
    {
        $preview = $this->previewConfig($file);

        ExtractPdfPreviewAction::create()->run([
            'file'            => $file,
            'page'            => ($preview['page'] ?? 1) - 1,
            'width'           => $preview['width'] ?? 2000,
            'previewVariants' => $preview['variants'] ?? [],
        ]);
    }

    protected function previewConfig(File $file): array
    {
        $config = app(StorageManager::class)->getTypeConfig($file->collection, 'document', $file->fileable_type);

        return $config['preview'] ?? [];
    }
}
