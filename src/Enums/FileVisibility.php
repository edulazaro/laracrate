<?php

namespace EduLazaro\Laracrate\Enums;

enum FileVisibility: string
{
    case OWNER  = 'owner';
    case GROUP  = 'group';
    case TENANT = 'tenant';
    case WORLD  = 'world';
}
