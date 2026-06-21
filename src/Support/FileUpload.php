<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Models\File;

/**
 * Value object representing a file that is ALREADY present in the backend
 * (uploaded via presigned URL to S3/R2, or by any other mechanism), before
 * persisting the File model.
 *
 * The JS client builds it after completing the direct upload and sends it
 * back to the server so CreateFileAction can materialize it.
 *
 * It only describes the physical binary (disk, key, mime, size, dimensions,
 * digest). For File model attributes (title, description, category,
 * visibility, metadata JSON) use the `$data` parameter of `addFile()`.
 */
class FileUpload
{
    /** Create a value object describing an already-uploaded binary. */
    public function __construct(
        public readonly string $disk,
        public readonly string $key,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $duration = null,
        public readonly ?string $digest = null,
    ) {}

    /** Build a FileUpload from a loosely-keyed array (snake or camel case). */
    public static function fromArray(array $data): self
    {
        return new self(
            disk:         $data['disk'],
            key:          $data['key'],
            originalName: $data['original_name'] ?? $data['originalName'] ?? 'unknown',
            mimeType:     $data['mime_type'] ?? $data['mimeType'] ?? 'application/octet-stream',
            size:         (int) ($data['size'] ?? 0),
            width:        isset($data['width']) ? (int) $data['width'] : null,
            height:       isset($data['height']) ? (int) $data['height'] : null,
            duration:     isset($data['duration']) ? (int) $data['duration'] : null,
            digest:       $data['digest'] ?? null,
        );
    }

    protected ?File $resultingFile = null;

    /** Associate the persisted File model produced from this upload. */
    public function bindTo(File $file): void
    {
        $this->resultingFile = $file;
    }

    /** The File model produced from this upload, if any. */
    public function getFile(): ?File
    {
        return $this->resultingFile;
    }
}
