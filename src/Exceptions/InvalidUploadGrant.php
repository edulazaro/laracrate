<?php

namespace EduLazaro\Laracrate\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** An upload grant that is unreadable, expired, or issued to another user. */
class InvalidUploadGrant extends HttpException
{
    public function __construct(string $message = 'Invalid upload grant.')
    {
        parent::__construct(403, $message);
    }
}
