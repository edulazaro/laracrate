<?php

namespace EduLazaro\Laracrate\Enums;

/**
 * Visibility scope of a File: who can see it.
 */
enum FileVisibility: string
{
    case OWNER  = 'owner';
    case GROUP  = 'group';
    case TENANT = 'tenant';
    case WORLD  = 'world';
}
