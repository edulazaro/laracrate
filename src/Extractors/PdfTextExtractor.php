<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;
use RuntimeException;

/**
 * Text extractor for PDFs using smalot/pdfparser when available.
 *
 * The package does not force the dependency. If the app needs this extraction,
 * it must add `composer require smalot/pdfparser`. If it is not installed,
 * `supports()` returns false and the pipeline skips it cleanly.
 *
 * Extracts per-page (preserving page_number) so apps can cite/display by page.
 */
class PdfTextExtractor implements TextExtractor
{
    /**
     * Determine whether this extractor can handle the given file.
     */
    public function supports(File $file): bool
    {
        if ($file->mime_type !== 'application/pdf') {
            return false;
        }

        return class_exists(\Smalot\PdfParser\Parser::class);
    }

    /**
     * Extract per-page text from the PDF and return the extracted content.
     */
    public function extract(File $file): ExtractedContent
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new RuntimeException(
                'PdfTextExtractor requires smalot/pdfparser. Install it with: composer require smalot/pdfparser'
            );
        }

        $key = $file->key;
        $tmpPath = tempnam(sys_get_temp_dir(), 'laracrate_pdf_');

        try {
            $stream = app(\EduLazaro\Laracrate\Services\StorageManager::class)
                ->diskFor($file)
                ->readStream($key);
            if (!$stream) {
                throw new RuntimeException("Could not read {$key} from disk {$file->disk}");
            }
            file_put_contents($tmpPath, stream_get_contents($stream));
            if (is_resource($stream)) {
                fclose($stream);
            }

            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($tmpPath);

            $pages = [];
            foreach ($pdf->getPages() as $idx => $page) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $page->getText()));
                $pages[] = [
                    'page_number' => $idx + 1,
                    'text'        => $text,
                ];
            }

            return ExtractedContent::fromPages($pages, [
                'extractor'   => static::class,
                'total_pages' => count($pages),
            ]);
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
