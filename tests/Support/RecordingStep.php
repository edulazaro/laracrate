<?php

namespace EduLazaro\Laracrate\Tests\Support;

use EduLazaro\Laracrate\Contracts\ProcessingStep;
use EduLazaro\Laracrate\Models\File;

/**
 * Step de testing que registra cada `handle` en un array estático.
 * `$supports` opcional permite filtrar por File en runtime.
 */
class RecordingStep implements ProcessingStep
{
    public static array $calls = [];

    public function __construct(
        public string $name,
        protected int $priority,
        protected $supports = null,
    ) {}

    public function supports(File $file): bool
    {
        if ($this->supports === null) return true;
        return (bool) ($this->supports)($file);
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function handle(File $file): void
    {
        self::$calls[] = $this->name;
    }
}
