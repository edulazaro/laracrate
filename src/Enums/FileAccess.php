<?php

namespace EduLazaro\Laracrate\Enums;

/**
 * Who a File is for. How a private one is served is decided where it is used:
 * `url()` gives a signed link straight to the disk, `streamUrl()` serves it through PHP.
 */
enum FileAccess: string
{
    /** Anyone: a permanent public URL. */
    case PUBLIC = 'public';

    /** Only who its view policy lets in. */
    case PRIVATE = 'private';

    /** A collection's or a row's access. 'signed' and 'stream' are the 1.x names of private. */
    public static function fromConfig(?string $access): self
    {
        return $access === 'public' ? self::PUBLIC : self::PRIVATE;
    }
}
