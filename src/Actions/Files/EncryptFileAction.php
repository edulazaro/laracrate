<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts a binary with the app key (Laravel Crypt). Used in
 * CreateFileAction when the collection has encrypt=true, before uploading
 * to the backend.
 */
class EncryptFileAction extends Action
{
    /** Encrypt the given binary content. */
    public function handle(string $binary): string
    {
        return Crypt::encryptString(base64_encode($binary));
    }
}
