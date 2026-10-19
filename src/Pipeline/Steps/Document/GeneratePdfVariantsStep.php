<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Document;

use EduLazaro\Laracrate\Actions\Files\Document\RenderPdfPageAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\Variants;

/**
 * Pipeline step that renders the PDF pages the document type declares as image variants:
 *
 *   'document' => ['variants' => [
 *       'portada' => ['type' => 'image', 'page' => 1, 'width' => 2000, 'variants' => ['thumbnail' => ['width' => 300]]],
 *   ]]
 */
class GeneratePdfVariantsStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::DOCUMENT || $file->mime_type !== 'application/pdf') {
            return false;
        }

        return $this->pages($file) !== [];
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 45;
    }

    /** Renders each page variant. */
    public function handle(File $file): void
    {
        foreach ($this->pages($file) as $name => $spec) {
            RenderPdfPageAction::create()->run([
                'file'       => $file,
                'name'       => $name,
                'page'       => max(1, (int) ($spec['page'] ?? 1)) - 1,
                'width'      => $spec['width'] ?? 2000,
                'variants'   => Variants::normalize($spec['variants'] ?? []),
                'engine'     => $spec['engine'] ?? config('laracrate.pdf_preview_engine', 'auto'),
                'resolution' => $spec['resolution'] ?? 150,
            ]);
        }
    }

    /** The document variants that are pages: `'type' => 'image'`. */
    protected function pages(File $file): array
    {
        return array_filter(Variants::of($file, 'document'), fn (array $spec) => ($spec['type'] ?? null) === 'image');
    }
}
