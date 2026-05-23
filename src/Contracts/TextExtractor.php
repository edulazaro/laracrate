<?php

namespace EduLazaro\Laracrate\Contracts;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;

/**
 * Extractor de contenido textual de un File.
 *
 * Implementaciones built-in:
 *   - PdfTextExtractor (smalot/pdfparser, per-page con page_number real)
 *   - PlainTextExtractor (text/*, single-page)
 *   - OcrPdfTextExtractor (Claude/OpenAI con PDF nativo, single-page)
 *
 * Devuelve un DTO `ExtractedContent` con texto completo + páginas + metadata.
 */
interface TextExtractor
{
    /**
     * Devuelve true si este extractor sabe manejar el File dado.
     */
    public function supports(File $file): bool;

    /**
     * Extrae el contenido estructurado del file. Lanza excepción si falla.
     */
    public function extract(File $file): ExtractedContent;
}
