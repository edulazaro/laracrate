<?php

namespace EduLazaro\Laracrate\Support;

/**
 * Value object for uploading binary content generated server-side.
 *
 * Use case: PDFs generated with mPDF, images generated with GD, in-memory
 * exports, etc. The caller passes the raw content + mime + logical name;
 * the package decides the canonical path, validates and uploads.
 *
 *   $case->addFile(new Binary(
 *       content: $pdfContent,
 *       mimeType: 'application/pdf',
 *       originalName: 'Contrato_firmado.pdf',
 *   ), 'documents', data: [...]);
 *
 * Differences from UploadedFile:
 *   - UploadedFile = HTTP upload, file in `php://input` or local tmp.
 *   - Binary       = content in memory, no intermediate file.
 *
 * Differences from FileUpload (presigned):
 *   - FileUpload = the client already uploaded to `temp/X` on R2; the package moves it.
 *   - Binary     = the server holds the bytes; the package writes to the final disk.
 */
class Binary
{
    /** Create a binary value object from raw server-side content. */
    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly string $originalName,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $duration = null,
    ) {}

    /** Size of the content in bytes. */
    public function size(): int
    {
        return strlen($this->content);
    }

    /** Lowercased file extension derived from the original name. */
    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION) ?: 'bin');
    }
}
