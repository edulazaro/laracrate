<?php

namespace EduLazaro\Laracrate\Tests\Unit;

use EduLazaro\Laracrate\Enums\FileType;
use PHPUnit\Framework\TestCase;

class FileTypeTest extends TestCase
{
    public function test_maps_image_mimes(): void
    {
        $this->assertSame(FileType::IMAGE, FileType::fromMime('image/jpeg'));
        $this->assertSame(FileType::IMAGE, FileType::fromMime('image/png'));
        $this->assertSame(FileType::IMAGE, FileType::fromMime('image/webp'));
    }

    public function test_maps_video_mimes(): void
    {
        $this->assertSame(FileType::VIDEO, FileType::fromMime('video/mp4'));
        $this->assertSame(FileType::VIDEO, FileType::fromMime('video/quicktime'));
    }

    public function test_maps_audio_mimes(): void
    {
        $this->assertSame(FileType::AUDIO, FileType::fromMime('audio/mpeg'));
        $this->assertSame(FileType::AUDIO, FileType::fromMime('audio/webm'));
    }

    public function test_falls_back_to_document(): void
    {
        $this->assertSame(FileType::DOCUMENT, FileType::fromMime('application/pdf'));
        $this->assertSame(FileType::DOCUMENT, FileType::fromMime('application/octet-stream'));
        $this->assertSame(FileType::DOCUMENT, FileType::fromMime('text/plain'));
    }
}
