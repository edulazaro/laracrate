<?php

namespace EduLazaro\Laracrate\Enums;

enum FileType: string
{
    case IMAGE    = 'image';
    case VIDEO    = 'video';
    case AUDIO    = 'audio';
    case DOCUMENT = 'document';

    public static function fromMime(string $mime): self
    {
        return match (true) {
            str_starts_with($mime, 'image/') => self::IMAGE,
            str_starts_with($mime, 'video/') => self::VIDEO,
            str_starts_with($mime, 'audio/') => self::AUDIO,
            default                          => self::DOCUMENT,
        };
    }
}
