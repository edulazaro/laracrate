<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;

/**
 * Text extractor for plain-text based files (txt, csv, markdown, json, xml, html).
 */
class PlainTextExtractor implements TextExtractor
{
    protected array $supportedMimes = [
        'text/plain',
        'text/csv',
        'text/markdown',
        'application/json',
        'application/xml',
        'text/xml',
        'text/html',
    ];

    /**
     * Determine whether this extractor can handle the given file.
     */
    public function supports(File $file): bool
    {
        if (in_array($file->mime_type, $this->supportedMimes, true)) {
            return true;
        }

        return str_starts_with((string) $file->mime_type, 'text/');
    }

    /**
     * Read the file contents and return them as extracted content.
     */
    public function extract(File $file): ExtractedContent
    {
        $key = $file->key;
        $contents = app(\EduLazaro\Laracrate\Services\StorageManager::class)
            ->diskFor($file)
            ->get($key);

        if ($contents === null) {
            return ExtractedContent::singlePage('', ['extractor' => static::class]);
        }

        $text = mb_convert_encoding($contents, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');

        return ExtractedContent::singlePage($text, [
            'extractor' => static::class,
        ]);
    }
}
