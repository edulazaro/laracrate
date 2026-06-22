<?php

namespace EduLazaro\Laracrate\Tests\Support;

use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Models\File;
use RuntimeException;

/**
 * Step de testing que siempre lanza RuntimeException con el mensaje dado.
 */
class ExplodingStep implements FileActionInterface
{
    public function __construct(
        protected string $message,
        protected int $priority = 10,
    ) {}

    public function supports(File $file): bool
    {
        return true;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function handle(File $file): void
    {
        throw new RuntimeException($this->message);
    }
}
