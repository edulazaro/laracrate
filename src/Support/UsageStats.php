<?php

namespace EduLazaro\Laracrate\Support;

/**
 * Immutable snapshot of the storage usage of a scope (tenant, collection,
 * creator or global). Returned by `UsageReporter`.
 *
 * The bytes include variants (thumbnails, previews, transcoded). If the app
 * wants to distinguish originals vs derivatives, use the `byType` breakdowns
 * or query `topLevel()` directly.
 */
class UsageStats
{
    /** Create an immutable usage snapshot. */
    public function __construct(
        public readonly int $totalBytes = 0,
        public readonly int $totalFiles = 0,
        /** @var array<string, array{bytes:int, files:int}> */
        public readonly array $byCollection = [],
        /** @var array<string, array{bytes:int, files:int}> */
        public readonly array $byType = [],
    ) {}

    /** Total bytes expressed in kilobytes. */
    public function kilobytes(): float
    {
        return $this->totalBytes / 1024;
    }

    /** Total bytes expressed in megabytes. */
    public function megabytes(): float
    {
        return $this->totalBytes / 1024 / 1024;
    }

    /** Total bytes expressed in gigabytes. */
    public function gigabytes(): float
    {
        return $this->totalBytes / 1024 / 1024 / 1024;
    }

    /**
     * Human-readable size, e.g. "1.42 GB", "234 MB", "12 KB".
     */
    public function human(int $precision = 2): string
    {
        $bytes = $this->totalBytes;

        if ($bytes >= 1024 ** 3) return number_format($bytes / 1024 ** 3, $precision) . ' GB';
        if ($bytes >= 1024 ** 2) return number_format($bytes / 1024 ** 2, $precision) . ' MB';
        if ($bytes >= 1024)      return number_format($bytes / 1024,        $precision) . ' KB';

        return $bytes . ' B';
    }

    /**
     * Does usage exceed the given quota (in bytes)?
     */
    public function exceeds(int $quotaBytes): bool
    {
        return $this->totalBytes > $quotaBytes;
    }

    /**
     * Bytes remaining until the quota. Negative if already exceeded.
     */
    public function remaining(int $quotaBytes): int
    {
        return $quotaBytes - $this->totalBytes;
    }

    /**
     * Percentage of the quota used (0-100+, can go over 100 if exceeded).
     */
    public function percentageOf(int $quotaBytes): float
    {
        if ($quotaBytes <= 0) return 0.0;
        return ($this->totalBytes / $quotaBytes) * 100;
    }

    /** Serialize the snapshot to an array. */
    public function toArray(): array
    {
        return [
            'total_bytes'   => $this->totalBytes,
            'total_files'   => $this->totalFiles,
            'by_collection' => $this->byCollection,
            'by_type'       => $this->byType,
        ];
    }
}
