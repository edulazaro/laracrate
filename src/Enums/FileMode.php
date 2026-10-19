<?php

namespace EduLazaro\Laracrate\Enums;

/**
 * How an image is shown. Its type stays `image`, so it is processed as any other.
 */
enum FileMode: string
{
    /** A normal image. */
    case DEFAULT = 'default';

    /** A 360° photo: its XMP says GPano:ProjectionType="equirectangular". */
    case SPHERE = 'sphere';

    /** A strip of a video's frames in one image (a `frames` variant). */
    case FRAMES = 'frames';
}
