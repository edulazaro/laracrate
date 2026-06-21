<?php

namespace EduLazaro\Laracrate\Contracts;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;

/**
 * Extractor of textual content from a File.
 *
 * Built-in implementations:
 *   - PdfTextExtractor (smalot/pdfparser, per-page with a real page_number)
 *   - PlainTextExtractor (text/*, single-page)
 *   - OcrPdfTextExtractor (Claude/OpenAI with native PDF, single-page)
 *
 * Returns an `ExtractedContent` DTO with full text + pages + metadata.
 */
interface TextExtractor
{
    /**
     * Returns true if this extractor can handle the given File.
     */
    public function supports(File $file): bool;

    /**
     * Extracts the structured content of the file. Throws on failure.
     */
    public function extract(File $file): ExtractedContent;
}
