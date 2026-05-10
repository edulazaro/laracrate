<?php

namespace EduLazaro\Laracrate\Support;

/**
 * Snapshot inmutable del uso de storage de un scope (tenant, colección,
 * creator o global). Devuelto por `UsageReporter`.
 *
 * Los bytes incluyen variants (thumbnails, previews, transcoded). Si la
 * app quiere distinguir originales vs derivados, usa los desgloses
 * `byType` o consulta `topLevel()` directamente.
 */
class UsageStats
{
    public function __construct(
        public readonly int $totalBytes = 0,
        public readonly int $totalFiles = 0,
        /** @var array<string, array{bytes:int, files:int}> */
        public readonly array $byCollection = [],
        /** @var array<string, array{bytes:int, files:int}> */
        public readonly array $byType = [],
    ) {}

    public function kilobytes(): float
    {
        return $this->totalBytes / 1024;
    }

    public function megabytes(): float
    {
        return $this->totalBytes / 1024 / 1024;
    }

    public function gigabytes(): float
    {
        return $this->totalBytes / 1024 / 1024 / 1024;
    }

    /**
     * "1.42 GB", "234 MB", "12 KB".
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
     * ¿Excede la cuota dada (en bytes)?
     */
    public function exceeds(int $quotaBytes): bool
    {
        return $this->totalBytes > $quotaBytes;
    }

    /**
     * Bytes restantes hasta la cuota. Negativo si ya excedida.
     */
    public function remaining(int $quotaBytes): int
    {
        return $quotaBytes - $this->totalBytes;
    }

    /**
     * Porcentaje usado de la cuota (0-100+, puede pasar de 100 si excede).
     */
    public function percentageOf(int $quotaBytes): float
    {
        if ($quotaBytes <= 0) return 0.0;
        return ($this->totalBytes / $quotaBytes) * 100;
    }

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
